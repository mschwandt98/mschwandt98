<?php
declare(strict_types=1);

namespace Kino\Http;

/**
 * HTTP-Abruf mit Retry, Backoff und bedingten Requests.
 *
 * Bedingte Requests (ETag / If-Modified-Since) sind hier nicht nur Hoeflichkeit:
 * In der heissen Phase fragen wir minuetlich an, und ein 304 ohne Body ist fuer
 * beide Seiten billiger als eine vollstaendige Seite.
 */
final class Client
{
    private ?Robots $robots = null;
    private bool $robotsLoaded = false;

    /** @var array<string,int> Zeitpunkt des letzten Requests je Host */
    private array $lastRequestAt = [];

    public function __construct(
        private readonly string $userAgent,
        private readonly int $timeout = 15,
        private readonly int $retries = 2,
        private readonly int $minIntervalPerHost = 2,
        private readonly bool $respectRobots = true,
    ) {
    }

    /**
     * @param array{etag?:?string,last_modified?:?string} $cache
     */
    public function get(string $url, array $cache = []): Response
    {
        if ($this->respectRobots && !$this->allowedByRobots($url)) {
            return new Response(0, '', error: 'durch robots.txt untersagt: ' . $url);
        }
        $this->throttle($url);

        $attempt = 0;
        $lastError = 'unbekannter Fehler';
        while ($attempt <= $this->retries) {
            if ($attempt > 0) {
                // 2s, 4s, 8s -- ein kurzer Ausfall soll den Lauf nicht kosten.
                sleep(2 ** $attempt);
            }
            $attempt++;
            $res = $this->request($url, $cache);
            if ($res->ok()) {
                return $res;
            }
            $lastError = $res->error ?? ('HTTP ' . $res->status);
            // 4xx ausser 429 sind dauerhaft -- ein Retry hilft da nicht.
            if ($res->status >= 400 && $res->status < 500 && $res->status !== 429) {
                return $res;
            }
        }
        return new Response(0, '', error: $lastError . ' (nach ' . $attempt . ' Versuchen)');
    }

    private function request(string $url, array $cache): Response
    {
        $headers = ['Accept: text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
                    'Accept-Language: de-DE,de;q=0.9'];
        if (!empty($cache['etag'])) {
            $headers[] = 'If-None-Match: ' . $cache['etag'];
        }
        if (!empty($cache['last_modified'])) {
            $headers[] = 'If-Modified-Since: ' . $cache['last_modified'];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return new Response(0, '', error: 'curl_init fehlgeschlagen');
        }
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_ENCODING       => '',
            CURLOPT_HEADERFUNCTION => function ($_, string $line) use (&$responseHeaders): int {
                $len = strlen($line);
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($k))] = trim($v);
                }
                return $len;
            },
        ]);
        $body   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $final  = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return new Response($status, '', $responseHeaders, error: $err !== '' ? $err : 'Transferfehler');
        }
        return new Response(
            status:       $status,
            body:         (string)$body,
            headers:      $responseHeaders,
            etag:         $responseHeaders['etag'] ?? null,
            lastModified: $responseHeaders['last-modified'] ?? null,
            error:        $status >= 400 ? 'HTTP ' . $status : null,
            notModified:  $status === 304,
            finalUrl:     $final,
        );
    }

    private function throttle(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST) ?: 'default';
        $last = $this->lastRequestAt[$host] ?? 0;
        $wait = $this->minIntervalPerHost - (time() - $last);
        if ($wait > 0) {
            sleep($wait);
        }
        $this->lastRequestAt[$host] = time();
    }

    private function allowedByRobots(string $url): bool
    {
        if (!$this->robotsLoaded) {
            $this->robotsLoaded = true;
            $base = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);
            // Direkter Abruf ohne Robots-Pruefung, sonst dreht man sich im Kreis.
            $res = (new self($this->userAgent, $this->timeout, 1, $this->minIntervalPerHost, false))
                ->get($base . '/robots.txt');
            $this->robots = $res->ok() && $res->body !== '' ? new Robots($res->body) : null;
        }
        if ($this->robots === null) {
            return true; // keine robots.txt erreichbar -> nicht kuenstlich blockieren
        }
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $query = parse_url($url, PHP_URL_QUERY);
        return $this->robots->allows($path . ($query !== null ? '?' . $query : ''));
    }
}
