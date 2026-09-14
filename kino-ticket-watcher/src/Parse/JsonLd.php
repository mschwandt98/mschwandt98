<?php
declare(strict_types=1);

namespace Kino\Parse;

/**
 * Extrahiert schema.org-Daten aus <script type="application/ld+json">.
 *
 * Das ist die bevorzugte Quelle: Diese Bloecke werden fuer Google gepflegt und
 * aendern sich deutlich seltener als das sichtbare Markup. offers.availability
 * sagt uns direkt, was wir wissen wollen.
 */
final class JsonLd
{
    /** @return list<array<string,mixed>> flach ausgerollte Knotenliste */
    public static function extract(string $html): array
    {
        if (!preg_match_all(
            '#<script[^>]*type\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
            $html,
            $m
        )) {
            return [];
        }
        $nodes = [];
        foreach ($m[1] as $raw) {
            $raw = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }
            self::flatten($data, $nodes);
        }
        return $nodes;
    }

    /** Rollt @graph, Arrays und verschachtelte Objekte in eine flache Liste aus. */
    private static function flatten(array $data, array &$out): void
    {
        if (array_is_list($data)) {
            foreach ($data as $item) {
                if (is_array($item)) {
                    self::flatten($item, $out);
                }
            }
            return;
        }
        if (isset($data['@graph']) && is_array($data['@graph'])) {
            self::flatten($data['@graph'], $out);
        }
        if (isset($data['@type'])) {
            $out[] = $data;
        }
        foreach ($data as $key => $value) {
            if ($key !== '@graph' && is_array($value)) {
                self::flatten($value, $out);
            }
        }
    }

    /** @return list<string> alle @type-Werte eines Knotens, kleingeschrieben */
    public static function types(array $node): array
    {
        $t = $node['@type'] ?? [];
        $t = is_array($t) ? $t : [$t];
        return array_map(static fn ($x) => strtolower((string)$x), $t);
    }

    public static function isEvent(array $node): bool
    {
        foreach (self::types($node) as $t) {
            if (str_contains($t, 'event')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Verfuegbarkeit aus offers.availability, normalisiert auf ein Kuerzel.
     * Gibt null zurueck, wenn der Knoten dazu nichts sagt -- dann entscheidet
     * die Heuristik in Dom.php.
     */
    public static function availability(array $node): ?string
    {
        $offers = $node['offers'] ?? null;
        if ($offers === null) {
            return null;
        }
        $offers = array_is_list($offers) ? $offers : [$offers];
        $seen = [];
        foreach ($offers as $offer) {
            if (!is_array($offer) || !isset($offer['availability'])) {
                continue;
            }
            $a = strtolower((string)$offer['availability']);
            foreach (['soldout' => 'sold_out', 'outofstock' => 'sold_out', 'preorder' => 'preorder',
                      'presale' => 'preorder', 'limitedavailability' => 'in_stock', 'instock' => 'in_stock'] as $needle => $value) {
                if (str_contains(str_replace(['_', '-', ' '], '', $a), $needle)) {
                    $seen[] = $value;
                    break;
                }
            }
        }
        if ($seen === []) {
            return null;
        }
        // Wenn irgendein Angebot buchbar ist, ist die Vorstellung buchbar.
        if (in_array('in_stock', $seen, true)) {
            return 'in_stock';
        }
        return in_array('preorder', $seen, true) ? 'preorder' : 'sold_out';
    }

    public static function string(array $node, string $key): ?string
    {
        $v = $node[$key] ?? null;
        if (is_string($v) && $v !== '') {
            return $v;
        }
        if (is_array($v) && isset($v['name']) && is_string($v['name'])) {
            return $v['name'];
        }
        return null;
    }
}
