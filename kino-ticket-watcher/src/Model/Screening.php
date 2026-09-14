<?php
declare(strict_types=1);

namespace Kino\Model;

/**
 * Eine erkannte Vorstellung (bzw. ein erkannter Film-Eintrag, solange noch
 * kein Termin bekannt ist). Unveraenderlich -- der Vergleich alt/neu in
 * Decide\Transitions verlaesst sich darauf, dass niemand nachtraeglich
 * hineinschreibt.
 */
final readonly class Screening
{
    public function __construct(
        public string $title,
        public string $url,
        public Status $status,
        public ?string $startsAt = null,      // ISO-8601, null solange unbekannt
        public ?string $presaleStartsAt = null, // ISO-8601, aus "Vorverkauf ab ..."
        public ?string $bookingUrl = null,
        public string $source = 'unknown',
    ) {
    }

    /**
     * Stabiler Schluessel ueber Titel und Startzeit. Bewusst NICHT ueber die
     * URL: Slugs aendern sich, und kosmetische Titelzusaetze wie "(OV)" oder
     * "Extended Edition" wuerden sonst jedes Mal eine "neue" Vorstellung
     * erzeugen und einen Fehlalarm ausloesen.
     */
    public function fingerprint(): string
    {
        $key = \Kino\Parse\Normalize::forFingerprint($this->title) . '|' . $this->startDate();
        return substr(sha1($key), 0, 16);
    }

    /**
     * Kalendertag des Starts, oder 'nodate'.
     *
     * Bewusst nur der Tag, nicht die Uhrzeit: Dieselbe Vorstellung erreicht uns
     * je nach Quelle als "2026-12-13" (aus dem Seitentext) oder als
     * "2026-12-13T11:00:00+01:00" (aus JSON-LD). Ginge die Uhrzeit in den
     * Fingerprint ein, wuerde ein Quellenwechsel -- etwa wenn CinemaxX zum
     * Vorverkaufsstart JSON-LD ausliefert -- dieselbe Vorstellung als neue
     * gelten lassen. Der Uebergang "-> buchbar" ginge dabei verloren.
     */
    public function startDate(): string
    {
        if ($this->startsAt === null) {
            return 'nodate';
        }
        $ts = strtotime($this->startsAt);
        return $ts === false ? $this->startsAt : date('Y-m-d', $ts);
    }

    public function withStatus(Status $status): self
    {
        return new self(
            $this->title,
            $this->url,
            $status,
            $this->startsAt,
            $this->presaleStartsAt,
            $this->bookingUrl,
            $this->source,
        );
    }

    /** Menschenlesbarer Termin fuer Betreffzeile und Mailtext. */
    public function when(): string
    {
        if ($this->startsAt === null) {
            return 'Termin offen';
        }
        $ts = strtotime($this->startsAt);
        return $ts === false ? $this->startsAt : date('d.m.Y H:i', $ts);
    }

    public function toArray(): array
    {
        return [
            'title'            => $this->title,
            'url'              => $this->url,
            'status'           => $this->status->value,
            'starts_at'        => $this->startsAt,
            'presale_starts_at'=> $this->presaleStartsAt,
            'booking_url'      => $this->bookingUrl,
            'source'           => $this->source,
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self(
            (string)($a['title'] ?? ''),
            (string)($a['url'] ?? ''),
            Status::tryFrom((string)($a['status'] ?? '')) ?? Status::Absent,
            $a['starts_at'] ?? null,
            $a['presale_starts_at'] ?? null,
            $a['booking_url'] ?? null,
            (string)($a['source'] ?? 'unknown'),
        );
    }
}
