#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Entrypoint fuer den IONOS-Cronjob (alle 6 Stunden) und fuer Aufrufe von Hand.
 *
 * Beispiele:
 *   php8.5 bin/watch.php
 *   php8.5 bin/watch.php --dry-run --verbose --ignore-schedule
 *   php8.5 bin/watch.php --force-notify
 *   php8.5 bin/watch.php --status
 */

// Diese Pruefung laeuft VOR dem Autoloader und darf deshalb nichts aus dem
// Projekt benutzen -- auch nicht Kino\Output. echo funktioniert in jeder
// Umgebung und braucht weder Klassen noch die CLI-Konstanten.
if (PHP_VERSION_ID < 80500) {
    echo sprintf(
        "Dieser Watcher setzt PHP 8.5 voraus, laeuft aber unter %s.\n" .
        "Bitte im IONOS-Cron die richtige PHP-Version eintragen (z. B. /usr/bin/php8.5).\n",
        PHP_VERSION
    );
    exit(3);
}

require __DIR__ . '/../src/autoload.php';

use Kino\Config;
use Kino\Output;
use Kino\Runner;
use Kino\State\Store;

$options = getopt('', [
    'dry-run', 'verbose', 'quiet', 'force-notify', 'ignore-schedule', 'status', 'help', 'config::',
]);
$quiet = isset($options['quiet']) && !isset($options['verbose']);

if (isset($options['help'])) {
    Output::line(<<<TXT
    Kino-Ticket-Watcher

      --dry-run           pruefen, aber nichts speichern und keine Mail senden
      --verbose           ausfuehrliche Ausgabe
      --quiet             nur Ereignisse und Fehler ausgeben -- fuer den Cronjob
      --ignore-schedule   pruefen, auch wenn noch nicht faellig
      --force-notify      Testmail senden, auch ohne Aenderung
      --status            aktuellen Zustand anzeigen und beenden
      --config=<pfad>     alternative config.php

    TXT);
    exit(0);
}

try {
    $config = Config::load(isset($options['config']) ? (string)$options['config'] : null);
    date_default_timezone_set((string)$config->get('timezone'));
    $store = new Store($config->varDir());

    if (isset($options['status'])) {
        Output::line(renderStatus($store));
        exit(0);
    }

    if (!$store->acquireLock()) {
        if (!$quiet) {
            Output::line("Ein anderer Lauf ist bereits aktiv -- dieser Tick wird uebersprungen.\n");
        }
        exit(0);
    }

    $runner = new Runner(
        $config,
        $store,
        dryRun:  isset($options['dry-run']),
        verbose: isset($options['verbose']) || isset($options['dry-run']),
    );

    $result = $runner->run(
        ignoreSchedule: isset($options['ignore-schedule']) || isset($options['force-notify']),
        forceNotify:    isset($options['force-notify']),
    );

    if (!$result['ran']) {
        if (!$quiet) {
            Output::line($result['reason'] . "\n");
        }
        exit(0);
    }

    // Im Cron-Betrieb nur melden, wenn es etwas zu melden gibt. Bei einem
    // Takt von zwei Minuten liefe die Logdatei sonst mit Routinezeilen voll.
    $worthReporting = $result['events'] !== [] || $result['errors'] !== [];
    if (!$quiet || $worthReporting) {
        Output::line(sprintf(
            "%s Geprueft: %d Treffer, %d Ereignis(se), %d Fehler.\n",
            date('Y-m-d H:i:s'),
            count($result['screenings']),
            count($result['events']),
            count($result['errors'])
        ));
        foreach ($result['screenings'] as $screening) {
            Output::line(sprintf("  [%s] %s -- %s\n",
                $screening->status->value, $screening->title, $screening->when()));
        }
        foreach ($result['events'] as $event) {
            Output::line('  -> ' . $event->summary . "\n");
        }
    }
    foreach ($result['errors'] as $error) {
        Output::error('  !! ' . $error . "\n");
    }

    exit($result['errors'] === [] ? 0 : 2);
} catch (Throwable $e) {
    Output::error('Abbruch: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    if (isset($store)) {
        $store->releaseLock();
    }
}

function renderStatus(Store $store): string
{
    $lines = ['Kino-Ticket-Watcher -- Zustand', str_repeat('=', 40)];
    foreach (['last_run_at' => 'Letzter Lauf', 'last_success_at' => 'Letzter Erfolg',
              'last_tick_at' => 'Letzter Tick', 'next_check_at' => 'Naechste Pruefung',
              'last_status' => 'Status', 'consecutive_errors' => 'Fehler in Folge'] as $key => $label) {
        $lines[] = sprintf('%-18s %s', $label . ':', var_export($store->meta($key), true));
    }
    $lines[] = '';
    $screenings = $store->screenings();
    $lines[] = 'Beobachtete Vorstellungen: ' . count($screenings);
    foreach ($screenings as $fp => $row) {
        $lines[] = sprintf('  %s  [%s] %s (%s)',
            $fp, $row['status'] ?? '?', $row['title'] ?? '?', $row['starts_at'] ?? 'Termin offen');
    }
    return implode("\n", $lines) . "\n";
}
