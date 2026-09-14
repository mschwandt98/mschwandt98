<?php
declare(strict_types=1);

namespace Kino\Decide;

use Kino\Model\Screening;
use Kino\Model\Status;

/**
 * Vergleicht den vorherigen Zustand mit dem aktuellen und erzeugt daraus
 * Ereignisse. Gemailt wird ausschliesslich bei Uebergaengen -- sonst kaeme
 * bei minuetlichem Takt jede Minute eine Mail.
 */
final class Transitions
{
    /** @param list<int> $repeatAfter */
    public function __construct(private readonly array $repeatAfter)
    {
    }

    /**
     * @param array<string,array<string,mixed>> $previous  Fingerprint => Rohdaten
     * @param list<Screening>                   $current
     * @param array<string,int>                 $lastNotified Fingerprint => Zeitstempel
     * @return list<Event>
     */
    public function detect(array $previous, array $current, array $lastNotified = [], ?int $now = null): array
    {
        $now ??= time();
        $events = [];

        foreach ($current as $screening) {
            $fp   = $screening->fingerprint();
            $prev = $previous[$fp] ?? null;

            if ($prev === null) {
                if ($screening->status->isNotifiable()) {
                    $events[] = new Event(
                        Event::NEW_MATCH,
                        sprintf('Neu gefunden: %s (%s)', $screening->title, $screening->status->label()),
                        $screening,
                    );
                }
                continue;
            }

            $prevStatus = Status::tryFrom((string)($prev['status'] ?? '')) ?? Status::Absent;
            if ($prevStatus !== $screening->status) {
                $events[] = new Event(
                    Event::STATUS_CHANGED,
                    sprintf(
                        '%s: %s -> %s',
                        $screening->title,
                        $prevStatus->label(),
                        $screening->status->label()
                    ),
                    $screening,
                    $prevStatus->value,
                );
                continue;
            }

            // Zustand unveraendert: Nur der Hauptalarm wird wiederholt, und
            // auch der nur zweimal. Danach ist Ruhe -- wer bis dahin nicht
            // reagiert hat, dem hilft die dritte Mail auch nicht.
            if ($screening->status === Status::Bookable) {
                $since = $lastNotified[$fp] ?? null;
                if ($since !== null && $this->reminderDue($since, $now)) {
                    $events[] = new Event(
                        Event::REMINDER,
                        sprintf('Erinnerung: %s ist weiterhin buchbar', $screening->title),
                        $screening,
                    );
                }
            }
        }

        // Verschwundene Treffer sind eine Warnung wert: Entweder wurde die
        // Vorstellung abgesagt, oder unser Parser findet sie nicht mehr.
        $currentFps = array_map(static fn (Screening $s) => $s->fingerprint(), $current);
        foreach ($previous as $fp => $row) {
            $status = Status::tryFrom((string)($row['status'] ?? '')) ?? Status::Absent;
            if (!in_array($fp, $currentFps, true) && $status->rank() >= Status::Dated->rank()) {
                $events[] = new Event(
                    Event::WARNING,
                    sprintf(
                        'Vorstellung "%s" ist aus dem Programm verschwunden (war: %s). Abgesagt -- oder der Parser greift nicht mehr.',
                        (string)($row['title'] ?? $fp),
                        $status->label()
                    ),
                    details: ['fingerprint' => $fp, 'previous' => $row],
                );
            }
        }

        return $events;
    }

    private function reminderDue(int $lastNotifiedAt, int $now): bool
    {
        $elapsed = $now - $lastNotifiedAt;
        foreach ($this->repeatAfter as $after) {
            if ($elapsed >= $after && $elapsed < $after + 3600) {
                return true;
            }
        }
        return false;
    }
}
