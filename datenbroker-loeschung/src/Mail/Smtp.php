<?php
declare(strict_types=1);

namespace Optout\Mail;

/**
 * Schlanker SMTP-Client ueber stream_socket_client -- dieselbe Bauart wie im
 * Kino-Watcher, aber fuer beliebige Empfaenger und mit Thread-Headern.
 *
 * Warum nicht mail()? Ohne SPF/DKIM landet eine ueber mail() verschickte
 * Nachricht im Spam. Ein Loeschantrag im Spamordner des Brokers ist ein
 * Antrag, der nie gestellt wurde -- und die Frist laeuft trotzdem nicht.
 */
final class Smtp implements Sender
{
    /** @var resource|null */
    private $socket = null;
    private string $transcript = '';
    /** @var \Closure|null fn(): resource -- nur fuer Tests */
    private ?\Closure $connector;

    public function __construct(private readonly array $cfg, ?\Closure $connector = null)
    {
        $this->connector = $connector;
    }

    public function send(OutgoingMessage $message): ?string
    {
        foreach (['host', 'user', 'pass', 'from'] as $required) {
            if (trim((string)($this->cfg[$required] ?? '')) === '') {
                return "SMTP-Konfiguration unvollstaendig: '$required' fehlt.";
            }
        }
        $recipients = array_values(array_unique(array_merge($message->to, $message->bcc)));
        if ($recipients === []) {
            return 'Kein Empfaenger.';
        }
        foreach ($recipients as $r) {
            if (!self::validAddress($r)) {
                return "Ungueltige Empfaengeradresse: $r";
            }
        }

        $this->transcript = '';
        try {
            $this->connect();
            $this->handshake();
            $this->authenticate();
            $this->command('MAIL FROM:<' . $this->cfg['from'] . '>', [250]);
            foreach ($recipients as $rcpt) {
                $this->command('RCPT TO:<' . $rcpt . '>', [250, 251]);
            }
            $this->command('DATA', [354]);
            $this->writeLine($this->build($message));
            $this->writeLine('.');
            $this->expect([250]);
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

    public function transcript(): string
    {
        return $this->transcript;
    }

    public static function validAddress(string $address): bool
    {
        // Kein CR/LF, kein Leerzeichen, keine spitzen Klammern: Diese Werte
        // landen in SMTP-Kommandos und Headern.
        return preg_match('/^[^\s<>@,;"]+@[^\s<>@,;"]+\.[^\s<>@,;"]+$/', $address) === 1;
    }

    private function encryption(): string
    {
        return Tls::resolve((string)($this->cfg['encryption'] ?? 'auto'), (int)($this->cfg['port'] ?? 587));
    }

    private function connect(): void
    {
        if ($this->connector !== null) {
            $this->socket = ($this->connector)();
        } else {
            $this->socket = Tls::connect(
                (string)$this->cfg['host'],
                (int)($this->cfg['port'] ?? 587),
                $this->encryption(),
                (int)($this->cfg['timeout'] ?? 20),
            );
        }
        $this->expect([220]);
    }

    private function handshake(): void
    {
        $ehlo = $this->cfg['ehlo'] ?? (gethostname() ?: 'localhost');
        $this->command("EHLO $ehlo", [250]);
        if ($this->connector === null && $this->encryption() === 'tls') {
            $this->command('STARTTLS', [220]);
            Tls::upgrade($this->socket);
            $this->command("EHLO $ehlo", [250]);
        }
    }

    private function authenticate(): void
    {
        $this->command('AUTH LOGIN', [334]);
        $this->command(base64_encode((string)$this->cfg['user']), [334], redact: true);
        $this->command(base64_encode((string)$this->cfg['pass']), [235], redact: true);
    }

    public function build(OutgoingMessage $m): string
    {
        $fromName = (string)($this->cfg['from_name'] ?? '');
        $from = $fromName !== ''
            ? self::encodeHeader($fromName) . ' <' . $this->cfg['from'] . '>'
            : (string)$this->cfg['from'];

        $headers = [
            'Date: ' . date('r'),
            'From: ' . $from,
            'To: ' . implode(', ', $m->to),
            'Subject: ' . self::encodeHeader($m->subject),
            'Message-ID: <' . $m->messageId . '>',
        ];
        if ($m->replyTo !== '') {
            $headers[] = 'Reply-To: ' . $m->replyTo;
        }
        if ($m->inReplyTo !== '') {
            $headers[] = 'In-Reply-To: <' . $m->inReplyTo . '>';
        }
        if ($m->references !== []) {
            $headers[] = 'References: ' . implode(' ', array_map(static fn ($r) => "<$r>", $m->references));
        }
        $headers[] = 'MIME-Version: 1.0';
        if ($m->autoSubmitted) {
            $headers[] = 'Auto-Submitted: auto-generated';
        }
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: quoted-printable';

        // Bcc steht absichtlich nicht im Header -- nur im Umschlag.
        return implode("\r\n", $headers) . "\r\n\r\n" . self::encodeBody($m->text);
    }

    /** Umlaute im Betreff muessen nach RFC 2047 kodiert werden. */
    public static function encodeHeader(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);
        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $value;
        }
        // In Stuecken kodieren: Ein einzelnes encoded-word darf hoechstens
        // 75 Zeichen lang sein (RFC 2047, 2), lange Betreffe wuerden sonst
        // von strengen Servern verworfen.
        $words = [];
        foreach (mb_str_split($value, 10, 'UTF-8') as $chunk) {
            $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }
        return implode("\r\n ", $words);
    }

    /**
     * Quoted-printable ist 7-bit-sicher und unauffaellig. Danach das
     * Dot-Stuffing: Eine Zeile, die mit einem Punkt beginnt, wuerde den
     * DATA-Block vorzeitig beenden.
     */
    public static function encodeBody(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = str_replace("\n", "\r\n", $body);
        $encoded = quoted_printable_encode($body);
        return preg_replace('/^\./m', '..', $encoded) ?? $encoded;
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
                throw new \RuntimeException(!empty($meta['timed_out'])
                    ? 'SMTP-Zeitueberschreitung beim Lesen der Antwort.'
                    : 'SMTP-Verbindung unerwartet beendet.');
            }
            $response .= $line;
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
