<?php
declare(strict_types=1);

namespace Kino\Source;

use Kino\Http\Client;
use Kino\Model\Screening;
use Kino\Model\Status;
use Kino\Parse\Dom;
use Kino\Parse\JsonLd;
use Kino\Parse\Normalize;
use Kino\State\Store;

/**
 * Wertet eine einzelne Filmseite aus und bestimmt ihren Zustand.
 *
 * Reihenfolge der Quellen -- strikt von stabil nach fragil:
 *   1. JSON-LD mit offers.availability  (fuer Google gepflegt, aendert sich selten)
 *   2. Buchungslinks im Markup
 *   3. Textmarker ("ausverkauft", "Vorverkauf startet am ...")
 *
 * Punkt 2 und 3 sind Heuristiken. Sie sind nach dem Discovery-Lauf auf
 * cinemaxx.de zu praezisieren -- bis dahin greifen sie auf Merkmale zurueck,
 * die praktisch jede deutsche Kinoseite hat.
 */
final class FilmPage
{
    /** @param list<string> $bookingPatterns */
    public function __construct(
        private readonly Client $http,
        private readonly array $bookingPatterns,
    ) {
    }

    public function fetch(string $url, Store $store, int $snapshotsToKeep): FetchResult
    {
        $cache = $store->httpCache($url);
        $res   = $this->http->get($url, $cache);

        if ($res->notModified) {
            return new FetchResult([], null, $cache['digest'] ?? null, true);
        }
        if (!$res->ok()) {
            return new FetchResult([], $res->error ?? 'Abruf fehlgeschlagen', null, false);
        }

        $store->saveSnapshot('film-' . basename(parse_url($url, PHP_URL_PATH) ?: 'page'), $res->body, $snapshotsToKeep);
        $digest = Normalize::digest($res->body);
        $store->setHttpCache($url, $res->etag, $res->lastModified, $digest);

        return new FetchResult($this->parse($res->body, $url), null, $digest, false);
    }

    /** @return list<Screening> */
    public function parse(string $html, string $url): array
    {
        $text  = Normalize::visibleText($html);
        $title = Dom::heading($html) ?? Dom::title($html) ?? basename(parse_url($url, PHP_URL_PATH) ?: 'unbekannt');

        $fromJsonLd = $this->parseJsonLd($html, $url);
        if ($fromJsonLd !== []) {
            return $fromJsonLd;
        }

        $presale   = Dom::presaleStart($text);
        $dates     = $this->screeningDates($text, $presale);
        $hasBooking = $this->hasBookingLink($html, $url);

        $status = match (true) {
            Dom::looksSoldOut($text)             => Status::SoldOut,
            $hasBooking && !Dom::looksPending($text) => Status::Bookable,
            $dates !== [] || $presale !== null   => Status::Dated,
            default                              => Status::PageExists,
        };

        return [new Screening(
            title:           $title,
            url:             $url,
            status:          $status,
            startsAt:        $dates[0] ?? null,
            presaleStartsAt: $presale,
            bookingUrl:      $hasBooking ? $this->firstBookingLink($html, $url) : null,
            source:          'film_page',
        )];
    }

    /**
     * Datumsangaben, die als Vorstellungstermin in Frage kommen.
     *
     * Auf einer Filmseite steht typischerweise mehr als ein Datum: der Termin
     * selbst und der Vorverkaufsstart. Das frueheste Datum ist dabei fast immer
     * der Vorverkauf -- wuerde man es blind nehmen, stuende in Betreff und
     * Fingerprint der falsche Tag.
     *
     * @return list<string>
     */
    private function screeningDates(string $text, ?string $presale): array
    {
        $dates = Dom::dates($text);
        if ($presale === null) {
            return $dates;
        }
        $presaleDay = substr($presale, 0, 10);
        $withoutPresale = array_values(array_filter($dates, static fn ($d) => $d !== $presaleDay));
        // Faellt der Vorverkauf ausnahmsweise auf den Vorstellungstag, ist die
        // gefilterte Liste leer -- dann ist das Datum eben doch das richtige.
        return $withoutPresale !== [] ? $withoutPresale : $dates;
    }

    /** @return list<Screening> */
    private function parseJsonLd(string $html, string $url): array
    {
        $out = [];
        foreach (JsonLd::extract($html) as $node) {
            if (!JsonLd::isEvent($node)) {
                continue;
            }
            $availability = JsonLd::availability($node);
            $name  = JsonLd::string($node, 'name');
            $start = JsonLd::string($node, 'startDate');
            if ($name === null && $start === null) {
                continue;
            }
            $status = match ($availability) {
                'in_stock' => Status::Bookable,
                'preorder' => Status::Dated,
                'sold_out' => Status::SoldOut,
                default    => $start !== null ? Status::Dated : Status::PageExists,
            };
            $out[] = new Screening(
                title:      $name ?? (Dom::heading($html) ?? $url),
                url:        JsonLd::string($node, 'url') ?? $url,
                status:     $status,
                startsAt:   $start,
                bookingUrl: $this->offerUrl($node),
                source:     'json_ld',
            );
        }
        return $out;
    }

    private function offerUrl(array $node): ?string
    {
        $offers = $node['offers'] ?? null;
        if ($offers === null) {
            return null;
        }
        $offers = array_is_list($offers) ? $offers : [$offers];
        foreach ($offers as $offer) {
            if (is_array($offer) && isset($offer['url']) && is_string($offer['url'])) {
                return $offer['url'];
            }
        }
        return null;
    }

    private function hasBookingLink(string $html, string $url): bool
    {
        return $this->firstBookingLink($html, $url) !== null;
    }

    private function firstBookingLink(string $html, string $url): ?string
    {
        foreach (Dom::links($html, $url) as $link) {
            foreach ($this->bookingPatterns as $pattern) {
                if (@preg_match($pattern, $link) === 1) {
                    return $link;
                }
            }
        }
        return null;
    }
}
