<?php
declare(strict_types=1);

namespace Kino\Match;

use Kino\Parse\Normalize;

/**
 * Entscheidet, ob ein Titel oder eine URL zu dem gehoert, was wir suchen.
 *
 * Die Muster sind absichtlich breit: Ein Fehlalarm kostet eine Mail, ein
 * verpasster Treffer kostet den Marathon. Das Zeitfenster verhindert, dass
 * eine regulaere Hobbit-Wiederauffuehrung im Maerz Alarm ausloest.
 */
final class TitleMatcher
{
    /** @param list<string> $patterns */
    public function __construct(
        private readonly array $patterns,
        private readonly string $windowFrom = '10-01',
        private readonly string $windowTo   = '02-28',
    ) {
    }

    public function matchesText(string $text): bool
    {
        $needle = Normalize::title($text);
        foreach ($this->patterns as $pattern) {
            if (@preg_match($pattern, $needle) === 1) {
                return true;
            }
        }
        return false;
    }

    /** URL-Slugs enthalten Bindestriche statt Leerzeichen -- beides pruefen. */
    public function matchesUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        return $this->matchesText(str_replace(['-', '_', '/'], ' ', $path));
    }

    public function matches(string $title, string $url = ''): bool
    {
        return $this->matchesText($title) || ($url !== '' && $this->matchesUrl($url));
    }

    /**
     * Liegt ein ISO-Datum im saisonalen Fenster? Das Fenster laeuft ueber den
     * Jahreswechsel (Oktober bis Februar), deshalb die Oder-Verknuepfung.
     */
    public function inDateWindow(?string $isoDate): bool
    {
        if ($isoDate === null) {
            return true; // noch kein Termin bekannt -> nicht vorschnell verwerfen
        }
        $ts = strtotime($isoDate);
        if ($ts === false) {
            return true;
        }
        $md   = date('m-d', $ts);
        $from = $this->windowFrom;
        $to   = $this->windowTo;
        return $from <= $to
            ? ($md >= $from && $md <= $to)
            : ($md >= $from || $md <= $to);
    }
}
