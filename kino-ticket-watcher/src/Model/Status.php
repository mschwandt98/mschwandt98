<?php
declare(strict_types=1);

namespace Kino\Model;

/**
 * Zustaende einer beobachteten Vorstellung. Die Reihenfolge der Cases ist
 * zugleich die Eskalationsstufe -- rank() nutzt das, um zu entscheiden, ob ein
 * Uebergang eine Verbesserung (naeher am Buchen) oder eine Verschlechterung ist.
 */
enum Status: string
{
    case Absent     = 'ABSENT';       // kein passender Film im Programm
    case PageExists = 'PAGE_EXISTS';  // Filmseite da, aber keine Vorstellung
    case Dated      = 'DATED';        // Termin sichtbar, noch nicht buchbar
    case Bookable   = 'BOOKABLE';     // Buchung offen -- der Hauptalarm
    case SoldOut    = 'SOLD_OUT';     // ausverkauft

    public function rank(): int
    {
        return match ($this) {
            self::Absent     => 0,
            self::PageExists => 1,
            self::Dated      => 2,
            self::Bookable   => 3,
            self::SoldOut    => 4,
        };
    }

    /** Loest ein Erreichen dieses Zustands eine Mail aus? */
    public function isNotifiable(): bool
    {
        return $this !== self::Absent;
    }

    public function label(): string
    {
        return match ($this) {
            self::Absent     => 'nicht im Programm',
            self::PageExists => 'angekuendigt, noch ohne Termin',
            self::Dated      => 'Termin steht, noch nicht buchbar',
            self::Bookable   => 'JETZT BUCHBAR',
            self::SoldOut    => 'ausverkauft',
        };
    }
}
