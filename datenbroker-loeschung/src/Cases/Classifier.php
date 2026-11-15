<?php
declare(strict_types=1);

namespace Optout\Cases;

use Optout\Mail\IncomingMessage;

/**
 * Ordnet eine Antwort nach Schlagworten ein -- deutsch und englisch.
 *
 * Grundsatz: Im Zweifel nicht abschliessen. Ein faelschlich als "erledigt"
 * markierter Vorgang faellt niemandem auf; eine faelschlich als "unklar"
 * markierte Antwort kostet dich einen Blick. Deshalb schliessen nur
 * eindeutige Bestaetigungen ohne Gegenanzeichen den Vorgang.
 */
final class Classifier
{
    private const VERIFICATION = [
        '/\b(personal)?ausweis/u',
        '/identit(ät|aet)s?(nachweis|prüfung|pruefung|bestätigung)/u',
        '/(nachweis|bestätigung|prüfung) ihrer identität/u',
        '/verify (your )?identity|identity verification|proof of identity|copy of (your )?(id|passport)/u',
        '/(bestätigen|verifizieren) sie (bitte )?(ihre|die|den|diese)/u',
        '/confirm (your|the|this) (email|e-mail|request|identity)/u',
        '/click (on )?(the|this) (link|button)|klicken sie (bitte )?auf (den|diesen) (link|button)/u',
        '/(web|online|kontakt)-?formular|web ?form|privacy (portal|center|centre|request form)|request form/u',
    ];

    private const REJECTED = [
        '/berechtigte[ns]? interesse/u',
        '/legitimate interests?/u',
        '/(nicht|keine) (ge)?löschung (möglich|vornehmen)|(können|dürfen) (ihre daten |diese |wir )*nicht (ge)?löscht werden|(können|dürfen) wir (ihre daten |diese )?nicht löschen/u',
        '/(cannot|can ?not|unable to|are not able to|not in a position to) (delete|erase|comply|fulfil|fulfill|process)/u',
        '/aufbewahrungspflicht|aufbewahrungsfrist|retention (obligation|period)/u',
        '/art\.? ?17 abs\.? ?3|article 17\(3\)/u',
        '/(lehnen|weisen) (wir )?(ihren|ihre|den|die) (antrag|anfrage|widerspruch)( \w+){0,2} ab/u',
    ];

    private const CONFIRMED = [
        '/(wurden?|haben wir|sind|ist|werden)( \w+){0,6} (gelöscht|entfernt|gesperrt|anonymisiert)/u',
        '/(löschung|sperrung|anonymisierung)( \w+){0,4} (durchgeführt|vorgenommen|erfolgt|veranlasst|umgesetzt|abgeschlossen)/u',
        '/werbesperr(e|liste|datei|vermerk)|robinson/u',
        '/widerspruch( \w+){0,5} (vermerkt|eingetragen|umgesetzt|berücksichtigt|aufgenommen|registriert|gespeichert)/u',
        '/(keine|nicht mehr)( \w+){0,3} (werbung|werbezusendungen|werblich)/u',
        '/(has|have|had) been (permanently )?(deleted|erased|removed|suppressed|anonymi[sz]ed)/u',
        '/(we|i) (have )?(now )?(deleted|erased|removed|suppressed)/u',
        '/(suppression|do[- ]not[- ](contact|mail)|opt[- ]?out) list|opted out|been opted out/u',
        '/(request|deletion|erasure)( \w+){0,2} (has been|was|is) (completed|processed|fulfilled|actioned)/u',
    ];

    private const NO_DATA = [
        '/(keine|nicht)( \w+){0,3} (personenbezogenen )?daten( \w+){0,6} (gespeichert|vorhanden|verarbeitet|gefunden|erfasst|vor)/u',
        '/(liegen|sind) (uns )?keine( \w+){0,3} daten/u',
        '/(nicht|keine) in unserer? (datenbank|datenbestand|bestand|system)/u',
        '/keine (einträge|datensätze|treffer)/u',
        '/(do not|don\'t|did not|didn\'t|could not|couldn\'t|were unable to|was unable to) (hold|have|find|locate|store|process|identify)( any)? (personal )?(data|information|records|match)/u',
        '/no (personal )?(data|information|records|profile|match)( \w+){0,3} (about|on|relating to|associated with|regarding|for|matching) (you|your)/u',
        '/not (found|present|contained|held) in our (database|systems?|records)/u',
    ];

    private const EXTENDED = [
        '/frist( \w+){0,5} verlänger|verlängern( \w+){0,4} frist/u',
        '/zwei weitere monate|um zwei monate/u',
        '/art\.? ?12 abs\.? ?3 s(atz|\.)? ?2/u',
        '/(extend|extension of)( \w+){0,4} (deadline|period|time limit)|(additional|further) two months/u',
    ];

    private const ACK = [
        '/(eingang|erhalt)( \w+){0,3} (bestätig|erhalten)/u',
        '/(haben|hat) (wir )?ihre( \w+){0,2} (anfrage|e-?mail|nachricht|schreiben|anliegen|antrag)( \w+){0,2} erhalten/u',
        '/vorgangsnummer|ticketnummer|ticket-?(nr|nummer)|aktenzeichen/u',
        '/(schnellstmöglich|zeitnah|umgehend|in kürze) (bearbeit|beantwort|melden)/u',
        '/we (have )?received your (request|email|e-mail|message|inquiry|enquiry)/u',
        '/(ticket|case|reference) (number|id|#)/u',
        '/we will (respond|get back|reply|be in touch|process)/u',
        '/thank you for (contacting|your (request|email|e-mail|message|inquiry|enquiry))/u',
    ];

    /** @return array{verdict:Verdict, evidence:string} */
    public static function classify(IncomingMessage $m): array
    {
        if ($m->bounce) {
            return ['verdict' => Verdict::Bounce, 'evidence' => 'Zustellbericht'];
        }

        $text = self::normalize($m->subject . "\n" . self::stripQuoted($m->text));

        $hit = static function (array $patterns) use ($text): ?string {
            foreach ($patterns as $p) {
                if (preg_match($p, $text, $match) === 1) {
                    return $match[0];
                }
            }
            return null;
        };

        $verification = $hit(self::VERIFICATION);
        $rejected     = $hit(self::REJECTED);
        $confirmed    = $hit(self::CONFIRMED);
        $noData       = $hit(self::NO_DATA);
        $extended     = $hit(self::EXTENDED);
        $ack          = $hit(self::ACK);

        // "Keine Daten" enthaelt oft auch "gespeichert"/"geloescht" -- beides
        // schliesst ab, Reihenfolge ist hier nur fuer die Begruendung wichtig.
        return match (true) {
            $verification !== null && $confirmed === null && $noData === null
                => ['verdict' => Verdict::Verification, 'evidence' => $verification],
            $rejected !== null
                => ['verdict' => Verdict::Rejected, 'evidence' => $rejected],
            $noData !== null && $verification === null
                => ['verdict' => Verdict::NoData, 'evidence' => $noData],
            $confirmed !== null && $verification === null
                => ['verdict' => Verdict::Confirmed, 'evidence' => $confirmed],
            $extended !== null
                => ['verdict' => Verdict::Extended, 'evidence' => $extended],
            $verification !== null
                => ['verdict' => Verdict::Unknown, 'evidence' => 'widersprüchlich: ' . $verification],
            $ack !== null || $m->autoReply
                => ['verdict' => Verdict::Acknowledged, 'evidence' => $ack ?? 'Autoantwort'],
            default
                => ['verdict' => Verdict::Unknown, 'evidence' => ''],
        };
    }

    /**
     * Entfernt den zitierten Teil. Der enthaelt unsere eigene Anfrage -- und
     * die steckt voller Woerter wie "Loeschung", "keine" und "Frist".
     */
    public static function stripQuoted(string $text): string
    {
        $lines = explode("\n", str_replace("\r", '', $text));
        $out = [];
        foreach ($lines as $line) {
            $t = trim($line);
            if (preg_match('/^(-{2,}\s*(original|ursprüngliche|urspruengliche|weitergeleitete|forwarded)|_{5,}|am .{4,80}schrieb|on .{4,120}wrote:?$|(von|from):\s.+@|-----\s*nachricht)/iu', $t) === 1) {
                break;
            }
            if (str_starts_with($t, '>')) {
                continue;
            }
            $out[] = $line;
        }
        return implode("\n", $out);
    }

    private static function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        // Umschriebene Umlaute nur in deutschen Wortstaemmen zurueckwandeln --
        // ein pauschales "ue" -> "ü" machte aus "request" ein "reqüst".
        $text = strtr($text, [
            'loesch' => 'lösch', 'pruef' => 'prüf', 'bestaetig' => 'bestätig', 'verlaenger' => 'verlänger',
            'kuerze' => 'kürze', 'moeglich' => 'möglich', 'duerfen' => 'dürfen', 'koennen' => 'können',
            'identitaet' => 'identität', 'eintraege' => 'einträge', 'datensaetze' => 'datensätze',
            'beruecksichtig' => 'berücksichtig', 'durchgefuehrt' => 'durchgeführt', 'fuer' => 'für',
            'urspruenglich' => 'ursprünglich',
        ]);
        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }
}
