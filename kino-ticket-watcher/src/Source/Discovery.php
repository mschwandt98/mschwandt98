<?php
declare(strict_types=1);

namespace Kino\Source;

use Kino\Http\Client;
use Kino\Match\TitleMatcher;
use Kino\Parse\Dom;
use Kino\State\Store;

/**
 * Findet Kandidaten-Filmseiten. Bewusst nicht an eine feste URL gebunden:
 * Der Slug von diesem Jahr wird naechstes Jahr anders lauten, und genau dann
 * darf der Watcher nicht blind sein.
 *
 * Drei Wege parallel:
 *   - Programmuebersicht des Kinos
 *   - sitemap.xml (bester Fruehindikator, ausdruecklich fuer Crawler gedacht)
 *   - bekannte URLs aus der Konfiguration
 */
final class Discovery
{
    public function __construct(
        private readonly Client $http,
        private readonly TitleMatcher $matcher,
        private readonly string $baseUrl,
        private readonly string $programPath,
        private readonly string $city,
    ) {
    }

    /**
     * @param list<string> $knownUrls
     * @return array{urls:list<string>,errors:list<string>,program_link_count:int}
     */
    public function candidateUrls(array $knownUrls, Store $store, int $snapshotsToKeep): array
    {
        $urls   = [];
        $errors = [];
        $programLinks = 0;

        foreach ($knownUrls as $url) {
            $urls[$url] = true;
        }

        $programUrl = rtrim($this->baseUrl, '/') . $this->programPath;
        $res = $this->http->get($programUrl, $store->httpCache($programUrl));
        if ($res->ok() && !$res->notModified) {
            $store->saveSnapshot('program', $res->body, $snapshotsToKeep);
            $store->setHttpCache($programUrl, $res->etag, $res->lastModified, null);
            $links = $this->filmLinks($res->body, $programUrl);
            $programLinks = count($links);
            foreach ($links as $link) {
                if ($this->matcher->matchesUrl($link)) {
                    $urls[$link] = true;
                }
            }
        } elseif ($res->notModified) {
            // Unveraendert ist kein Fehler -- aber auch kein Beleg dafuer, dass
            // der Parser noch funktioniert. Die Canary-Pruefung beruecksichtigt das.
            $programLinks = -1;
        } else {
            $errors[] = 'Programmseite: ' . ($res->error ?? 'unbekannter Fehler');
        }

        foreach ($this->sitemapFilmUrls($errors) as $link) {
            if ($this->matcher->matchesUrl($link)) {
                $urls[$link] = true;
            }
        }

        return ['urls' => array_keys($urls), 'errors' => $errors, 'program_link_count' => $programLinks];
    }

    /** @return list<string> alle Links, die nach einer Filmseite dieses Kinos aussehen */
    public function filmLinks(string $html, string $baseUrl): array
    {
        $needle = '/' . trim($this->city, '/') . '/film/';
        $out = [];
        foreach (Dom::links($html, $baseUrl) as $link) {
            if (str_contains($link, $needle)) {
                $out[$link] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * @param list<string> $errors
     * @return list<string>
     */
    private function sitemapFilmUrls(array &$errors): array
    {
        $root = rtrim($this->baseUrl, '/');
        $seen = [];
        $queue = [$root . '/sitemap.xml'];
        $out = [];
        $budget = 5; // Sitemap-Indizes koennen tief sein; wir graben nicht endlos.

        while ($queue !== [] && $budget-- > 0) {
            $url = array_shift($queue);
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;

            $res = $this->http->get($url);
            if (!$res->ok() || $res->body === '') {
                if ($url === $root . '/sitemap.xml') {
                    $errors[] = 'Sitemap: ' . ($res->error ?? 'nicht erreichbar');
                }
                continue;
            }
            if (!preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $res->body, $m)) {
                continue;
            }
            $isIndex = stripos($res->body, '<sitemapindex') !== false;
            foreach ($m[1] as $loc) {
                $loc = html_entity_decode($loc, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($isIndex) {
                    // Nur Teil-Sitemaps verfolgen, die ueberhaupt nach Programm aussehen.
                    if (stripos($loc, 'kinoprogramm') !== false || stripos($loc, 'film') !== false) {
                        $queue[] = $loc;
                    }
                    continue;
                }
                if (str_contains($loc, '/' . trim($this->city, '/') . '/film/')) {
                    $out[$loc] = true;
                }
            }
        }
        return array_keys($out);
    }
}
