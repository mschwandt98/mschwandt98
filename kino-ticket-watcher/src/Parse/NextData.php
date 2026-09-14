<?php
declare(strict_types=1);

namespace Kino\Parse;

/**
 * Liest den __NEXT_DATA__-Block der CinemaxX-Seiten.
 *
 * Die Seite ist eine Next.js-Anwendung auf Sitecore. Die fuer uns wichtigen
 * Kennungen stehen nicht im sichtbaren Markup, sondern in diesem JSON-Block:
 *
 *   context.cinema.cinemaId.value  -> "1391"          (CinemaxX Magdeburg)
 *   context.film.filmId.value      -> "HO00001617"    (Film-Kennung der API)
 *
 * Mit diesen beiden Werten laesst sich die Showings-API abfragen, die
 * verbindlich sagt, ob eine Vorstellung buchbar ist. Das Markup selbst muss
 * dafuer gar nicht mehr interpretiert werden.
 *
 * Achtung: Die GUIDs daneben (context.cinema.id, context.film.id) sind
 * Sitecore-interne Kennungen und fuer die API nutzlos.
 */
final class NextData
{
    public static function extract(string $html): ?array
    {
        if (!preg_match(
            '#<script[^>]*id=["\']__NEXT_DATA__["\'][^>]*>(.*?)</script>#is',
            $html,
            $m
        )) {
            return null;
        }
        $data = json_decode(trim($m[1]), true);
        return is_array($data) ? $data : null;
    }

    private static function context(array $nextData): array
    {
        $ctx = $nextData['props']['pageProps']['layoutData']['sitecore']['context'] ?? null;
        return is_array($ctx) ? $ctx : [];
    }

    /** Numerische Kino-Kennung, z. B. "1391" fuer Magdeburg. */
    public static function cinemaId(array $nextData): ?string
    {
        $v = self::context($nextData)['cinema']['cinemaId']['value'] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    public static function cinemaName(array $nextData): ?string
    {
        $v = self::context($nextData)['cinema']['fullName']['value']
            ?? self::context($nextData)['cinema']['cinemaName']['value']
            ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    /** Film-Kennung der Showings-API, z. B. "HO00001617". */
    public static function filmId(array $nextData): ?string
    {
        $v = self::context($nextData)['film']['filmId']['value'] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    public static function filmName(array $nextData): ?string
    {
        $v = self::context($nextData)['film']['filmName']['value'] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    /** Bequemer Einstieg: direkt aus HTML, ohne Zwischenschritt. */
    public static function idsFromHtml(string $html): array
    {
        $data = self::extract($html);
        if ($data === null) {
            return ['cinemaId' => null, 'filmId' => null, 'filmName' => null];
        }
        return [
            'cinemaId' => self::cinemaId($data),
            'filmId'   => self::filmId($data),
            'filmName' => self::filmName($data),
        ];
    }
}
