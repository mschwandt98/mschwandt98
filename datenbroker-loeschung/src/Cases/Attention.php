<?php
declare(strict_types=1);

namespace Optout\Cases;

/** Warum ein Vorgang auf dich wartet. */
enum Attention: string
{
    case Verification = 'verification';
    case Rejected     = 'rejected';
    case Bounce       = 'bounce';
    case Unknown      = 'unknown';
    case Escalation   = 'escalation';
    case Manual       = 'manual';
    case SendFailed   = 'send_failed';

    public static function fromVerdict(Verdict $v): self
    {
        return match ($v) {
            Verdict::Verification => self::Verification,
            Verdict::Rejected     => self::Rejected,
            Verdict::Bounce       => self::Bounce,
            default               => self::Unknown,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Verification => 'verlangt Identitätsnachweis, Bestätigungslink oder Formular',
            self::Rejected     => 'hat ganz oder teilweise abgelehnt',
            self::Bounce       => 'Adresse unzustellbar',
            self::Unknown      => 'Antwort nicht eindeutig, bitte lesen',
            self::Escalation   => 'auch nach Erinnerung keine Antwort, Beschwerde-Entwurf liegt bereit',
            self::Manual       => 'nur per Webformular oder Brief erreichbar',
            self::SendFailed   => 'Versand wiederholt fehlgeschlagen',
        };
    }

    public function advice(): string
    {
        return match ($this) {
            self::Verification => 'Antwort lesen. Einen Bestätigungslink kannst du anklicken. Eine Ausweiskopie musst du in der Regel nicht schicken, denn Name und Anschrift reichen zur Zuordnung (Art. 12 Abs. 6 DSGVO verlangt begründete Zweifel). Danach „wartet wieder“ wählen.',
            self::Rejected     => 'Begründung lesen. Bei Auskunfteien ist das üblich. Bei Adresshändlern gilt der Widerspruch gegen Werbung (Art. 21 Abs. 2) ohne Ausnahme: Hier lohnt sich eine Beschwerde.',
            self::Bounce       => 'Adresse ist veraltet. bin/sync-db.php ausführen und dann „erneut senden“, oder die Firma in brokers.exclude eintragen.',
            self::Unknown      => 'Antwort lesen und den Vorgang als „erledigt“ oder „wartet wieder“ markieren.',
            self::Escalation   => 'Beschwerde-Entwurf prüfen und an die Aufsichtsbehörde schicken, dann „erledigt“ wählen.',
            self::Manual       => 'Brieftext über das Formular der Firma abschicken oder ausdrucken, dann „wartet wieder“ wählen.',
            self::SendFailed   => 'SMTP-Fehler prüfen (bin/selftest.php), dann „erneut senden“.',
        };
    }
}
