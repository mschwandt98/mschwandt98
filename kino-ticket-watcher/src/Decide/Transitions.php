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
    /** @var list<int> */
    private readonly array $repeatAfter;

    /**
     * @param list<int> $repeatAfter Abstaende der Erinnerungen, gemessen ab der
     *        ERSTEN Meldung. Eine leere Liste schaltet Erinnerungen ganz ab.
     */
    public function __construct(array $repeatAfter)
    {
        $this->repeatAfter = array_values(array_map('intval', $repeatAfter));
    }

    /**
     * @param array<string,array<string,mixed>> $previous  Fingerprint => Rohdaten
     * @param list<Screening>                   $current
     * @param array<string,mixed>               $notified  Fingerprint => Meldungsstand
     *        je Eintrag {at:int, status:string, reminders:int}; ein blosser
     *        Zeitstempel wird noch verstanden (altes Format).
     * @return list<Event>
     */
    public function detect(array $previous, array $current, array $notified = [], ?int $now = null): array
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
                $record = self::normalizeRecord($notified[$fp] ?? null);
                if ($record !== null && $this->reminderDue($record, $now)) {
                    $number = $record['reminders'] + 1;
                    $events[] = new Event(
                        Event::REMINDER,
                        sprintf(
                            'Erinnerung %d von %d: %s ist weiterhin buchbar. Danach ist Ruhe.',
                            $number,
                            count($this->repeatAfter),
                            $screening->title
                        ),
                        $screening,
                    );
                }
            }
        }

        // Verschwundene Treffer sind eine Warnung wert: Entweder wurde die
        // Vorstellung abgesagt, oder unser Parser findet sie nicht mehr.
        //
        // Nicht aber, wenn der Termin schon vorbei ist: Dann hat die
        // Vorstellung einfach stattgefunden, und das Kino nimmt sie aus dem
        // Programm. Ohne diese Ausnahme kaeme nach jedem Marathon eine
        // Warnmail, die nach einem Defekt klingt.
        $currentFps = array_map(static fn (Screening $s) => $s->fingerprint(), $current);
        foreach ($previous as $fp => $row) {
            $status = Status::tryFrom((string)($row['status'] ?? '')) ?? Status::Absent;
            if (in_array($fp, $currentFps, true) || $status->rank() < Status::Dated->rank()) {
                continue;
            }
            if (self::alreadyTookPlace($row['starts_at'] ?? null, $now)) {
                $events[] = new Event(
                    Event::STATUS_CHANGED,
                    sprintf(
                        'Vorstellung "%s" hat stattgefunden und ist aus dem Programm genommen.',
                        (string)($row['title'] ?? $fp)
                    ),
                    previousStatus: $status->value,
                    details: ['fingerprint' => $fp],
                );
                continue;
            }
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

        return $events;
    }

    /**
     * Liegt der Termin in der Vergangenheit? Mit etwas Puffer, damit eine
     * laufende Vorstellung nicht schon als vorbei gilt -- das Triple dauert
     * ueber zwoelf Stunden.
     */
    private static function alreadyTookPlace(mixed $startsAt, int $now): bool
    {
        if (!is_string($startsAt) || $startsAt === '') {
            return false; // ohne Termin lieber warnen als schweigen
        }
        $ts = strtotime($startsAt);
        return $ts !== false && $ts < ($now - 24 * 3600);
    }

    /**
     * Ist die naechste Erinnerung faellig?
     *
     * Entscheidend ist, dass ab der ERSTEN Meldung gerechnet wird und mitgezaehlt
     * wird, wie viele Erinnerungen schon raus sind. Zuvor wurde gegen den
     * Zeitpunkt der letzten Mail gerechnet -- den eine Erinnerung selbst neu
     * setzt. Dadurch begann die Uhr nach jeder Erinnerung von vorn, und es kam
     * endlos alle sechs Stunden eine weitere, statt zweimal insgesamt.
     *
     * @param array{at:int,status:string,reminders:int} $record
     */
    private function reminderDue(array $record, int $now): bool
    {
        $sent = $record['reminders'];
        if ($sent >= count($this->repeatAfter)) {
            return false; // Soll erfuellt -- ab jetzt Ruhe
        }
        if ($record['at'] <= 0) {
            return false;
        }
        return ($now - $record['at']) >= $this->repeatAfter[$sent];
    }

    /**
     * Versteht beide Formate: das neue Datensatz-Format und einen blossen
     * Zeitstempel aus einem Zustand, der vor dieser Aenderung geschrieben wurde.
     *
     * @return array{at:int,status:string,reminders:int}|null
     */
    public static function normalizeRecord(mixed $value): ?array
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return ['at' => (int)$value, 'status' => '', 'reminders' => 0];
        }
        if (!is_array($value)) {
            return null;
        }
        return [
            'at'        => (int)($value['at'] ?? 0),
            'status'    => (string)($value['status'] ?? ''),
            'reminders' => (int)($value['reminders'] ?? 0),
        ];
    }
}
