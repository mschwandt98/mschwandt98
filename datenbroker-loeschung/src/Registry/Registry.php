<?php
declare(strict_types=1);

namespace Optout\Registry;

use Optout\Config;

/**
 * Alle bekannten Unternehmen und die Auswahl, die tatsaechlich angeschrieben wird.
 *
 * Quellen, spaetere ueberschreiben fruehere:
 *   1. data/companies/*.json   mitgelieferter Stand der Datenanfragen.de-Datenbank
 *   2. var/db/*.json           per bin/sync-db.php aktualisierte Datensaetze
 *   3. brokers.custom          eigene Eintraege aus config.php
 */
final class Registry
{
    /**
     * Eigene Pakete, zusaetzlich zu denen von Datenanfragen.de. Die Liste
     * orientiert sich an dem, was kommerzielle Dienste fuer Deutschland
     * abdecken: Adresshandel, Marketingdaten, Personen- und Kontaktsuche.
     */
    public const BUILTIN_PACKS = [
        'marketing-data' => ['bedirect', 'nexiga', 'panadress', 'liveramp-de', 'epsilon-com'],
        'people-search'  => ['dastelefonbuch', 'mylife', 'zoominfo', 'apollo-io', 'lusha', 'rocketreach', 'kaspr', 'contactout'],
    ];

    /** @var array<string, Broker> */
    private array $brokers = [];
    /** @var array<string, list<string>> */
    private array $packs = [];
    /** @var array<string, array> */
    private array $authorities = [];
    /** @var list<string> */
    private array $warnings = [];

    private function __construct()
    {
    }

    public static function load(Config $config): self
    {
        $r = new self();
        $data = $config->dataDir();
        foreach ([$data . '/companies', $config->varDir() . '/db'] as $dir) {
            foreach (glob($dir . '/*.json') ?: [] as $file) {
                $record = json_decode((string)file_get_contents($file), true);
                if (!is_array($record) || ($record['slug'] ?? '') === '') {
                    $r->warnings[] = 'Datensatz unlesbar: ' . basename($file);
                    continue;
                }
                $r->brokers[$record['slug']] = Broker::fromRecord($record);
            }
        }
        foreach ((array)$config->get('brokers.custom', []) as $i => $record) {
            if (!is_array($record) || trim((string)($record['slug'] ?? '')) === '') {
                $r->warnings[] = "brokers.custom[$i] hat keinen 'slug'.";
                continue;
            }
            $r->brokers[$record['slug']] = Broker::fromRecord($record, 'config');
        }

        $packFile = $data . '/packs-de.json';
        foreach ((array)json_decode((string)@file_get_contents($packFile), true) as $pack) {
            if (is_array($pack) && isset($pack['slug'], $pack['companies'])) {
                $r->packs[(string)$pack['slug']] = array_values((array)$pack['companies']);
            }
        }
        $r->packs = array_merge($r->packs, self::BUILTIN_PACKS);

        foreach (glob($data . '/authorities/*.json') ?: [] as $file) {
            $record = json_decode((string)file_get_contents($file), true);
            if (is_array($record) && isset($record['slug'])) {
                $r->authorities[$record['slug']] = $record;
            }
        }
        return $r;
    }

    /** @param array<string, Broker> $brokers nur fuer Tests */
    public static function fromBrokers(array $brokers, array $packs = [], array $authorities = []): self
    {
        $r = new self();
        foreach ($brokers as $b) {
            $r->brokers[$b->slug] = $b;
        }
        $r->packs = $packs;
        $r->authorities = $authorities;
        return $r;
    }

    public function find(string $slug): ?Broker
    {
        return $this->brokers[$slug] ?? null;
    }

    /** @return array<string, Broker> */
    public function all(): array
    {
        return $this->brokers;
    }

    /** @return array<string, list<string>> */
    public function packs(): array
    {
        return $this->packs;
    }

    /**
     * Die Unternehmen, die laut Konfiguration angeschrieben werden.
     *
     * @return array<string, Broker>
     */
    public function selected(Config $config): array
    {
        $slugs = [];
        foreach ((array)$config->get('brokers.packs', []) as $pack) {
            if (!isset($this->packs[$pack])) {
                $this->warnings[] = "Unbekanntes Paket '$pack'. Vorhanden: " . implode(', ', array_keys($this->packs));
                continue;
            }
            array_push($slugs, ...$this->packs[$pack]);
        }
        array_push($slugs, ...array_map('strval', (array)$config->get('brokers.add', [])));
        foreach ((array)$config->get('brokers.custom', []) as $record) {
            if (is_array($record) && isset($record['slug'])) {
                $slugs[] = (string)$record['slug'];
            }
        }
        $exclude = array_map('strval', (array)$config->get('brokers.exclude', []));

        $out = [];
        foreach (array_unique($slugs) as $slug) {
            if (in_array($slug, $exclude, true)) {
                continue;
            }
            $broker = $this->brokers[$slug] ?? null;
            if ($broker === null) {
                $this->warnings[] = "Unternehmen '$slug' ist nicht in der Datenbank. bin/sync-db.php --add=$slug laedt es nach.";
                continue;
            }
            $out[$slug] = $broker;
        }
        ksort($out);
        return $out;
    }

    /** @return list<Broker> */
    public function search(string $query, int $limit = 20): array
    {
        $q = mb_strtolower(trim($query));
        $hits = [];
        foreach ($this->brokers as $b) {
            $hay = mb_strtolower($b->slug . ' ' . $b->name . ' ' . $b->web . ' ' . $b->email . ' ' . implode(' ', $b->runs));
            if ($q !== '' && str_contains($hay, $q)) {
                $hits[] = $b;
            }
        }
        return array_slice($hits, 0, $limit);
    }

    public function authority(string $slug): ?array
    {
        return $this->authorities[$slug] ?? null;
    }

    /** @return array<string, array> */
    public function authorities(): array
    {
        return $this->authorities;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return array_values(array_unique($this->warnings));
    }
}
