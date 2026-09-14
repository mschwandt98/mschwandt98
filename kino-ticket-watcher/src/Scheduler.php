<?php
declare(strict_types=1);

namespace Kino;

use Kino\Model\Status;

/**
 * Entscheidet, wann der naechste echte Prueflauf faellig ist.
 *
 * Der externe Taktgeber klopft jede Minute an; die allermeisten dieser Ticks
 * enden hier als No-op. Dadurch sieht cinemaxx.de nie mehr Last als in der
 * Tabelle unten -- unabhaengig davon, wie oft getickt wird.
 */
final class Scheduler
{
    public function __construct(private readonly array $cfg)
    {
    }

    public function isDue(?string $nextCheckAt, ?int $now = null): bool
    {
        if ($nextCheckAt === null) {
            return true;
        }
        $ts = strtotime($nextCheckAt);
        return $ts === false || $ts <= ($now ?? time());
    }

    /**
     * @param Status $status hoechster erreichter Zustand ueber alle Treffer
     * @return int Sekunden bis zum naechsten Prueflauf
     */
    public function interval(Status $status, ?string $presaleStartsAt, int $consecutiveErrors, ?int $now = null): int
    {
        $now ??= time();

        if ($consecutiveErrors > 0) {
            $backoff = $this->cfg['error_backoff'];
            return (int)$backoff[min($consecutiveErrors - 1, count($backoff) - 1)];
        }

        // Ein bekanntes Vorverkaufsdatum schlaegt alles andere: Kurz davor
        // wollen wir bei jedem Tick pruefen. Das ist der Mechanismus, der
        // ueber Erfolg oder Misserfolg des ganzen Projekts entscheidet.
        if ($presaleStartsAt !== null && $status !== Status::Bookable && $status !== Status::SoldOut) {
            $ts = strtotime($presaleStartsAt);
            if ($ts !== false && $ts > $now) {
                return $ts - $now <= (int)$this->cfg['presale_near_window']
                    ? (int)$this->cfg['presale_near_seconds']
                    : (int)$this->cfg['presale_far_seconds'];
            }
        }

        return match ($status) {
            Status::Bookable   => (int)$this->cfg['bookable_seconds'],
            Status::SoldOut    => (int)$this->cfg['sold_out_seconds'],
            Status::Dated      => (int)$this->cfg['dated_seconds'],
            Status::PageExists => (int)$this->cfg['page_exists_seconds'],
            Status::Absent     => $this->isHotSeason($now)
                ? (int)$this->cfg['hot_absent_seconds']
                : (int)$this->cfg['quiet_season_seconds'],
        };
    }

    /** Ab September wird schaerfer getaktet -- da faellt der Marathon typischerweise an. */
    public function isHotSeason(?int $now = null): bool
    {
        $month = (int)date('n', $now ?? time());
        $from  = (int)$this->cfg['hot_season_month'];
        // Saison laeuft ueber den Jahreswechsel bis Februar.
        return $month >= $from || $month <= 2;
    }

    public function nextCheckAt(Status $status, ?string $presaleStartsAt, int $consecutiveErrors, ?int $now = null): string
    {
        $now ??= time();
        $interval = $this->interval($status, $presaleStartsAt, $consecutiveErrors, $now);
        $jitter   = random_int(0, max(0, (int)$this->cfg['jitter_seconds']));
        return date('c', $now + $interval + $jitter);
    }
}
