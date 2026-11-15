<?php
declare(strict_types=1);

namespace Optout\Cases;

/** Einordnung einer Antwort. */
enum Verdict: string
{
    case Confirmed    = 'confirmed';     // geloescht / gesperrt / Widerspruch vermerkt
    case NoData       = 'no_data';       // keine Daten gespeichert
    case Acknowledged = 'acknowledged';  // Eingangsbestaetigung, Autoantwort
    case Extended     = 'extended';      // Frist nach Art. 12 Abs. 3 S. 2 verlaengert
    case Verification = 'verification';  // Identitaetsnachweis, Link, Webformular verlangt
    case Rejected     = 'rejected';      // abgelehnt, ganz oder teilweise
    case Bounce       = 'bounce';        // unzustellbar
    case Unknown      = 'unknown';       // nicht eindeutig -- Mensch muss lesen

    /** Schliesst die Antwort den Vorgang ohne dein Zutun ab? */
    public function closes(): bool
    {
        return $this === self::Confirmed || $this === self::NoData;
    }

    /** Musst du dir die Antwort ansehen? */
    public function needsUser(): bool
    {
        return in_array($this, [self::Verification, self::Rejected, self::Bounce, self::Unknown], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Confirmed    => 'bestätigt',
            self::NoData       => 'keine Daten',
            self::Acknowledged => 'Eingangsbestätigung',
            self::Extended     => 'Frist verlängert',
            self::Verification => 'Nachweis verlangt',
            self::Rejected     => 'abgelehnt',
            self::Bounce       => 'unzustellbar',
            self::Unknown      => 'unklar',
        };
    }
}
