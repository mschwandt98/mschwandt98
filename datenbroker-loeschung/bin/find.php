#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Sucht in der lokalen Firmendatenbank -- z. B. fuer die Liste aus dem
 * Incogni-Dashboard.
 *
 *   php bin/find.php acxiom
 *   php bin/find.php --file=incogni.txt     eine Firma pro Zeile
 *
 * Nicht gefunden? Auf https://www.datenanfragen.de suchen und den Slug mit
 * bin/sync-db.php --add=<slug> nachladen, oder in config.php unter
 * brokers.custom selbst eintragen.
 */

require __DIR__ . '/bootstrap.php';

use Optout\Output;
use Optout\Registry\Registry;

$options = getopt('', ['config:', 'file:'], $rest);
$config = optout_config($options);
$registry = Registry::load($config);

$queries = [];
if (isset($options['file'])) {
    $queries = array_filter(array_map('trim', file((string)$options['file']) ?: []));
} else {
    $queries = [implode(' ', array_slice($argv, $rest))];
}

$found = [];
foreach ($queries as $q) {
    $hits = $registry->search($q);
    if ($hits === []) {
        Output::line("–  $q: nicht in der lokalen Datenbank\n");
        continue;
    }
    foreach ($hits as $b) {
        Output::line(sprintf("✓  %-24s %-40s %s\n", $b->slug, mb_strimwidth($b->name, 0, 40, '…'), $b->email ?: ($b->webform ?: 'nur Brief')));
        $found[] = $b->slug;
    }
}
if (count($queries) > 1 && $found !== []) {
    Output::line("\nFür config.php:\n'add' => ['" . implode("', '", array_unique($found)) . "'],\n");
}
