<?php
declare(strict_types=1);

namespace Optout\Registry;

/** Ein Unternehmen, an das Anfragen gehen -- ein Datensatz der Datenanfragen.de-Datenbank. */
final class Broker
{
    /**
     * @param list<string> $categories
     * @param list<string> $countries
     * @param list<string> $requiredElements Typen: name, address, birthdate, email, phone, ...
     * @param list<string> $runs
     * @param list<string> $comments
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $email,
        public readonly string $webform,
        public readonly string $web,
        public readonly string $address,
        public readonly array $categories,
        public readonly array $countries,
        public readonly string $language,
        public readonly array $requiredElements,
        public readonly bool $needsIdDocument,
        public readonly array $runs,
        public readonly array $comments,
        public readonly string $transport,
        public readonly string $source,
    ) {
    }

    public static function fromRecord(array $r, string $source = 'datenanfragen'): self
    {
        $countries = array_values(array_map('strval', (array)($r['relevant-countries'] ?? ['de'])));
        $language = (string)($r['request-language'] ?? '');
        if ($language === '') {
            $language = in_array('de', $countries, true) || in_array('at', $countries, true) ? 'de' : 'en';
        }
        $email = trim((string)($r['email'] ?? ''));
        return new self(
            slug: (string)($r['slug'] ?? ''),
            name: (string)($r['name'] ?? ($r['slug'] ?? '')),
            email: $email,
            webform: (string)($r['webform'] ?? ''),
            web: (string)($r['web'] ?? ''),
            address: (string)($r['address'] ?? ''),
            categories: array_values(array_map('strval', (array)($r['categories'] ?? []))),
            countries: $countries,
            language: in_array($language, ['de', 'en'], true) ? $language : 'en',
            requiredElements: array_values(array_unique(array_map(
                static fn ($e) => (string)(is_array($e) ? ($e['type'] ?? '') : $e),
                (array)($r['required-elements'] ?? [])
            ))),
            needsIdDocument: ($r['needs-id-document'] ?? false) === true,
            runs: array_values(array_map('strval', (array)($r['runs'] ?? []))),
            comments: array_values(array_map('strval', (array)($r['comments'] ?? []))),
            transport: (string)($r['suggested-transport-medium'] ?? ($email !== '' ? 'email' : 'letter')),
            source: $source,
        );
    }

    public function canEmail(): bool
    {
        return $this->email !== '' && preg_match('/^[^\s<>@,;"]+@[^\s<>@,;"]+\.[^\s<>@,;"]+$/', $this->email) === 1;
    }

    /**
     * Domains, von denen Antworten dieses Unternehmens kommen koennen:
     * die der Datenschutzadresse und die der Website, jeweils als
     * registrierbare Domain ("mail.schufa.de" -> "schufa.de").
     *
     * @return list<string>
     */
    public function domains(): array
    {
        $domains = [];
        if ($this->email !== '') {
            $at = strrchr($this->email, '@');
            if ($at !== false) {
                $domains[] = self::registrable(substr($at, 1));
            }
        }
        foreach ([$this->web, $this->webform] as $url) {
            $host = (string)parse_url($url, PHP_URL_HOST);
            if ($host !== '') {
                $domains[] = self::registrable($host);
            }
        }
        return array_values(array_unique(array_filter($domains)));
    }

    /** Grobe Naeherung ohne Public-Suffix-Liste; genuegt fuer die Zuordnung. */
    public static function registrable(string $host): string
    {
        $host = strtolower(rtrim($host, '.'));
        $labels = explode('.', $host);
        if (count($labels) <= 2) {
            return $host;
        }
        $twoLevel = ['co.uk', 'org.uk', 'ac.uk', 'com.au', 'co.at', 'or.at', 'com.br', 'co.jp', 'co.nz', 'com.tr'];
        $lastTwo = implode('.', array_slice($labels, -2));
        return in_array($lastTwo, $twoLevel, true)
            ? implode('.', array_slice($labels, -3))
            : $lastTwo;
    }
}
