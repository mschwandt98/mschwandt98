<?php
declare(strict_types=1);

namespace Optout;

final class Config
{
    private function __construct(private readonly array $data)
    {
    }

    public static function load(?string $path = null): self
    {
        $path ??= dirname(__DIR__) . '/config/config.php';
        if (!is_file($path)) {
            throw new \RuntimeException(
                "Konfiguration fehlt: $path\n" .
                "Bitte config/config.example.php nach config/config.php kopieren und ausfuellen."
            );
        }
        $data = require $path;
        if (!is_array($data)) {
            throw new \RuntimeException('config.php muss ein Array zurueckgeben, tut es aber nicht.');
        }
        return self::fromArray($data);
    }

    public static function fromArray(array $data): self
    {
        $merged = array_replace_recursive(self::defaults(), $data);
        // Listen werden ersetzt, nicht elementweise gemischt: Wer
        // 'packs' => ['credit-agencies'] schreibt, meint genau dieses eine Paket.
        foreach (['brokers.packs', 'brokers.add', 'brokers.exclude', 'brokers.custom', 'imap.folders',
                     'identity.include', 'identity.emails', 'identity.previous_addresses', 'identity.phones'] as $key) {
            $value = self::dig($data, $key);
            if (is_array($value)) {
                self::put($merged, $key, $value);
            }
        }
        return new self($merged);
    }

    /** Punktnotation: $config->get('smtp.host') */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = self::dig($this->data, $key);
        return $value ?? $default;
    }

    public function all(): array
    {
        return $this->data;
    }

    public function baseDir(): string
    {
        return dirname(__DIR__);
    }

    public function dataDir(): string
    {
        return (string)$this->get('data_dir', $this->baseDir() . '/data');
    }

    public function varDir(): string
    {
        return (string)$this->get('var_dir', $this->baseDir() . '/var');
    }

    public function live(): bool
    {
        return $this->get('live') === true;
    }

    private static function dig(array $data, string $key): mixed
    {
        $node = $data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return null;
            }
            $node = $node[$part];
        }
        return $node;
    }

    private static function put(array &$data, string $key, mixed $value): void
    {
        $node = &$data;
        foreach (explode('.', $key) as $part) {
            if (!isset($node[$part]) || !is_array($node[$part])) {
                $node[$part] = [];
            }
            $node = &$node[$part];
        }
        $node = $value;
    }

    public static function defaults(): array
    {
        return [
            'timezone' => 'Europe/Berlin',

            // Solange false, wird nichts an Unternehmen verschickt -- nur
            // protokolliert, was verschickt wuerde. Erst nach der Vorschau
            // (bin/preview.php) auf true stellen.
            'live' => false,

            'identity' => [
                'name'               => '',
                'address'            => '',
                'previous_addresses' => [],
                'emails'             => [],
                'phones'             => [],
                'birthdate'          => '',
                // Was IMMER mitgeschickt wird. Adresshaendler finden dich nur
                // ueber die Postanschrift; ohne sie ist die Anfrage wertlos.
                // Weitere Angaben (Geburtsdatum, Telefon) gehen nur an Firmen,
                // die sie laut Datenbank zur Zuordnung verlangen.
                'include'            => ['name', 'address', 'emails'],
            ],

            'brokers' => [
                'packs'   => ['address-brokers', 'marketing-data', 'people-search', 'credit-agencies'],
                'add'     => [],
                'exclude' => [],
                'custom'  => [],
                // Welche Art Anfrage pro Kategorie. Bei Auskunfteien ist eine
                // Loeschung fast immer aussichtslos (berechtigtes Interesse),
                // die kostenlose Selbstauskunft dagegen lohnt sich.
                'kind_by_category' => [
                    'credit agency' => 'access',
                ],
                // MyLife fuehrt die Datenbank als Auskunftei, ist aber eine
                // Personensuche -- dort soll der Eintrag verschwinden.
                'kind_by_slug' => ['mylife' => 'erasure'],
                'default_kind' => 'erasure',
            ],

            'smtp' => [
                'host' => '', 'port' => 587, 'user' => '', 'pass' => '',
                'from' => '', 'from_name' => '',
                // Optional: Antwortadresse mit Plus-Adressierung, z. B.
                // 'datenschutz+{ref}@example.com'. Hilft beim Zuordnen von
                // Antworten, ist aber nicht noetig.
                'reply_to' => '',
                // Optional: Kopie jeder Anfrage an diese Adresse (Beleg).
                'bcc' => '',
                'encryption' => 'auto',
                'timeout' => 20,
            ],

            'imap' => [
                'host' => '', 'port' => 993, 'user' => '', 'pass' => '',
                'encryption' => 'auto',
                'folders' => ['INBOX'],
                'timeout' => 20,
                'max_messages_per_run' => 50,
                'max_message_bytes' => 300_000,
            ],

            'notify' => [
                'to'              => '',
                'subject_prefix'  => '[DATENSCHUTZ]',
                'digest_weekday'  => 1, // Montag
                'digest_hour'     => 8,
            ],

            // Aufsichtsbehoerde fuer Beschwerde-Entwuerfe (Slug aus data/authorities).
            'authority' => '',

            'schedule' => [
                'max_sends_per_run' => 5,
                // Zuschlag auf die Monatsfrist: Postlaufzeit, Wochenenden.
                'grace_days'        => 3,
                // Nachfrist nach der Erinnerung.
                'reminder_days'     => 14,
                // Wann eine erledigte Anfrage erneut gestellt wird. Broker
                // kaufen Daten nach -- das ist der Kern des Ganzen.
                'recheck_days'      => ['erasure' => 90, 'objection' => 180, 'access' => 365],
                // Mindestabstand zwischen zwei Anfragen an DIESELBE Firma,
                // auch bei manuellem "erneut senden".
                'min_resend_days'   => 7,
            ],

            'tick_secret'      => '',
            'dashboard_secret' => '',
            'dashboard_url'    => '',
        ];
    }
}
