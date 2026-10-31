#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Einen Vorgang von Hand weiterschalten.
 *
 *   php bin/case.php DB-7K3Q9X done      erledigt (naechste Runde nach recheck_days)
 *   php bin/case.php DB-7K3Q9X wait      wartet wieder (Frist ab heute neu)
 *   php bin/case.php DB-7K3Q9X resend    beim naechsten Lauf erneut senden
 *   php bin/case.php DB-7K3Q9X ignore    diese Firma nicht mehr anschreiben
 *   php bin/case.php DB-7K3Q9X reopen    ignorierte/erledigte Firma wieder aufnehmen
 */

require __DIR__ . '/bootstrap.php';

use Optout\Cases\Store;
use Optout\Engine\Actions;
use Optout\Output;

$options = getopt('', ['config:'], $rest);
$ref = strtoupper($argv[$rest] ?? '');
$action = $argv[$rest + 1] ?? '';
if ($ref === '' || $action === '') {
    Output::line("php bin/case.php <DB-XXXXXX> <" . implode('|', array_keys(Actions::ALL)) . ">\n");
    exit(1);
}
$config = optout_config($options);
$store = new Store($config->varDir());
if (!$store->acquireLock()) {
    Output::error("Ein Lauf ist gerade aktiv, bitte gleich noch einmal.\n");
    exit(1);
}
try {
    $error = (new Actions($config, $store))->apply($ref, $action, time());
    if ($error !== null) {
        Output::error("$error\n");
        exit(1);
    }
    $store->save();
    Output::line("$ref: " . Actions::ALL[$action] . "\n");
} finally {
    $store->releaseLock();
}
