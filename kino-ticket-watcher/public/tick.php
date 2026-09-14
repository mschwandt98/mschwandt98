<?php
declare(strict_types=1);

/**
 * Web-Entrypoint fuer den externen Taktgeber (cron-job.org).
 *
 * Dies ist die EINZIGE Datei des Watchers, die im Document Root liegen darf.
 * Sie enthaelt selbst keine Zugangsdaten -- die Konfiguration liegt ausserhalb
 * des Webspace-Wurzelverzeichnisses.
 *
 * Installation: Diese Datei in den Document Root kopieren und $appDir auf das
 * Verzeichnis der Anwendung zeigen lassen.
 */

// --- anpassen: absoluter Pfad zum Anwendungsverzeichnis (ausserhalb des Docroot)
$appDir = dirname(__DIR__);
// ---

if (PHP_VERSION_ID < 80500) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    exit("Falsche PHP-Version: " . PHP_VERSION . " (benoetigt wird 8.5).\n");
}

require $appDir . '/src/autoload.php';

use Kino\Config;
use Kino\Runner;
use Kino\State\Store;

/** Antwortet wie eine nicht existierende Datei -- verraet nicht, dass es tick.php gibt. */
function notFound(): never
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit("Not Found\n");
}

try {
    $config = Config::load($appDir . '/config/config.php');
} catch (Throwable) {
    // Ein Konfigurationsfehler darf nach aussen nicht sichtbar sein.
    notFound();
}

$expected = (string)$config->get('tick_secret');
$given    = (string)($_GET['key'] ?? '');
if ($expected === '' || strlen($given) !== strlen($expected) || !hash_equals($expected, $given)) {
    notFound();
}

date_default_timezone_set((string)$config->get('timezone'));
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');

// Der externe Dienst soll nicht auf den Abruf warten muessen; falls die
// Verbindung abbricht, laeuft der Lauf trotzdem zu Ende.
ignore_user_abort(true);

$store = null;
try {
    $store = new Store($config->varDir());
    if (!$store->acquireLock()) {
        exit("busy\n");
    }
    $result = (new Runner($config, $store))->run();
    if (!$result['ran']) {
        exit("skipped\n");
    }
    printf(
        "ok screenings=%d events=%d errors=%d\n",
        count($result['screenings']),
        count($result['events']),
        count($result['errors'])
    );
    if ($result['errors'] !== []) {
        // 500 sorgt dafuer, dass cron-job.org den Fehlschlag selbst meldet --
        // ein zweiter, unabhaengiger Weg, von Problemen zu erfahren.
        http_response_code(500);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo "error\n";
    error_log('KinoTicketWatcher: ' . $e->getMessage());
} finally {
    $store?->releaseLock();
}
