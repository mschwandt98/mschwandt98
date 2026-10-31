#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Der Lauf fuer den Cron. Stuendlich ist ein guter Takt.
 *
 *   php8.5 bin/run.php            Lauf mit Ausgabe
 *   php8.5 bin/run.php --quiet    nur Ereignisse und Fehler (fuer den Cron)
 *   php8.5 bin/run.php --digest   Wochenuebersicht sofort schicken
 */

require __DIR__ . '/bootstrap.php';

use Optout\App;
use Optout\Cases\Store;
use Optout\Output;

$options = getopt('', ['quiet', 'digest', 'help', 'config:']);
if (isset($options['help'])) {
    Output::line("php bin/run.php [--quiet] [--digest] [--config=<pfad>]\n");
    exit(0);
}

$store = null;
try {
    $config = optout_config($options);
    $store = new Store($config->varDir());
    if (!$store->acquireLock()) {
        Output::line("Ein anderer Lauf ist aktiv.\n");
        exit(0);
    }
    $result = App::runner($config, $store)->run(time(), isset($options['digest']));
} catch (Throwable $e) {
    Output::error('Fehler: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    $store?->releaseLock();
}

foreach ($result['log'] as $line) {
    Output::line($line . "\n");
}
foreach ($result['errors'] as $line) {
    Output::error('FEHLER: ' . $line . "\n");
}
if (!isset($options['quiet'])) {
    Output::line(sprintf(
        "%s verschickt, %d Erinnerung(en), %d Antwort(en), %d brauchen dich.%s\n",
        $result['sent'] === 1 ? '1 Anfrage' : $result['sent'] . ' Anfragen',
        $result['reminded'],
        $result['replies'],
        $result['attention'],
        $config->live() ? '' : " (Testmodus)"
    ));
}
exit($result['errors'] === [] ? 0 : 1);
