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
use Kino\Source\Discovery;
use Kino\Source\FilmPage;
use Kino\State\Store;

/**
 * Orchestriert einen einzelnen Prueflauf. Beide Entrypoints (CLI-Cron und
 * HTTP-Tick) landen hier -- die Prueflogik existiert genau einmal.
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

        $http = new Client(
            (string)$this->config->get('http.user_agent'),
            (int)$this->config->get('http.timeout'),
            (int)$this->config->get('http.retries'),
            (int)$this->config->get('http.min_interval_per_host'),
            (bool)$this->config->get('http.respect_robots'),
        );
        $matcher = new TitleMatcher(
            (array)$this->config->get('watch_patterns'),
            (string)$this->config->get('date_window.from'),
            (string)$this->config->get('date_window.to'),
        );
        $discovery = new Discovery(
            $http,
            $matcher,
            (string)$this->config->get('cinema.base_url'),
            (string)$this->config->get('cinema.program_path'),
            (string)$this->config->get('cinema.city'),
        );
        $filmPage = new FilmPage($http, (array)$this->config->get('booking_link_patterns'));
        $keep     = (int)$this->config->get('snapshots_to_keep');

        $found = $discovery->candidateUrls((array)$this->config->get('known_urls'), $this->store, $keep);
        $errors = $found['errors'];
        $this->log(sprintf('%d Kandidaten-URL(s), %d Filmlinks im Programm',
            count($found['urls']), $found['program_link_count']));

        $screenings = [];
        $diffEvents = [];
        foreach ($found['urls'] as $url) {
            $previousDigest = $this->store->httpCache($url)['digest'] ?? null;
            $result = $filmPage->fetch($url, $this->store, $keep);
            if ($result->error !== null) {
                $errors[] = $url . ': ' . $result->error;
                continue;
            }
            foreach ($result->screenings as $screening) {
                if (!$matcher->matches($screening->title, $screening->url)) {
                    continue;
                }
                if (!$matcher->inDateWindow($screening->startsAt)) {
                    $this->log('ausserhalb des Zeitfensters: ' . $screening->title . ' ' . $screening->when());
                    continue;
                }
                $screenings[] = $screening;
            }
            $diff = $watchdog->checkContentDiff($url, $previousDigest, $result->digest);
            if ($diff !== null) {
                $diffEvents[] = $diff;
            }
        }

        $transitions = new Transitions((array)$this->config->get('notify.bookable_repeat_after'));
        $lastNotified = (array)$this->store->meta('notified_at', []);
        $events = $transitions->detect($this->store->screenings(), $screenings, $lastNotified, $now);

        // Der Roh-Diff ist nur dann interessant, wenn der Klassifikator nichts
        // gesehen hat. Sonst wuerde jede echte Aenderung doppelt gemeldet.
        if ($events === []) {
            $events = $diffEvents;
        }

        $canary = $watchdog->checkCanary($found['program_link_count'], $this->store);
        if ($canary !== null) {
            $events[] = $canary;
        }
        $silence = $watchdog->checkTickSilence($this->store, $scheduler->isHotSeason($now), $now);
        if ($silence !== null) {
            $events[] = $silence;
        }
        if ($watchdog->heartbeatDue($this->store, $now)) {
            $events[] = new Event(
                Event::WARNING,
                sprintf(
                    'Lebenszeichen: Watcher laeuft. Aktueller Zustand: %s. %d Vorstellung(en) beobachtet.',
                    $this->highestStatus($screenings)->label(),
                    count($screenings)
                ),
            );
            $this->store->setMeta('last_heartbeat_at', date('c', $now));
        }

        if ($forceNotify && $events === []) {
            $events[] = new Event(
                Event::WARNING,
                'Testmail (--force-notify). Wenn du diese Mail liest, funktioniert die gesamte Kette '
                . 'von HTTP ueber Parser bis SMTP.',
            );
        }

        $this->persist($screenings, $events, $errors, $scheduler, $now);

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
            'reason' => 'geprueft',
            'events' => $events,
            'screenings' => $screenings,
            'errors' => $errors,
        ];
    }

    /** @param list<Screening> $screenings */
    private function highestStatus(array $screenings): Status
    {
        $best = Status::Absent;
        foreach ($screenings as $s) {
            // SoldOut hat den hoechsten rank, ist aber fuer die Taktung nicht
            // "besser" als Bookable -- Bookable gewinnt daher ausdruecklich.
            if ($s->status === Status::Bookable) {
                return Status::Bookable;
            }
            if ($s->status->rank() > $best->rank()) {
                $best = $s->status;
            }
        }
        return $best;
    }

    /** @param list<Screening> $screenings @param list<Event> $events @param list<string> $errors */
    private function persist(array $screenings, array $events, array $errors, Scheduler $scheduler, int $now): void
    {
        $rows = [];
        $presale = null;
        foreach ($screenings as $s) {
            $rows[$s->fingerprint()] = $s->toArray() + ['last_seen' => date('c', $now)];
            $presale ??= $s->presaleStartsAt;
        }

        $consecutive = $errors !== [] && $screenings === []
            ? (int)$this->store->meta('consecutive_errors', 0) + 1
            : 0;

        $status = $this->highestStatus($screenings);
        $this->store->setScreenings($rows);
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
