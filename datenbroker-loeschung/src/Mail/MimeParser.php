<?php
declare(strict_types=1);

namespace Optout\Mail;

/**
 * Zerlegt eine Rohnachricht so weit, wie es fuer das Zuordnen und Einordnen
 * einer Antwort noetig ist: Header, Klartext, Autoantwort- und
 * Bounce-Erkennung. Anhaenge werden ignoriert.
 */
final class MimeParser
{
    public static function parse(string $raw, string $folder = '', int $uid = 0): IncomingMessage
    {
        [$headers, $body] = self::split($raw);

        $ctype = self::contentType($headers);
        $parts = [];
        self::collect($headers, $body, $parts, 0);

        $plain = array_filter($parts, static fn ($p) => $p['type'] === 'text/plain');
        $html  = array_filter($parts, static fn ($p) => $p['type'] === 'text/html');
        $other = array_filter($parts, static fn ($p) => !in_array($p['type'], ['text/plain', 'text/html'], true));

        $text = $plain !== []
            ? implode("\n\n", array_column($plain, 'text'))
            : implode("\n\n", array_map(static fn ($p) => self::htmlToText($p['text']), $html));
        // Zustellberichte (message/delivery-status, message/rfc822) mitnehmen:
        // Darin steht die Message-ID der unzustellbaren Anfrage.
        if ($other !== []) {
            $text .= "\n\n" . implode("\n\n", array_column($other, 'text'));
        }
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        $from = self::firstAddress(self::header($headers, 'from'));
        $recipients = [];
        foreach (['to', 'cc', 'delivered-to', 'x-original-to', 'envelope-to'] as $h) {
            foreach (self::headerAll($headers, $h) as $value) {
                foreach (self::addresses($value) as $a) {
                    $recipients[] = strtolower($a['address']);
                }
            }
        }

        $subject = self::decodeHeader(self::header($headers, 'subject'));
        $date = strtotime(self::header($headers, 'date')) ?: 0;

        return new IncomingMessage(
            messageId: self::ids(self::header($headers, 'message-id'))[0] ?? '',
            inReplyTo: self::ids(self::header($headers, 'in-reply-to'))[0] ?? '',
            references: self::ids(self::header($headers, 'references')),
            from: strtolower($from['address']),
            fromName: $from['name'],
            recipients: array_values(array_unique($recipients)),
            subject: $subject,
            date: $date,
            text: $text,
            autoReply: self::isAutoReply($headers, $subject),
            bounce: self::isBounce($headers, $ctype, $from['address'], $subject),
            folder: $folder,
            uid: $uid,
        );
    }

    /** @return array{0: array<string, list<string>>, 1: string} */
    public static function split(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $pos = strpos($raw, "\n\n");
        $head = $pos === false ? $raw : substr($raw, 0, $pos);
        $body = $pos === false ? '' : substr($raw, $pos + 2);

        // Gefaltete Header (Fortsetzungszeilen beginnen mit Leerraum) entfalten.
        $head = preg_replace("/\n[ \t]+/", ' ', $head) ?? $head;
        $headers = [];
        foreach (explode("\n", $head) as $line) {
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            $headers[$name][] = trim(substr($line, $colon + 1));
        }
        return [$headers, $body];
    }

    /** @param array<string, list<string>> $headers */
    private static function header(array $headers, string $name): string
    {
        return $headers[$name][0] ?? '';
    }

    /** @param array<string, list<string>> $headers @return list<string> */
    private static function headerAll(array $headers, string $name): array
    {
        return $headers[$name] ?? [];
    }

    /** @return array{type:string, params:array<string,string>} */
    private static function contentType(array $headers): array
    {
        $raw = self::header($headers, 'content-type');
        if ($raw === '') {
            return ['type' => 'text/plain', 'params' => []];
        }
        $pieces = explode(';', $raw, 2);
        $params = [];
        if (isset($pieces[1]) && preg_match_all('/([a-z0-9*-]+)\s*=\s*("(?:[^"\\\\]|\\\\.)*"|[^;\s]+)/i', $pieces[1], $m, PREG_SET_ORDER)) {
            foreach ($m as $p) {
                $params[strtolower($p[1])] = trim($p[2], '"');
            }
        }
        return ['type' => strtolower(trim($pieces[0])), 'params' => $params];
    }

    /**
     * Sammelt alle lesbaren Teile, rekursiv durch multipart/*.
     *
     * @param array<string, list<string>> $headers
     * @param list<array{type:string, text:string}> $parts
     */
    private static function collect(array $headers, string $body, array &$parts, int $depth): void
    {
        if ($depth > 8) {
            return;
        }
        $ct = self::contentType($headers);
        $disposition = strtolower(self::header($headers, 'content-disposition'));
        if (str_starts_with($ct['type'], 'multipart/')) {
            $boundary = $ct['params']['boundary'] ?? '';
            if ($boundary === '') {
                return;
            }
            $chunks = preg_split('/^--' . preg_quote($boundary, '/') . '(?:--)?[ \t]*$/m', $body) ?: [];
            // Erstes Stueck ist die Praeambel, das letzte der Epilog.
            foreach (array_slice($chunks, 1, -1) as $chunk) {
                [$h, $b] = self::split(ltrim($chunk, "\n"));
                self::collect($h, $b, $parts, $depth + 1);
            }
            return;
        }
        if (str_starts_with($disposition, 'attachment')
            && !in_array($ct['type'], ['message/rfc822', 'message/delivery-status', 'text/rfc822-headers'], true)) {
            return;
        }
        if ($ct['type'] === 'message/rfc822') {
            // Die zurueckgeschickte Originalnachricht eines Bounce: Header als
            // Text behalten (Message-ID!), den Inhalt rekursiv lesen.
            [$h, $b] = self::split($body);
            $parts[] = ['type' => 'message/rfc822', 'text' => self::headerBlock($h)];
            self::collect($h, $b, $parts, $depth + 1);
            return;
        }
        if (!str_starts_with($ct['type'], 'text/') && !str_starts_with($ct['type'], 'message/')) {
            return;
        }
        $decoded = self::decodeTransfer($body, strtolower(self::header($headers, 'content-transfer-encoding')));
        $parts[] = [
            'type' => $ct['type'],
            'text' => self::toUtf8($decoded, $ct['params']['charset'] ?? ''),
        ];
    }

    /** @param array<string, list<string>> $headers */
    private static function headerBlock(array $headers): string
    {
        $lines = [];
        foreach (['message-id', 'subject', 'to', 'date'] as $name) {
            foreach ($headers[$name] ?? [] as $v) {
                $lines[] = ucfirst($name) . ': ' . $v;
            }
        }
        return implode("\n", $lines);
    }

    private static function decodeTransfer(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64'           => (string)base64_decode(preg_replace('/\s+/', '', $body) ?? '', false),
            'quoted-printable' => quoted_printable_decode($body),
            default            => $body,
        };
    }

    private static function toUtf8(string $text, string $charset): string
    {
        $charset = strtoupper(trim($charset));
        if ($charset === '' || $charset === 'US-ASCII') {
            return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
        }
        if ($charset === 'UTF-8' || $charset === 'UTF8') {
            return mb_check_encoding($text, 'UTF-8') ? $text : mb_scrub($text, 'UTF-8');
        }
        // Windows-1252 ist Obermenge von ISO-8859-1 und bei Outlook ueblich.
        if ($charset === 'ISO-8859-1') {
            $charset = 'Windows-1252';
        }
        try {
            $converted = @mb_convert_encoding($text, 'UTF-8', $charset);
            if (is_string($converted)) {
                return $converted;
            }
        } catch (\ValueError) {
            // Unbekannter Zeichensatz -- unten weiter.
        }
        $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
        return is_string($converted) ? $converted : mb_scrub($text, 'UTF-8');
    }

    public static function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>|</p>|</div>|</tr>|</li>|</h\d>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        return trim(preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text) ?? $text);
    }

    public static function decodeHeader(string $value): string
    {
        if ($value === '' || !str_contains($value, '=?')) {
            return $value;
        }
        // Leerraum zwischen zwei encoded-words gehoert nicht zum Text (RFC 2047, 6.2).
        $value = preg_replace('/\?=\s+=\?/', '?==?', $value) ?? $value;
        $decoded = preg_replace_callback('/=\?([^?]+)\?([bq])\?([^?]*)\?=/i', static function (array $m): string {
            $bytes = strtolower($m[2]) === 'b'
                ? (string)base64_decode($m[3])
                : quoted_printable_decode(str_replace('_', ' ', $m[3]));
            return self::toUtf8($bytes, preg_replace('/\*.*$/', '', $m[1]) ?? $m[1]);
        }, $value);
        return $decoded ?? $value;
    }

    /** @return list<string> */
    public static function ids(string $value): array
    {
        preg_match_all('/<([^<>\s]+)>/', $value, $m);
        return array_values(array_unique($m[1]));
    }

    /** @return array{address:string, name:string} */
    private static function firstAddress(string $value): array
    {
        return self::addresses($value)[0] ?? ['address' => '', 'name' => ''];
    }

    /** @return list<array{address:string, name:string}> */
    public static function addresses(string $value): array
    {
        $out = [];
        $value = self::decodeHeader($value);
        // Kommas in Anzeigenamen ("Muster, Max" <a@b>) duerfen nicht trennen.
        preg_match_all('/(?:"((?:[^"\\\\]|\\\\.)*)"|([^,<"]*))\s*<([^<>\s]+@[^<>\s]+)>|([^\s,<>"]+@[^\s,<>"]+)/', $value, $m, PREG_SET_ORDER);
        foreach ($m as $hit) {
            if (!empty($hit[4])) {
                $out[] = ['address' => $hit[4], 'name' => ''];
            } else {
                $out[] = ['address' => $hit[3], 'name' => trim(($hit[1] ?? '') !== '' ? stripslashes($hit[1]) : ($hit[2] ?? ''))];
            }
        }
        return $out;
    }

    /** @param array<string, list<string>> $headers */
    private static function isAutoReply(array $headers, string $subject): bool
    {
        $auto = strtolower(self::header($headers, 'auto-submitted'));
        if ($auto !== '' && $auto !== 'no') {
            return true;
        }
        if (isset($headers['x-autoreply']) || isset($headers['x-autorespond'])) {
            return true;
        }
        $precedence = strtolower(self::header($headers, 'precedence'));
        if (in_array($precedence, ['auto_reply', 'bulk', 'junk'], true)) {
            return true;
        }
        return preg_match('/^(auto(matic)?[ -]?(reply|response|antwort)|abwesen|out of (the )?office|automatische antwort|eingangsbest[äa]tigung)/iu', $subject) === 1;
    }

    /** @param array<string, list<string>> $headers @param array{type:string, params:array<string,string>} $ct */
    private static function isBounce(array $headers, array $ct, string $from, string $subject): bool
    {
        if ($ct['type'] === 'multipart/report' && strtolower($ct['params']['report-type'] ?? '') === 'delivery-status') {
            return true;
        }
        $local = strtolower(strstr($from, '@', true) ?: $from);
        if (in_array($local, ['mailer-daemon', 'postmaster'], true)
            && preg_match('/undeliver|unzustellbar|delivery (status|failure)|returned mail|nicht zugestellt|failure notice/i', $subject) === 1) {
            return true;
        }
        return preg_match('/^(undeliverable|unzustellbar|mail delivery failed|delivery status notification \(failure\)|returned mail)/i', $subject) === 1;
    }
}
