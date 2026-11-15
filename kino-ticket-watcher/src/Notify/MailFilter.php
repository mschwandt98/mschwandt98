<?php
declare(strict_types=1);

namespace Kino\Notify;

use Kino\Decide\Event;
use Kino\Model\Status;

/**
 * Entscheidet, welche Ereignisse eines Laufs tatsaechlich gemailt werden.
 *
 * Erkannt und im Zustand protokolliert wird weiterhin alles. Gemailt wird
 * aber nur, was zum Handeln taugt: Vorstellungen in einem der konfigurierten
 * Zustaende (Vorgabe: nur BOOKABLE) und Warnungen ueber Defekte des Watchers.
 *
 * Hintergrund: Ein Film "angekuendigt, noch ohne Termin" flackerte zwischen
 * API und HTML-Rueckfallebene. Sein Verschwinden war stumm, sein
 * Wiederauftauchen galt jedes Mal als "Neu gefunden" -- taeglich eine Mail,
 * mit der man nichts anfangen konnte.
 */
final class MailFilter
{
    /** @var list<Status> */
    private readonly array $statuses;

    /** @param list<string> $statuses Status-Werte wie 'BOOKABLE' */
    public function __construct(array $statuses)
    {
        $out = [];
        foreach ($statuses as $value) {
            $status = Status::tryFrom(strtoupper(trim((string)$value)));
            if ($status !== null) {
                $out[] = $status;
            }
        }
        $this->statuses = $out;
    }

    /**
     * @param list<Event> $events
     * @return list<Event>
     */
    public function filter(array $events): array
    {
        return array_values(array_filter($events, fn (Event $e): bool => $this->shouldMail($e)));
    }

    public function shouldMail(Event $event): bool
    {
        if ($event->screening !== null) {
            return in_array($event->screening->status, $this->statuses, true);
        }
        // Ohne Vorstellung: Warnungen (Defekt, Ausfall, Lebenszeichen,
        // Testmail) gehen raus, reine Info wie "hat stattgefunden" nicht.
        return $event->type === Event::WARNING;
    }
}
