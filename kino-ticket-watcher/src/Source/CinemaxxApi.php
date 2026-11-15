<?php
declare(strict_types=1);

namespace Kino\Source;

use Kino\Http\Client;
use Kino\Model\Screening;
use Kino\Model\Status;
use Kino\State\Store;

/**
 * Primaerquelle: die oeffentliche Showings-API von cinemaxx.de.
 *
 *   GET /api/microservice/showings/cinemas/{cinemaId}/films
 *       ?minEmbargoLevel=1&includesSession=true&includeSessionAttributes=true
 *       [&filmId={filmId}]
 *
 * Ohne filmId liefert sie alle Filme des Kinos mit ihren Vorstellungen -- also
 * genau das, was der Watcher braucht, in einem einzigen Request. Keine
 * Authentifizierung, Antwort als JSON, Cache-Control max-age=60 und
 * Last-Modified, sodass bedingte Requests greifen.
 *
 * Entscheidend ist, dass die API die Frage direkt beantwortet, statt sie uns
 * raten zu lassen:
 *   session.isBookingAvailable -> buchbar
 *   session.isSoldOut          -> ausverkauft
 *   film.embargoEndDate        -> Vorverkaufsstart, solange noch gesperrt
 *
 * Deshalb ist diese Quelle dem HTML-Parsen vorzuziehen: Sie ist eindeutig,
 * klein und aendert sich seltener als das Markup.
 */
final class CinemaxxApi
{
    /** @param array<string,string> $headers Header, die die API verlangt (siehe Config) */
    public function __construct(
        private readonly Client $http,
        private readonly string $baseUrl,
        private readonly array $headers = [],
    ) {
    }

    /**
     * Loest {base_url} und {program_url} in den konfigurierten Headern auf,
     * damit die Konfiguration die Domain nicht doppelt fuehren muss.
     *
     * @param array<string,mixed> $headers
     * @return array<string,string>
     */
    public static function resolveHeaders(array $headers, string $baseUrl, string $programUrl): array
    {
        $replacements = [
            '{base_url}'    => rtrim($baseUrl, '/'),
            '{program_url}' => $programUrl,
        ];
        $out = [];
        foreach ($headers as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $out[$name] = strtr($value, $replacements);
            }
        }
        return $out;
    }

    public function sessionsUrl(string $cinemaId, ?string $filmId = null): string
    {
        $query = [
            'minEmbargoLevel'          => '1',
            'includesSession'          => 'true',
            'includeSessionAttributes' => 'true',
        ];
        if ($filmId !== null && $filmId !== '') {
            $query = ['filmId' => $filmId] + $query;
        }
        return rtrim($this->baseUrl, '/')
            . '/api/microservice/showings/cinemas/' . rawurlencode($cinemaId) . '/films'
            . '?' . http_build_query($query);
    }

    /**
     * Fragt die Vorstellungen eines Kinos ab.
     *
     * @return array{screenings:list<Screening>,films:int,error:?string,notModified:bool}
     */
    public function fetch(string $cinemaId, Store $store, int $snapshotsToKeep, ?string $filmId = null): array
    {
        $url = $this->sessionsUrl($cinemaId, $filmId);
        $res = $this->http->get($url, $store->httpCache($url), $this->headers);

        if ($res->notModified) {
            return ['screenings' => [], 'films' => -1, 'error' => null, 'notModified' => true];
        }
        if (!$res->ok()) {
            $hint = $res->status === 401 || $res->status === 403
                ? ' -- der Endpunkt lehnt den Request ab. Bitte bin/probe-api.php laufen lassen,'
                  . ' es ermittelt die noetigen Header.'
                : '';
            return [
                'screenings' => [], 'films' => 0, 'notModified' => false,
                'error' => ($res->error ?? 'Abruf fehlgeschlagen') . $hint,
            ];
        }

        $store->saveSnapshot('api-cinema-' . $cinemaId . ($filmId !== null ? '-' . $filmId : ''), $res->body, $snapshotsToKeep);
        $store->setHttpCache($url, $res->etag, $res->lastModified, null);

        $data = json_decode($res->body, true);
        if (!is_array($data) || !isset($data['result']) || !is_array($data['result'])) {
            return ['screenings' => [], 'films' => 0, 'error' => 'API-Antwort nicht auswertbar (kein result-Array)', 'notModified' => false];
        }
        if (($data['responseCode'] ?? 0) !== 0) {
            return [
                'screenings' => [], 'films' => 0, 'notModified' => false,
                'error' => 'API meldet Fehler: ' . (string)($data['errorMessage'] ?? $data['responseCode']),
            ];
        }

        $screenings = [];
        foreach ($data['result'] as $film) {
            if (is_array($film)) {
                array_push($screenings, ...$this->mapFilm($film));
            }
        }
        return ['screenings' => $screenings, 'films' => count($data['result']), 'error' => null, 'notModified' => false];
    }

    /** @return list<Screening> */
    public function parse(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !is_array($data['result'] ?? null)) {
            return [];
        }
        $out = [];
        foreach ($data['result'] as $film) {
            if (is_array($film)) {
                array_push($out, ...$this->mapFilm($film));
            }
        }
        return $out;
    }

    /** @return list<Screening> */
    private function mapFilm(array $film): array
    {
        $title  = (string)($film['filmTitle'] ?? '');
        $url    = $this->absolute((string)($film['filmUrl'] ?? ''));
        $filmId = $this->nonEmpty($film['filmId'] ?? null);
        // embargoEndDate ist der Zeitpunkt, ab dem gebucht werden kann --
        // die verlaesslichste Vorwarnung, die es gibt.
        $presale = $this->nonEmpty($film['embargoEndDate'] ?? null);

        $sessions = [];
        foreach ($film['showingGroups'] ?? [] as $group) {
            foreach ($group['sessions'] ?? [] as $session) {
                if (is_array($session)) {
                    $sessions[] = $this->mapSession($session, $title, $url, $presale, $filmId);
                }
            }
        }

        if ($sessions !== []) {
            return $sessions;
        }

        // Film gelistet, aber ohne Vorstellung: angekuendigt. Mit Embargo-Datum
        // ist der Termin faktisch schon gesetzt, nur noch nicht buchbar.
        return [new Screening(
            title:           $title,
            url:             $url,
            status:          $presale !== null ? Status::Dated : Status::PageExists,
            startsAt:        $this->nonEmpty($film['releaseDate'] ?? null),
            presaleStartsAt: $presale,
            source:          'api',
            filmId:          $filmId,
        )];
    }

    private function mapSession(
        array $session,
        string $title,
        string $url,
        ?string $presale,
        ?string $filmId,
    ): Screening {
        $soldOut   = (bool)($session['isSoldOut'] ?? false);
        $bookable  = (bool)($session['isBookingAvailable'] ?? false);
        $bookingUrl = $this->nonEmpty($session['bookingUrl'] ?? null);

        $status = match (true) {
            $soldOut  => Status::SoldOut,
            $bookable => Status::Bookable,
            default   => Status::Dated,
        };

        // Saal und Preis gehoeren NICHT in den Titel: Der Titel geht in den
        // Fingerprint ein, und ein umgelegter Saal wuerde dieselbe Vorstellung
        // als neue erscheinen lassen. Sie sind rein informativ.
        $note = implode(', ', array_filter([
            $this->nonEmpty($session['screenName'] ?? null),
            $this->nonEmpty($session['formattedPrice'] ?? null),
        ]));

        return new Screening(
            title:           $title,
            url:             $url,
            status:          $status,
            startsAt:        $this->nonEmpty($session['showTimeWithTimeZone'] ?? null)
                             ?? $this->nonEmpty($session['startTime'] ?? null),
            presaleStartsAt: $presale,
            bookingUrl:      $bookingUrl !== null ? $this->absolute($bookingUrl) : $url,
            source:          'api',
            note:            $note !== '' ? $note : null,
            filmId:          $filmId,
        );
    }

    private function nonEmpty(mixed $v): ?string
    {
        return is_string($v) && trim($v) !== '' ? $v : null;
    }

    private function absolute(string $path): string
    {
        if ($path === '') {
            return rtrim($this->baseUrl, '/');
        }
        return str_starts_with($path, 'http')
            ? $path
            : rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }
}
