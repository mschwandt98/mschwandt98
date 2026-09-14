<?php
declare(strict_types=1);

namespace Kino\Parse;

/**
 * HTML-Heuristiken als Rueckfallebene, wenn JSON-LD nichts hergibt.
 *
 * Bewusst grob gehalten: Feine CSS-Selektoren waeren eine Wette auf ein Markup,
 * das ich nicht kenne und das sich bis naechstes Jahr aendern kann. Diese
 * Heuristiken greifen auf Merkmale zurueck, die praktisch jede Kinoseite hat --
 * Links, Datumsangaben, deutsche Schluesselwoerter.
 */
final class Dom
{
    /** @return list<string> absolute URLs aller <a href> */
    public static function links(string $html, string $baseUrl): array
    {
        if (!preg_match_all('#<a\b[^>]*href\s*=\s*["\']([^"\']+)["\']#i', $html, $m)) {
            return [];
        }
        $out = [];
        foreach ($m[1] as $href) {
            $abs = self::absolutize(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $baseUrl);
            if ($abs !== null) {
                $out[$abs] = true;
            }
        }
        return array_keys($out);
    }

    public static function absolutize(string $href, string $baseUrl): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#') || preg_match('#^(mailto|tel|javascript):#i', $href)) {
            return null;
        }
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $root = $parts['scheme'] . '://' . $parts['host'];
        if (str_starts_with($href, '//')) {
            return $parts['scheme'] . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $root . $href;
        }
        $dir = rtrim(dirname($parts['path'] ?? '/'), '/');
        return $root . $dir . '/' . $href;
    }

    /** <title> der Seite, ohne Seitensuffix wie " | CinemaxX". */
    public static function title(string $html): ?string
    {
        if (!preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            return null;
        }
        $t = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $t = preg_replace('/\s*[\|\x{2013}\x{2014}-]\s*(cinemaxx|kinoprogramm).*$/iu', '', $t) ?? $t;
        return $t !== '' ? trim($t) : null;
    }

    /** <h1> der Seite -- bei Filmseiten meist der zuverlaessigste Titel. */
    public static function heading(string $html): ?string
    {
        if (!preg_match('#<h1[^>]*>(.*?)</h1>#is', $html, $m)) {
            return null;
        }
        $t = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        return $t !== '' ? $t : null;
    }

    /**
     * Deutsche Datumsangaben aus dem sichtbaren Text, als ISO-Datum.
     * Erkennt "13.12.2026", "13.12." und "13. Dezember 2026".
     *
     * @return list<string> ISO-Daten (Y-m-d), aufsteigend, ohne Duplikate
     */
    public static function dates(string $text, ?int $assumeYear = null): array
    {
        $assumeYear ??= (int)date('Y');
        $found = [];

        if (preg_match_all('/\b(\d{1,2})\.\s?(\d{1,2})\.(\d{4})?/u', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $found[] = [(int)$hit[1], (int)$hit[2], isset($hit[3]) && $hit[3] !== '' ? (int)$hit[3] : null];
            }
        }
        $months = ['januar'=>1,'februar'=>2,'maerz'=>3,'marz'=>3,'april'=>4,'mai'=>5,'juni'=>6,
                   'juli'=>7,'august'=>8,'september'=>9,'oktober'=>10,'november'=>11,'dezember'=>12];
        $folded = mb_strtolower(Normalize::fold($text), 'UTF-8');
        $names  = implode('|', array_keys($months));
        if (preg_match_all('/\b(\d{1,2})\.?\s+(' . $names . ')\s*(\d{4})?/u', $folded, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $found[] = [(int)$hit[1], $months[$hit[2]], isset($hit[3]) && $hit[3] !== '' ? (int)$hit[3] : null];
            }
        }

        $out = [];
        foreach ($found as [$d, $mo, $y]) {
            if ($d < 1 || $d > 31 || $mo < 1 || $mo > 12) {
                continue;
            }
            // Ohne Jahresangabe: Ein Datum, das dieses Jahr schon vorbei ist,
            // meint fast immer das kommende Jahr.
            if ($y === null) {
                $y = $assumeYear;
                if (mktime(0, 0, 0, $mo, $d, $y) < time() - 86400 * 14) {
                    $y++;
                }
            }
            if ($y < 2000 || $y > 2100 || !checkdate($mo, $d, $y)) {
                continue;
            }
            $out[sprintf('%04d-%02d-%02d', $y, $mo, $d)] = true;
        }
        $out = array_keys($out);
        sort($out);
        return $out;
    }

    /**
     * Vorverkaufsdatum aus Formulierungen wie "Vorverkauf startet am 14.10.
     * um 10:00 Uhr". Das ist der wertvollste Einzelfund im ganzen Parser:
     * Er erlaubt es, den Prueftakt gezielt vor den Start zu legen.
     */
    public static function presaleStart(string $text): ?string
    {
        $folded = mb_strtolower(Normalize::fold($text), 'UTF-8');
        // Das Suchfenster darf Punkte enthalten -- ein deutsches Datum besteht
        // aus ihnen. Eine Begrenzung auf "bis zum Satzende" wuerde genau die
        // Angabe abschneiden, auf die es ankommt.
        if (!preg_match(
            '/(vorverkauf|vvk|tickets?)\s*(startet|start|beginnt|beginn|ab|gibt es ab)?.{0,80}/us',
            $folded,
            $m
        )) {
            return null;
        }
        $window = $m[0];
        $dates = self::dates($window);
        if ($dates === []) {
            return null;
        }
        return $dates[0] . 'T' . self::timeIn($window) . ':00';
    }

    /**
     * Uhrzeit aus einem Textfenster. Nur Doppelpunkt-Schreibweise oder eine
     * Angabe mit "Uhr" zaehlen: Ein Punkt als Trenner waere hier fatal, weil
     * "14.10.2026" sonst als 14:10 gelesen wird.
     */
    private static function timeIn(string $window): string
    {
        if (preg_match('/\b(\d{1,2}):(\d{2})/u', $window, $t)) {
            return sprintf('%02d:%02d', (int)$t[1], (int)$t[2]);
        }
        if (preg_match('/\b(\d{1,2})(?:[:.](\d{2}))?\s*uhr/u', $window, $t)) {
            return sprintf('%02d:%02d', (int)$t[1], (int)($t[2] ?? 0));
        }
        return '10:00'; // uebliche Startzeit, wenn nichts dabeisteht
    }

    /** Enthaelt der Text einen eindeutigen Ausverkauft-Marker? */
    public static function looksSoldOut(string $text): bool
    {
        $folded = mb_strtolower(Normalize::fold($text), 'UTF-8');
        return (bool)preg_match('/\b(ausverkauft|sold ?out|keine (tickets|plaetze) (mehr )?(verfuegbar|frei))\b/u', $folded);
    }

    /** Deutet der Text auf einen noch nicht gestarteten Vorverkauf hin? */
    public static function looksPending(string $text): bool
    {
        $folded = mb_strtolower(Normalize::fold($text), 'UTF-8');
        return (bool)preg_match(
            '/\b(vorverkauf (startet|beginnt|ab)|noch nicht buchbar|demnaechst|coming soon|bald (im kino|verfuegbar))\b/u',
            $folded
        );
    }
}
