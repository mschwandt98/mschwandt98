#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Zeigt, was verschickt wuerde -- ohne etwas zu verschicken.
 *
 *   php bin/preview.php                 Liste: wer bekommt welche Anfrage auf welchem Weg
 *   php bin/preview.php schufa          der vollstaendige Brief an eine Firma
 */

require __DIR__ . '/bootstrap.php';

use Optout\App;
use Optout\Cases\CaseFile;
use Optout\Cases\Store;
use Optout\Engine\Runner;
use Optout\Letters\Letters;
use Optout\Output;
use Optout\Registry\Registry;

$options = getopt('', ['config:'], $rest);
$slug = $argv[$rest] ?? '';
$config = optout_config($options);
$registry = Registry::load($config);
$store = new Store($config->varDir());
$runner = App::runner($config, $store);
$letters = new Letters($config);

foreach (App::configProblems($config) as $p) {
    Output::line("Konfiguration: $p\n");
}

if ($slug === '') {
    $selected = $registry->selected($config);
    Output::line(sprintf("%-26s %-36s %-10s %-4s %s\n", 'Slug', 'Unternehmen', 'Art', 'Spr.', 'Weg'));
    Output::line(str_repeat('-', 110) . "\n");
    foreach ($selected as $b) {
        $way = $b->canEmail() ? $b->email : ($b->webform !== '' ? 'FORMULAR ' . $b->webform : 'BRIEF');
        Output::line(optout_pad($b->slug, 26) . ' ' . optout_pad($b->name, 36) . ' ' . optout_pad($runner->kindFor($b)->label(), 10)
            . ' ' . optout_pad($b->language, 4) . " $way\n");
    }
    Output::line("\n" . count($selected) . " Unternehmen. Brief ansehen: php bin/preview.php <slug>\n");
    foreach ($registry->warnings() as $w) {
        Output::line("Warnung: $w\n");
    }
    exit(0);
}

$broker = $registry->find($slug);
if ($broker === null) {
    Output::error("Unbekannt: $slug. Suchen mit: php bin/find.php <name>\n");
    exit(1);
}
$letter = $letters->request($broker, $runner->kindFor($broker), CaseFile::newRef());
Output::line(Runner::envelope($broker->canEmail() ? $broker->email : ($broker->webform ?: $broker->address), $letter['subject'], $letter['body'], time()));
if ($letter['missing'] !== []) {
    Output::line("\nACHTUNG: Die Firma verlangt laut Datenbank auch: " . implode(', ', $letter['missing'])
        . ". Ohne diese Angaben kommt vermutlich eine Rückfrage.\n");
}
foreach ($broker->comments as $c) {
    Output::line("\nHinweis aus der Datenbank: $c\n");
}
