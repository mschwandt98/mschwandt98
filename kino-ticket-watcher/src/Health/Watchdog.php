<?php
declare(strict_types=1);

namespace Kino\Health;

use Kino\Decide\Event;
use Kino\State\Store;

/**
 * Erkennt die Fehlerfaelle, die den Watcher wertlos machen, ohne dass er
 * abstuerzt: Er laeuft, findet aber nichts mehr.
 *
 * Ein Watcher, auf den man sich verlaesst und der seit drei Wochen
 * stillschweigend nichts findet, ist schlimmer als gar keiner.
 */
final class Watchdog
{
    public function __construct(private readonly array $cfg)
    {
    }

    /**
     * Canary: Meldet die Quelle fuer das gesamte Kino null Filme, ist das ein
     * Auswertungsfehler und kein leeres Ergebnis -- ein CinemaxX-Multiplex hat
     * immer Programm. Diese eine Regel faengt die Mehrzahl aller Aenderungen
     * an API oder Seitenstruktur ab.
     *
     * Ein negativer Wert bedeutet "unveraendert (304)" -- daraus laesst sich
     * nichts ableiten, also auch kein Alarm.
     */
    public function checkCanary(int $filmCount, Store $store): ?Event
    {
        if ($filmCount !== 0) {
            if ($filmCount > 0) {
                $store->setMeta('last_film_count', $filmCount);
            }
            return null;
        }
        $previously = (int)$store->meta('last_film_count', 0);
        if ($previously === 0) {
            return null; // noch nie etwas gefunden -> erster Lauf, kein Alarm
        }
        return new Event(
            Event::WARNING,
            'Die Quelle meldet fuer das gesamte Kino keinen einzigen Film mehr '
            . "(zuletzt waren es $previously). Das deutet auf eine Aenderung der API "
            . 'oder der Seitenstruktur hin -- bitte nachsehen.',
            details: ['previous_film_count' => $previously],
        );
    }

    /**
     * Struktur-Hash: Warnt, wenn sich der sichtbare Inhalt einer beobachteten
     * Seite wesentlich aendert, ohne dass der Klassifikator etwas erkannt hat.
     * Das ist das Sicherheitsnetz -- lieber eine Mail zu viel als eine zu wenig.
     */
    public function checkContentDiff(string $url, ?string $previousDigest, ?string $currentDigest): ?Event
    {
        if ($previousDigest === null || $currentDigest === null || $previousDigest === $currentDigest) {
            return null;
        }
        return new Event(
            Event::CONTENT_DIFF,
            'Inhalt der beobachteten Seite hat sich geaendert, ohne dass ein Zustandswechsel erkannt wurde. '
            . 'Bitte einmal selbst nachsehen: ' . $url,
            details: ['url' => $url],
        );
    }

    /**
     * Meldet, wenn der externe Taktgeber stumm geworden ist. Ohne diese
     * Pruefung faellt sein Ausfall erst auf, wenn der Marathon vorbei ist.
     */
    public function checkTickSilence(Store $store, bool $hotSeason, ?int $now = null): ?Event
    {
        $now ??= time();
        $lastTick = $store->meta('last_tick_at');
        if (!$hotSeason || !is_string($lastTick)) {
            return null;
        }
        $ts = strtotime($lastTick);
        $limit = (int)$this->cfg['tick_silence_alert'];
        if ($ts === false || ($now - $ts) < $limit) {
            return null;
        }
        $hours = round(($now - $ts) / 3600, 1);
        return new Event(
            Event::WARNING,
            "Der externe Taktgeber hat sich seit $hours Stunden nicht gemeldet. "
            . 'Es wird derzeit nur alle 6 Stunden geprueft. Bitte cron-job.org pruefen.',
            details: ['last_tick_at' => $lastTick],
        );
    }

    /**
     * Woechentliches Lebenszeichen in der heissen Phase. Nervt minimal,
     * beweist aber, dass die gesamte Kette inklusive SMTP-Zustellung
     * funktioniert -- genau der Teil, der 11 Monate ungetestet bleibt.
     */
    public function heartbeatDue(Store $store, ?int $now = null): bool
    {
        $now ??= time();
        if ((int)date('n', $now) < (int)$this->cfg['heartbeat_from_month']) {
            return false;
        }
        if ((int)date('N', $now) !== (int)$this->cfg['heartbeat_weekday']) {
            return false;
        }
        $last = $store->meta('last_heartbeat_at');
        if (!is_string($last)) {
            return true;
        }
        $ts = strtotime($last);
        return $ts === false || ($now - $ts) > 6 * 86400;
    }
}
