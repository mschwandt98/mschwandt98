<?php
declare(strict_types=1);

namespace Optout\Mail;

/**
 * Minimaler, nur lesender IMAP-Client ueber stream_socket_client.
 *
 * Warum nicht ext-imap? Die Extension ist seit PHP 8.4 nicht mehr Teil von
 * PHP, und auf Shared Hosting laesst sie sich nicht nachinstallieren. Fuer
 * unseren Zweck -- neue Nachrichten seit dem letzten Lauf abholen -- reichen
 * fuenf Kommandos.
 *
 * Postfaecher werden mit EXAMINE geoeffnet, Nachrichten mit BODY.PEEK
 * gelesen: Der Client veraendert nichts, nicht einmal das Gelesen-Flag.
 */
final class Imap
{
    /** @var resource|null */
    private $socket = null;
    private int $tag = 0;
    private string $transcript = '';
    /** @var \Closure|null fn(): resource -- nur fuer Tests */
    private ?\Closure $connector;

    public function __construct(private readonly array $cfg, ?\Closure $connector = null)
    {
        $this->connector = $connector;
    }

    public function open(): void
    {
        foreach (['host', 'user', 'pass'] as $required) {
            if (trim((string)($this->cfg[$required] ?? '')) === '') {
                throw new \RuntimeException("IMAP-Konfiguration unvollstaendig: '$required' fehlt.");
            }
        }
        $port = (int)($this->cfg['port'] ?? 993);
        $enc = Tls::resolve((string)($this->cfg['encryption'] ?? 'auto'), $port);
        if ($this->connector !== null) {
            $this->socket = ($this->connector)();
        } else {
            $this->socket = Tls::connect((string)$this->cfg['host'], $port, $enc, (int)($this->cfg['timeout'] ?? 20));
        }
        $greeting = $this->readLine();
        if (!str_starts_with($greeting, '* OK') && !str_starts_with($greeting, '* PREAUTH')) {
            throw new \RuntimeException('Unerwartete IMAP-Begruessung: ' . trim($greeting));
        }
        if ($this->connector === null && $enc === 'tls') {
            $this->command('STARTTLS');
            Tls::upgrade($this->socket);
        }
        if (!str_starts_with($greeting, '* PREAUTH')) {
            $this->command('LOGIN ' . $this->astring((string)$this->cfg['user']) . ' '
                . $this->astring((string)$this->cfg['pass']), redact: true);
        }
    }

    /**
     * Oeffnet ein Postfach nur lesend.
     *
     * @return array{uidvalidity:int, uidnext:int, exists:int}
     */
    public function examine(string $folder): array
    {
        $lines = $this->command('EXAMINE ' . $this->quote($folder));
        $info = ['uidvalidity' => 0, 'uidnext' => 0, 'exists' => 0];
        foreach ($lines as [$line]) {
            if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $line, $m)) {
                $info['uidvalidity'] = (int)$m[1];
            }
            if (preg_match('/\[UIDNEXT (\d+)\]/i', $line, $m)) {
                $info['uidnext'] = (int)$m[1];
            }
            if (preg_match('/^\* (\d+) EXISTS/i', $line, $m)) {
                $info['exists'] = (int)$m[1];
            }
        }
        return $info;
    }

    /** @return list<int> UIDs groesser als $afterUid, aufsteigend */
    public function uidsAfter(int $afterUid): array
    {
        // "n:*" liefert laut RFC 3501 immer mindestens die letzte Nachricht,
        // auch wenn deren UID kleiner als n ist -- deshalb wird nachgefiltert.
        $lines = $this->command('UID SEARCH UID ' . ($afterUid + 1) . ':*');
        $uids = [];
        foreach ($lines as [$line]) {
            if (preg_match('/^\* SEARCH\b(.*)$/i', rtrim($line), $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $v) {
                    if ($v !== '' && ctype_digit($v) && (int)$v > $afterUid) {
                        $uids[] = (int)$v;
                    }
                }
            }
        }
        sort($uids);
        return array_values(array_unique($uids));
    }

    /** Rohnachricht, hoechstens $maxBytes lang. */
    public function fetch(int $uid, int $maxBytes): string
    {
        $lines = $this->command("UID FETCH $uid (BODY.PEEK[]<0.$maxBytes>)");
        foreach ($lines as [$line, $literals]) {
            if (preg_match('/^\* \d+ FETCH/i', $line) && $literals !== []) {
                return $literals[0];
            }
        }
        return '';
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            try {
                $this->command('LOGOUT');
            } catch (\Throwable) {
                // Beim Abmelden ist ein Fehler bedeutungslos.
            }
            fclose($this->socket);
        }
        $this->socket = null;
    }

    public function transcript(): string
    {
        return $this->transcript;
    }

    private function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * Zugangsdaten als quoted string, oder als Literal, wenn sie Zeichen
     * enthalten, die in einem quoted string nicht erlaubt sind (8-Bit, CR/LF).
     */
    private function astring(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $this->quote($value);
        }
        return "\0LITERAL" . base64_encode($value);
    }

    /**
     * Schickt ein Kommando und liest bis zur getaggten Antwort.
     *
     * @return list<array{0:string, 1:list<string>}> ungetaggte Antworten mit ihren Literalen
     */
    private function command(string $command, bool $redact = false): array
    {
        $tag = sprintf('A%04d', ++$this->tag);
        $this->transcript .= "> $tag " . ($redact ? preg_replace('/ .*/', ' ***', $command) : $command) . "\n";

        // Literale (fuer 8-Bit-Zugangsdaten) brauchen eine Fortsetzungsanfrage.
        $parts = preg_split('/\0LITERAL([A-Za-z0-9+\/=]*)/', $command, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$command];
        $out = "$tag " . $parts[0];
        for ($i = 1; $i < count($parts); $i += 2) {
            $literal = (string)base64_decode($parts[$i]);
            $this->write($out . '{' . strlen($literal) . "}\r\n");
            $cont = $this->readLine();
            if (!str_starts_with($cont, '+')) {
                throw new \RuntimeException('IMAP-Server verweigert Literal: ' . trim($cont));
            }
            $out = $literal . ($parts[$i + 1] ?? '');
        }
        $this->write($out . "\r\n");

        $untagged = [];
        while (true) {
            [$line, $literals] = $this->readResponse();
            if (str_starts_with($line, $tag . ' ')) {
                $this->transcript .= '< ' . trim($line) . "\n";
                if (!preg_match('/^' . $tag . ' OK\b/i', $line)) {
                    throw new \RuntimeException('IMAP-Fehler bei "' . strtok($command, ' ') . '": ' . trim(substr($line, strlen($tag) + 1)));
                }
                return $untagged;
            }
            $untagged[] = [$line, $literals];
        }
    }

    /**
     * Eine vollstaendige Antwortzeile, mit eingebetteten Literalen
     * ({n}\r\n gefolgt von n Bytes) in beliebiger Zahl.
     *
     * @return array{0:string, 1:list<string>}
     */
    private function readResponse(): array
    {
        $line = '';
        $literals = [];
        while (true) {
            $chunk = $this->readLine();
            $line .= $chunk;
            if (preg_match('/\{(\d+)\}\r?\n$/', $chunk, $m)) {
                $literals[] = $this->readBytes((int)$m[1]);
                continue;
            }
            return [$line, $literals];
        }
    }

    private function readLine(): string
    {
        if (!is_resource($this->socket)) {
            throw new \RuntimeException('IMAP-Verbindung nicht offen.');
        }
        $line = fgets($this->socket, 65536);
        if ($line === false) {
            $meta = stream_get_meta_data($this->socket);
            throw new \RuntimeException(!empty($meta['timed_out'])
                ? 'IMAP-Zeitueberschreitung beim Lesen.'
                : 'IMAP-Verbindung unerwartet beendet.');
        }
        return $line;
    }

    private function readBytes(int $n): string
    {
        $data = '';
        while (strlen($data) < $n) {
            $chunk = fread($this->socket, min(65536, $n - strlen($data)));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);
                if (!empty($meta['timed_out']) || feof($this->socket)) {
                    throw new \RuntimeException('IMAP-Literal unvollstaendig.');
                }
                continue;
            }
            $data .= $chunk;
        }
        return $data;
    }

    private function write(string $data): void
    {
        if (!is_resource($this->socket) || fwrite($this->socket, $data) === false) {
            throw new \RuntimeException('Schreiben auf den IMAP-Socket fehlgeschlagen.');
        }
    }
}
