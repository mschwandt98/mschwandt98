<?php
declare(strict_types=1);

namespace Optout\Cases;

enum Status: string
{
    case Queued    = 'queued';     // wartet auf den Versand
    case Waiting   = 'waiting';    // verschickt, Frist laeuft
    case Reminded  = 'reminded';   // Erinnerung verschickt, Nachfrist laeuft
    case Attention = 'attention';  // du bist dran (Grund steht in 'attention')
    case Done      = 'done';       // erledigt, bis zur naechsten Runde
    case Ignored   = 'ignored';    // von dir ausgeschlossen

    public function label(): string
    {
        return match ($this) {
            self::Queued    => 'wartet auf Versand',
            self::Waiting   => 'Frist läuft',
            self::Reminded  => 'erinnert',
            self::Attention => 'du bist dran',
            self::Done      => 'erledigt',
            self::Ignored   => 'ignoriert',
        };
    }

    /** Laeuft der Vorgang noch, d. h. ist eine Antwort zu erwarten? */
    public function open(): bool
    {
        return in_array($this, [self::Waiting, self::Reminded, self::Attention], true);
    }
}
