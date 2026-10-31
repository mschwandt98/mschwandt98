<?php
declare(strict_types=1);

namespace Optout\Letters;

/** Art der Anfrage. */
enum Kind: string
{
    /** Loeschung (Art. 17) plus Widerspruch gegen Werbung und Profiling (Art. 21). */
    case Erasure = 'erasure';
    /** Nur Widerspruch gegen Direktwerbung (Art. 21 Abs. 2). */
    case Objection = 'objection';
    /** Selbstauskunft (Art. 15) -- bei Auskunfteien sinnvoller als Loeschung. */
    case Access = 'access';

    public function article(): string
    {
        return match ($this) {
            self::Erasure   => '17',
            self::Objection => '21',
            self::Access    => '15',
        };
    }

    public function subject(string $language): string
    {
        return $language === 'de'
            ? match ($this) {
                self::Erasure   => 'Antrag auf Löschung nach Art. 17 DSGVO und Widerspruch nach Art. 21 DSGVO',
                self::Objection => 'Widerspruch gegen Direktwerbung nach Art. 21 Abs. 2 DSGVO',
                self::Access    => 'Auskunftsersuchen nach Art. 15 DSGVO',
            }
            : match ($this) {
                self::Erasure   => 'Request for erasure according to Art. 17 GDPR and objection according to Art. 21 GDPR',
                self::Objection => 'Objection to direct marketing according to Art. 21(2) GDPR',
                self::Access    => 'Access request according to Art. 15 GDPR',
            };
    }

    public function label(): string
    {
        return match ($this) {
            self::Erasure   => 'Löschung',
            self::Objection => 'Widerspruch',
            self::Access    => 'Auskunft',
        };
    }
}
