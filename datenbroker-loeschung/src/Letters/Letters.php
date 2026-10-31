<?php
declare(strict_types=1);

namespace Optout\Letters;

use Optout\Config;
use Optout\Registry\Broker;

/**
 * Setzt die Brieftexte zusammen.
 *
 * Die Vorlagen stammen von Datenanfragen.de und benutzen deren Syntax:
 *   [flag>Text]  erscheint nur, wenn flag gesetzt ist
 *   {variable}   wird ersetzt
 */
final class Letters
{
    public function __construct(private readonly Config $config)
    {
    }

    /** @return array{subject:string, body:string, missing:list<string>} */
    public function request(Broker $broker, Kind $kind, string $ref): array
    {
        $lang = $broker->language;
        [$idData, $missing] = $this->identityBlock($broker, $lang);

        $flags = [
            'erase_all'         => true,
            'erase_some'        => false,
            'include_objection' => true,
            'data_portability'  => false,
            'runs'              => $broker->runs !== [],
            'has_fields'        => $idData !== '',
        ];
        $vars = [
            'objection_reason' => $lang === 'de'
                ? 'Insbesondere widerspreche ich nach Art. 21 Abs. 2 DSGVO der Verarbeitung für Zwecke der Direktwerbung und des Adresshandels; dieser Widerspruch bedarf keiner Begründung.'
                : 'In particular, I object to processing for direct marketing purposes and the trading of addresses according to Article 21(2) GDPR; this objection does not require any justification.',
            'runs_list' => implode(', ', $broker->runs),
            'id_data'   => $idData,
        ];

        $file = match ($kind) {
            Kind::Erasure   => 'erasure-default',
            Kind::Objection => 'objection-default',
            Kind::Access    => 'access-default',
        };
        $body = $this->render($this->template($lang, $file), $flags, $vars);
        $body .= "\n" . $this->identity('name') . "\n";
        $body .= "\n" . ($lang === 'de' ? 'Referenz' : 'Reference') . ": $ref\n";

        return [
            'subject' => $kind->subject($lang) . " [Ref $ref]",
            'body'    => $body,
            'missing' => $missing,
        ];
    }

    /** @return array{subject:string, body:string} */
    public function reminder(Broker $broker, Kind $kind, string $ref, int $requestedAt, string $originalBody): array
    {
        $lang = $broker->language;
        $body = $this->render($this->template($lang, 'reminder'), [], [
            'request_date'    => self::date($requestedAt, $lang),
            'request_article' => $kind->article(),
            'ref'             => $ref,
        ]);
        $body .= "\n" . $this->identity('name') . "\n\n";
        $body .= ($lang === 'de' ? '--- Ursprüngliche Anfrage vom ' : '--- Original request of ')
            . self::date($requestedAt, $lang) . " ---\n\n" . self::quote($originalBody);
        return [
            'subject' => ($lang === 'de' ? 'Erinnerung: ' : 'Reminder: ') . $kind->subject($lang) . " [Ref $ref]",
            'body'    => $body,
        ];
    }

    /** Entwurf einer Beschwerde bei der Aufsichtsbehoerde. @return array{to:string, subject:string, body:string} */
    public function complaint(Broker $broker, Kind $kind, string $ref, int $requestedAt, int $remindedAt, string $originalBody, ?array $authority): array
    {
        $recipient = $broker->name . ($broker->address !== '' ? "\n" . $broker->address : '')
            . ($broker->email !== '' ? "\n" . $broker->email : '');
        $body = $this->render($this->template('de', 'complaint'), [], [
            'request_date'      => self::date($requestedAt, 'de'),
            'request_article'   => $kind->article(),
            'request_recipient' => $recipient,
            'reminder_date'     => self::date($remindedAt, 'de'),
            'contact'           => (string)($this->config->get('smtp.from') ?: ($this->identity('emails')[0] ?? '')),
        ]);
        $body .= "\n" . $this->identity('name') . "\n\n--- Anfrage vom " . self::date($requestedAt, 'de') . " ---\n\n" . self::quote($originalBody);
        return [
            'to'      => (string)($authority['email'] ?? ''),
            'subject' => "Beschwerde nach Art. 77 DSGVO gegen {$broker->name} [Ref $ref]",
            'body'    => $body,
        ];
    }

    /**
     * Die Angaben zur Identifikation. Immer: was in identity.include steht.
     * Zusaetzlich: was das Unternehmen laut Datenbank zur Zuordnung verlangt.
     *
     * @return array{0:string, 1:list<string>} Text und fehlende Pflichtangaben
     */
    public function identityBlock(Broker $broker, string $lang): array
    {
        $wanted = array_map('strval', (array)$this->config->get('identity.include', []));
        foreach ($broker->requiredElements as $type) {
            $wanted[] = match ($type) {
                'address' => 'address',
                'birthdate', 'birthday' => 'birthdate',
                'email' => 'emails',
                'phone' => 'phones',
                default => $type,
            };
        }
        $wanted = array_values(array_unique($wanted));

        $labels = $lang === 'de'
            ? ['name' => 'Name', 'address' => 'Anschrift', 'previous_addresses' => 'Frühere Anschrift',
               'emails' => 'E-Mail', 'phones' => 'Telefon', 'birthdate' => 'Geburtsdatum']
            : ['name' => 'Name', 'address' => 'Address', 'previous_addresses' => 'Previous address',
               'emails' => 'Email', 'phones' => 'Phone', 'birthdate' => 'Date of birth'];

        $lines = [];
        $missing = [];
        // Feste Reihenfolge, unabhaengig von der Konfiguration.
        foreach (['name', 'address', 'previous_addresses', 'birthdate', 'emails', 'phones'] as $key) {
            $include = in_array($key, $wanted, true)
                || ($key === 'previous_addresses' && in_array('address', $wanted, true));
            if (!$include) {
                continue;
            }
            $value = $this->identity($key);
            $values = is_array($value) ? $value : [$value];
            $values = array_values(array_filter(array_map(static fn ($v) => trim((string)$v), $values), static fn ($v) => $v !== ''));
            if ($values === []) {
                if ($key !== 'previous_addresses' && in_array($key, $wanted, true)) {
                    $missing[] = $key;
                }
                continue;
            }
            foreach ($values as $v) {
                $v = str_replace("\n", ', ', $v);
                $lines[] = $labels[$key] . ': ' . $v;
            }
        }
        // Unbekannte Pflichtangaben (z. B. "customer_number") melden, nicht raten.
        foreach ($wanted as $key) {
            if (!isset($labels[$key])) {
                $missing[] = $key;
            }
        }
        return [implode("\n", $lines) . ($lines !== [] ? "\n" : ''), array_values(array_unique($missing))];
    }

    private function identity(string $key): mixed
    {
        return $this->config->get('identity.' . $key, '');
    }

    private function template(string $lang, string $name): string
    {
        $file = $this->config->dataDir() . "/templates/$lang/$name.txt";
        if (!is_file($file)) {
            $file = $this->config->dataDir() . "/templates/en/$name.txt";
        }
        $text = file_get_contents($file);
        if ($text === false) {
            throw new \RuntimeException("Vorlage fehlt: $file");
        }
        return $text;
    }

    /**
     * @param array<string,bool> $flags
     * @param array<string,string> $vars
     */
    public static function render(string $template, array $flags, array $vars): string
    {
        $text = preg_replace_callback('/\[([a-z_]+)>(.*?)\]/s', static function (array $m) use ($flags): string {
            return !empty($flags[$m[1]]) ? $m[2] : '';
        }, $template) ?? $template;
        $text = preg_replace_callback('/\{([a-z_]+)\}/', static function (array $m) use ($vars): string {
            return $vars[$m[1]] ?? $m[0];
        }, $text) ?? $text;
        // Auszeichnungen der Web-Oberflaeche (<italic>) haben in Klartext nichts verloren.
        $text = preg_replace('#</?(italic|bold)>#', '', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return rtrim($text) . "\n";
    }

    public static function date(int $ts, string $lang): string
    {
        return $lang === 'de' ? date('d.m.Y', $ts) : date('F j, Y', $ts);
    }

    private static function quote(string $text): string
    {
        return implode("\n", array_map(static fn ($l) => '> ' . $l, explode("\n", rtrim($text)))) . "\n";
    }
}
