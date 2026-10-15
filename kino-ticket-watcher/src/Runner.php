<?php
declare(strict_types=1);

namespace Kino;

use Kino\Decide\Event;
use Kino\Decide\Transitions;
use Kino\Health\Watchdog;
use Kino\Http\Client;
use Kino\Match\TitleMatcher;
use Kino\Model\Screening;
use Kino\Model\Status;
use Kino\Notify\Mailer;
use Kino\Notify\Templates;
use Kino\Parse\NextData;
use Kino\Source\CinemaxxApi;
use Kino\Source\Discovery;
use Kino\Source\FilmPage;
use Kino\State\Store;

/**
 * Orchestriert einen einzelnen Prueflauf. Beide Entrypoints (CLI-Cron und
 * HTTP-Tick) landen hier -- die Prueflogik existiert genau einmal.
 *
 * Quellenreihenfolge:
 *   1. Showings-API des Kinos -- verbindlich, ein Request, keine Interpretation
 *   2. HTML-Rueckfallebene ueber Programmseite und Sitemap, falls die API
 *      ausfaellt oder nichts Passendes meldet
 */
final class Runner
{
    public function __construct(
        private readonly Config $config,
        private readonly Store $store,
        private readonly bool $dryRun = false,
        private readonly bool $verbose = false,
    ) {
    }

    /**
     * @return array{ran:bool,reason:string,events:list<Event>,screenings:list<Screening>,errors:list<string>}
     */
    public function run(bool $ignoreSchedule = false, bool $forceNotify = false): array
    {
        $scheduler = new Scheduler($this->config->get('schedule'));
        $watchdog  = new Watchdog($this->config->get('notify'));
        $now       = time();

        $this->store->setMeta('last_tick_at', date('c', $now));

        if (!$ignoreSchedule && !$scheduler->isDue($this->store->meta('next_check_at'), $now)) {
            $this->save();
            return [
                'ran' => false,
                'reason' => 'noch nicht faellig (naechste Pruefung ' . (string)$this->store->meta('next_check_at') . ')',
                'events' => [], 'screenings' => [], 'errors' => [],
            ];
        }

        $http    = $this->httpClient();
        $matcher = $this->matcher();
        $keep    = (int)$this->config->get('snapshots_to_keep');
        $errors  = [];

        $cinemaId = $this->cinemaId($http, $errors);
        if ($cinemaId === null) {
            $errors[] = 'Kino-Kennung unbekannt -- weder konfiguriert noch aus der Programmseite lesbar.';
        }

        $screenings   = [];
        $filmCount    = 0;
        $haveFreshData = false;
        $usedFallback = false;

        // --- Quelle 1: Showings-API ------------------------------------------
        if ($cinemaId !== null) {
            if ((bool)$this->config->get('http.warmup', false)) {
                // Einmal die Programmseite laden, damit deren Cookies im
                // Client liegen, bevor die API gefragt wird.
                $http->warmup($this->programUrl());
            }
            $api = new CinemaxxApi(
                $http,
                (string)$this->config->get('cinema.base_url'),
                $this->apiHeaders(),
            );
            $result = $api->fetch($cinemaId, $this->store, $keep);

            if ($result['notModified']) {
                // 304: Es gibt keine neuen Daten. Jetzt zu vergleichen hiesse,
                // alle bekannten Vorstellungen als verschwunden zu melden.
                $this->log('API meldet unveraendert (304)');
            } elseif ($result['error'] !== null) {
                $errors[] = 'API: ' . $result['error'];
            } else {
                $haveFreshData = true;
                $filmCount = $result['films'];
                $screenings = $this->filter($result['screenings'], $matcher);
                $this->log(sprintf('API: %d Film(e) im Kino, %d Treffer', $filmCount, count($screenings)));
            }
        }

        // --- Quelle 2: HTML-Rueckfallebene -----------------------------------
        // Nur wenn die API ausgefallen ist oder nichts Passendes gemeldet hat --
        // und dann gedrosselt, damit der Minutentakt sie nicht vervielfacht.
        $apiFailed = $cinemaId === null || $errors !== [];
        if ($apiFailed || ($haveFreshData && $screenings === [] && $this->crosscheckDue($now))) {
            $usedFallback = true;
            $this->store->setMeta('last_html_crosscheck_at', date('c', $now));
            [$fallback, $fallbackErrors, $links] = $this->htmlFallback($http, $matcher, $keep);
            $errors = array_merge($errors, $fallbackErrors);
            if ($fallback !== [] || $links > 0) {
                $haveFreshData = true;
                $filmCount = max($filmCount, $links);
                $screenings = $this->dedupe(array_merge($screenings, $fallback));
                $this->log(sprintf('HTML-Rueckfallebene: %d Filmlink(s), %d Treffer', $links, count($fallback)));
            }
        }

        // --- Vergleich und Ereignisse ----------------------------------------
        $events = [];
        if ($haveFreshData) {
            $transitions  = new Transitions((array)$this->config->get('notify.bookable_repeat_after'));
            $lastNotified = (array)$this->store->meta('notified_at', []);
            $events = $transitions->detect($this->store->screenings(), $screenings, $lastNotified, $now);

            $canary = $watchdog->checkCanary($filmCount, $this->store);
            if ($canary !== null) {
                $events[] = $canary;
            }
        }

        $silence = $watchdog->checkTickSilence($this->store, $scheduler->isHotSeason($now), $now);
        if ($silence !== null) {
            $events[] = $silence;
        }
        if ($watchdog->heartbeatDue($this->store, $now)) {
            $events[] = new Event(
                Event::WARNING,
                sprintf(
                    'Lebenszeichen: Watcher laeuft. Zustand: %s, %d Vorstellung(en) beobachtet, Quelle: %s.',
                    $this->highestStatus($screenings)->label(),
                    count($screenings),
                    $usedFallback ? 'HTML-Rueckfallebene' : 'API'
                ),
            );
            $this->store->setMeta('last_heartbeat_at', date('c', $now));
        }
        if ($forceNotify && $events === []) {
            $events[] = new Event(
                Event::WARNING,
                'Testmail (--force-notify). Wenn du diese Mail liest, funktioniert die gesamte Kette '
                . 'von HTTP ueber die API bis zum SMTP-Versand.',
            );
        }

        $this->persist($screenings, $events, $errors, $scheduler, $now, $haveFreshData);

        if ($events !== []) {
            $mailError = $this->notify($events);
            if ($mailError !== null) {
                $errors[] = 'Mailversand: ' . $mailError;
                $this->store->appendEvent(['type' => 'mail_failed', 'error' => $mailError]);
                $this->save();
            }
        }

        return [
            'ran' => true,
            'reason' => $haveFreshData ? 'geprueft' : 'keine neuen Daten',
            'events' => $events,
            'screenings' => $screenings,
            'errors' => $errors,
        ];
    }

    private function httpClient(): Client
    {
        return new Client(
            (string)$this->config->get('http.user_agent'),
            (int)$this->config->get('http.timeout'),
            (int)$this->config->get('http.retries'),
            (int)$this->config->get('http.min_interval_per_host'),
            (bool)$this->config->get('http.respect_robots'),
        );
    }

    private function programUrl(): string
    {
        return rtrim((string)$this->config->get('cinema.base_url'), '/')
             . (string)$this->config->get('cinema.program_path');
    }

    /**
     * Header fuer die API. {base_url} und {program_url} werden ersetzt, damit
     * die Konfiguration die Domain nicht doppelt fuehren muss.
     *
     * @return array<string,string>
     */
    private function apiHeaders(): array
    {
        $replacements = [
            '{base_url}'    => rtrim((string)$this->config->get('cinema.base_url'), '/'),
            '{program_url}' => $this->programUrl(),
        ];
        $out = [];
        foreach ((array)$this->config->get('http.api_headers', []) as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $out[$name] = strtr($value, $replacements);
            }
        }
        return $out;
    }

    private function matcher(): TitleMatcher
    {
        return new TitleMatcher(
            (array)$this->config->get('watch_patterns'),
            (string)$this->config->get('date_window.from'),
            (string)$this->config->get('date_window.to'),
        );
    }

    /**
     * Kino-Kennung fuer die API. Normalerweise aus der Konfiguration (1391 fuer
     * Magdeburg); fehlt sie, wird sie einmalig aus dem __NEXT_DATA__ der
     * Programmseite gelesen und gemerkt.
     *
     * @param list<string> $errors
     */
    private function cinemaId(Client $http, array &$errors): ?string
    {
        $configured = trim((string)$this->config->get('cinema.id', ''));
        if ($configured !== '') {
            return $configured;
        }
        $cached = $this->store->meta('cinema_id');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $url = rtrim((string)$this->config->get('cinema.base_url'), '/')
             . (string)$this->config->get('cinema.program_path');
        $res = $http->get($url);
        if (!$res->ok()) {
            $errors[] = 'Kino-Kennung: Programmseite nicht erreichbar (' . ($res->error ?? '?') . ')';
            return null;
        }
        $id = NextData::idsFromHtml($res->body)['cinemaId'];
        if ($id !== null) {
            $this->store->setMeta('cinema_id', $id);
        }
        return $id;
    }

    /**
     * Rueckfallebene: Programmseite und Sitemap nach Filmseiten durchsuchen,
     * diese einzeln auswerten. Deutlich fragiler als die API und deshalb nur
     * Reserve.
     *
     * @return array{0:list<Screening>,1:list<string>,2:int}
     */
    private function htmlFallback(Client $http, TitleMatcher $matcher, int $keep): array
    {
        $discovery = new Discovery(
            $http,
            $matcher,
            (string)$this->config->get('cinema.base_url'),
            (string)$this->config->get('cinema.program_path'),
            (string)$this->config->get('cinema.city'),
        );
        $filmPage = new FilmPage($http, (array)$this->config->get('booking_link_patterns'));

        $found  = $discovery->candidateUrls((array)$this->config->get('known_urls'), $this->store, $keep);
        $errors = $found['errors'];
        $out    = [];

        foreach ($found['urls'] as $url) {
            $result = $filmPage->fetch($url, $this->store, $keep);
            if ($result->error !== null) {
                $errors[] = $url . ': ' . $result->error;
                continue;
            }
            foreach ($this->filter($result->screenings, $matcher) as $screening) {
                $out[] = $screening;
            }
        }
        return [$out, $errors, max(0, $found['program_link_count'])];
    }

    /**
     * @param list<Screening> $screenings
     * @return list<Screening>
     */
    private function filter(array $screenings, TitleMatcher $matcher): array
    {
        $out = [];
        foreach ($screenings as $screening) {
            if (!$matcher->matches($screening->title, $screening->url)) {
                continue;
            }
            if (!$matcher->inDateWindow($screening->startsAt)) {
                $this->log('ausserhalb des Zeitfensters: ' . $screening->title . ' ' . $screening->when());
                continue;
            }
            $out[] = $screening;
        }
        return $this->dedupe($out);
    }

    /**
     * Mehrere Vorstellungen am selben Tag teilen sich einen Fingerprint. Es
     * gewinnt die guenstigste: Ist eine buchbar, ist der Tag buchbar.
     *
     * @param list<Screening> $screenings
     * @return list<Screening>
     */
    private function dedupe(array $screenings): array
    {
        $best = [];
        foreach ($screenings as $screening) {
            $fp = $screening->fingerprint();
            $current = $best[$fp] ?? null;
            if ($current === null || $this->priority($screening->status) > $this->priority($current->status)) {
                $best[$fp] = $screening;
            }
        }
        return array_values($best);
    }

    private function priority(Status $status): int
    {
        // Buchbar schlaegt alles -- auch "ausverkauft", das nur fuer einzelne
        // Vorstellungen gelten kann, waehrend eine andere noch offen ist.
        return match ($status) {
            Status::Bookable   => 5,
            Status::Dated      => 4,
            Status::SoldOut    => 3,
            Status::PageExists => 2,
            Status::Absent     => 1,
        };
    }

    private function crosscheckDue(int $now): bool
    {
        $last = $this->store->meta('last_html_crosscheck_at');
        if (!is_string($last)) {
            return true;
        }
        $ts = strtotime($last);
        return $ts === false || ($now - $ts) >= (int)$this->config->get('schedule.html_crosscheck_seconds', 1800);
    }

    /** @param list<Screening> $screenings */
    private function highestStatus(array $screenings): Status
    {
        $best = Status::Absent;
        foreach ($screenings as $s) {
            if ($this->priority($s->status) > $this->priority($best)) {
                $best = $s->status;
            }
        }
        return $best;
    }

    /** @param list<Screening> $screenings @param list<Event> $events @param list<string> $errors */
    private function persist(
        array $screenings,
        array $events,
        array $errors,
        Scheduler $scheduler,
        int $now,
        bool $haveFreshData,
    ): void {
        $presale = null;
        if ($haveFreshData) {
            $rows = [];
            foreach ($screenings as $s) {
                $rows[$s->fingerprint()] = $s->toArray() + ['last_seen' => date('c', $now)];
                $presale ??= $s->presaleStartsAt;
            }
            $this->store->setScreenings($rows);
        } else {
            // Ohne frische Daten bleibt der gespeicherte Zustand unangetastet.
            foreach ($this->store->screenings() as $row) {
                $presale ??= $row['presale_starts_at'] ?? null;
            }
        }

        $consecutive = $errors !== [] && !$haveFreshData
            ? (int)$this->store->meta('consecutive_errors', 0) + 1
            : 0;

        $status = $haveFreshData
            ? $this->highestStatus($screenings)
            : (Status::tryFrom((string)$this->store->meta('last_status', '')) ?? Status::Absent);

        $this->store->setMeta('consecutive_errors', $consecutive);
        $this->store->setMeta('last_run_at', date('c', $now));
        $this->store->setMeta('last_status', $status->value);
        $this->store->setMeta('next_check_at', $scheduler->nextCheckAt($status, $presale, $consecutive, $now));
        if ($errors === []) {
            $this->store->setMeta('last_success_at', date('c', $now));
        }

        foreach ($events as $event) {
            $this->store->appendEvent([
                'type' => $event->type,
                'summary' => $event->summary,
                'status' => $event->screening?->status->value,
                'previous' => $event->previousStatus,
            ]);
        }
        foreach ($errors as $error) {
            $this->store->appendEvent(['type' => 'error', 'summary' => $error]);
        }
        $this->save();
    }

    /** @param list<Event> $events */
    private function notify(array $events): ?string
    {
        $templates = new Templates();
        $cinema    = (string)$this->config->get('cinema.name');
        $subject   = $templates->subject($events, $cinema);

        if ($this->dryRun) {
            $this->log('[dry-run] Mail unterdrueckt. Betreff waere: ' . $subject);
            $this->log($templates->text($events, $cinema));
            return null;
        }

        $mailer = new Mailer((array)$this->config->get('smtp'));
        $error  = $mailer->send(
            $subject,
            $templates->text($events, $cinema),
            $templates->html($events, $cinema),
        );
        if ($error !== null) {
            return $error;
        }

        $notified = (array)$this->store->meta('notified_at', []);
        foreach ($events as $event) {
            if ($event->screening !== null) {
                $notified[$event->screening->fingerprint()] = time();
            }
        }
        $this->store->setMeta('notified_at', $notified);
        $this->save();
        return null;
    }

    private function save(): void
    {
        if (!$this->dryRun) {
            $this->store->save();
        }
    }

    private function log(string $message): void
    {
        if ($this->verbose) {
            fwrite(STDOUT, '  ' . $message . "\n");
        }
    }
}
