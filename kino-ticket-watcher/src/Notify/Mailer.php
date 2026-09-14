<?php
declare(strict_types=1);

namespace Kino\Notify;

/**
 * Schlanker SMTP-Client ueber stream_socket_client.
 *
 * Warum nicht mail()? Ohne SPF/DKIM landet eine ueber mail() verschickte
 * Nachricht zuverlaessig im Spam -- und eine Alarmmail im Spam-Ordner ist
 * genau der stille Ausfall, den dieses Projekt verhindern soll. Deshalb
 * echter SMTP-Versand ueber den eigenen Mailprovider.
 */
final class Mailer
{
    /** @var resource|null */
    private $socket = null;
    private string $transcript = '';

    public function __construct(private readonly array $cfg)
    {
    }

    /**
     * Verschickt eine Mail. Gibt bei Erfolg null zurueck, sonst die
     * Fehlermeldung -- der Rueckgabewert darf nicht ignoriert werden, sonst
     * scheitert der Versand stillschweigend.
     */
    #[\NoDiscard('Ein ignorierter Mailfehler ist ein stiller Ausfall des Watchers.')]
    public function send(string $subject, string $textBody, ?string $htmlBody = null): ?string
    {
        foreach (['host', 'user', 'pass', 'from', 'to'] as $required) {
            if (trim((string)($this->cfg[$required] ?? '')) === '') {
                return "SMTP-Konfiguration unvollstaendig: '$required' fehlt.";
            }
        }

        try {
            $this->connect();
            $this->handshake();
            $this->authenticate();
            $this->envelope();
            $this->data($subject, $textBody, $htmlBody);
            $this->command('QUIT', [221]);
            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        } finally {
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
            $this->socket = null;
        }
    }

    /** Vollstaendiger SMTP-Dialog des letzten Versands -- fuer selftest.php. */
    public function transcript(): string
    {
        return $this->transcript;
    }

    private function connect(): void
    {
        $host = (string)$this->cfg['host'];
        $port = (int)($this->cfg['port'] ?? 587);
        $enc  = strtolower((string)($this->cfg['encryption'] ?? 'tls'));
        $timeout = (int)($this->cfg['timeout'] ?? 20);

        // Port 465 spricht von der ersten Sekunde an TLS, 587 startet im
        // Klartext und schaltet per STARTTLS um.
        $dsn = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;

        $context = stream_context_create(['ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'SNI_enabled'       => true,
            'peer_name'         => $host,
        ]]);

        $socket = @stream_socket_client($dsn, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new \RuntimeException("Verbindung zu $dsn fehlgeschlagen: $errstr ($errno)");
        }
        stream_set_timeout($socket, $timeout);
        $this->socket = $socket;
        $this->expect([220]);
    }

    private function handshake(): void
    {
        $ehlo = $this->cfg['ehlo'] ?? (gethostname() ?: 'localhost');
        $this->command("EHLO $ehlo", [250]);

        if (strtolower((string)($this->cfg['encryption'] ?? 'tls')) === 'tls') {
            $this->command('STARTTLS', [220]);
            $ok = @stream_socket_enable_crypto(
                $this->socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );
            if ($ok !== true) {
                throw new \RuntimeException('STARTTLS fehlgeschlagen (TLS-Handshake abgelehnt).');
            }
            // Nach STARTTLS ist ein erneutes EHLO vorgeschrieben.
            $this->command("EHLO $ehlo", [250]);
        }
    }

    private function authenticate(): void
    {
        $this->command('AUTH LOGIN', [334]);
        $this->command(base64_encode((string)$this->cfg['user']), [334]);
        $this->command(base64_encode((string)$this->cfg['pass']), [235], redact: true);
    }

    private function envelope(): void
    {
        $this->command('MAIL FROM:<' . $this->cfg['from'] . '>', [250]);
        foreach ($this->recipients() as $rcpt) {
            $this->command('RCPT TO:<' . $rcpt . '>', [250, 251]);
        }
    }

    /** @return list<string> */
    private function recipients(): array
    {
        $to = $this->cfg['to'];
        $list = is_array($to) ? $to : (preg_split('/[,;]\s*/', (string)$to) ?: []);
        return array_values(array_filter(array_map('trim', $list), static fn ($v) => $v !== ''));
    }

    private function data(string $subject, string $text, ?string $html): void
    {
        $this->command('DATA', [354]);
        $this->writeLine($this->buildMessage($subject, $text, $html));
        $this->writeLine('.');
        $this->expect([250]);
    }

    private function buildMessage(string $subject, string $text, ?string $html): string
    {
        $boundary = 'b' . bin2hex(random_bytes(12));
        $fromName = (string)($this->cfg['from_name'] ?? '');
        $from = $fromName !== ''
            ? self::encodeHeader($fromName) . ' <' . $this->cfg['from'] . '>'
            : (string)$this->cfg['from'];

        $headers = [
            'Date: ' . date('r'),
            'From: ' . $from,
            'To: ' . implode(', ', $this->recipients()),
            'Subject: ' . self::encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $this->senderDomain() . '>',
            'MIME-Version: 1.0',
            'X-Mailer: KinoTicketWatcher',
            'Auto-Submitted: auto-generated',
        ];

        if ($html === null) {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';
            return implode("\r\n", $headers) . "\r\n\r\n" . self::dotStuff($text);
        }

        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $body = implode("\r\n", [
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            self::dotStuff($text),
            '--' . $boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            self::dotStuff($html),
            '--' . $boundary . '--',
        ]);
        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private function senderDomain(): string
    {
        $at = strrchr((string)$this->cfg['from'], '@');
        return $at === false ? 'localhost' : substr($at, 1);
    }

    /** Umlaute im Betreff muessen nach RFC 2047 kodiert werden. */
    public static function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /**
     * Eine Zeile, die mit einem Punkt beginnt, wuerde den DATA-Block vorzeitig
     * beenden. Der Standard verlangt deshalb einen zweiten Punkt davor.
     */
    private static function dotStuff(string $body): string
    {
        $body = str_replace(["\r\n", "\r", "\n"], "\n", $body);
        $body = str_replace("\n", "\r\n", $body);
        return preg_replace('/^\./m', '..', $body) ?? $body;
    }

    /** @param list<int> $expected */
    private function command(string $command, array $expected, bool $redact = false): string
    {
        $this->transcript .= '> ' . ($redact ? '***' : $command) . "\n";
        $this->writeLine($command);
        return $this->expect($expected);
    }

    private function writeLine(string $line): void
    {
        if (!is_resource($this->socket) || fwrite($this->socket, $line . "\r\n") === false) {
            throw new \RuntimeException('Schreiben auf den SMTP-Socket fehlgeschlagen.');
        }
    }

    /** @param list<int> $expected */
    private function expect(array $expected): string
    {
        $response = '';
        while (is_resource($this->socket)) {
            $line = fgets($this->socket, 8192);
            if ($line === false) {
                $meta = stream_get_meta_data($this->socket);
                throw new \RuntimeException(
                    !empty($meta['timed_out'])
                        ? 'SMTP-Zeitueberschreitung beim Lesen der Antwort.'
                        : 'SMTP-Verbindung unerwartet beendet.'
                );
            }
            $response .= $line;
            // Mehrzeilige Antworten haben einen Bindestrich an Position 4:
            // "250-STARTTLS" geht weiter, "250 OK" ist die letzte Zeile.
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $this->transcript .= '< ' . trim($response) . "\n";
        $code = (int)substr(ltrim($response), 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new \RuntimeException(
                'SMTP-Fehler: erwartet ' . implode('/', $expected) . ', bekommen: ' . trim($response)
            );
        }
        return $response;
    }
}
