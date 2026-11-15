#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 *   php bin/status.php              aktuelle Vorgaenge aller ausgewaehlten Firmen
 *   php bin/status.php DB-7K3Q9X    ein Vorgang im Detail
 *   php bin/status.php schufa       alle Vorgaenge einer Firma
 */

require __DIR__ . '/bootstrap.php';

use Optout\Cases\CaseFile;
use Optout\Cases\Store;
use Optout\Output;
use Optout\Registry\Registry;

$options = getopt('', ['config:'], $rest);
$arg = $argv[$rest] ?? '';
$config = optout_config($options);
$store = new Store($config->varDir());
$registry = Registry::load($config);

$name = static fn (string $slug): string => $registry->find($slug)?->name ?? $slug;
$fmt = static fn (?int $ts): string => $ts ? date('d.m.Y', $ts) : '–';

$detail = static function (CaseFile $c) use ($name, $fmt, $store): void {
    Output::line(sprintf("%s  %s  (%s, Runde %d)\n", $c->ref(), $name($c->slug()), $c->kind()->label(), $c->d['cycle']));
    Output::line('  Status:    ' . $c->status()->label() . ($c->attention() ? ' – ' . $c->attention()->label() : '') . "\n");
    Output::line('  Verschickt ' . $fmt($c->int('sent_at')) . ', Frist ' . $fmt($c->int('due_at'))
        . ', erinnert ' . $fmt($c->int('reminded_at')) . "\n");
    if ($c->int('next_cycle_at')) {
        Output::line('  Nächste Runde ab ' . $fmt($c->int('next_cycle_at')) . "\n");
    }
    foreach ($c->d['notes'] as $n) {
        Output::line("  Hinweis:   $n\n");
    }
    Output::line("  Verlauf:\n");
    foreach ($c->d['history'] as $h) {
        Output::line('    ' . date('d.m.Y H:i', (int)$h['at']) . '  ' . $h['event'] . "\n");
    }
    foreach ($c->d['letters'] as $l) {
        Output::line('  Brief:     var/' . $l['file'] . "\n");
    }
    foreach ($c->replies() as $r) {
        Output::line("\n  Antwort " . date('d.m.Y', (int)$r['at']) . " von {$r['from']} – {$r['verdict']}\n");
        Output::line('  Betreff: ' . $r['subject'] . "\n");
        foreach (explode("\n", (string)$r['excerpt']) as $line) {
            Output::line("  | $line\n");
        }
        if (!empty($r['file'])) {
            Output::line('  Rohdaten: var/' . $r['file'] . "\n");
        }
    }
    Output::line("\n");
};

if ($arg !== '' && str_starts_with(strtoupper($arg), 'DB-')) {
    $c = $store->case(strtoupper($arg));
    if ($c === null) {
        Output::error("Vorgang $arg nicht gefunden.\n");
        exit(1);
    }
    $detail($c);
    exit(0);
}
if ($arg !== '') {
    foreach ($store->casesFor($arg) as $c) {
        $detail($c);
    }
    exit(0);
}

Output::line(($config->live() ? '' : "TESTMODUS – es wird nichts verschickt.\n") . "\n");
Output::line(sprintf("%-10s %-34s %-10s %-22s %-11s %s\n", 'Ref', 'Unternehmen', 'Art', 'Status', 'Frist', 'Letzte Antwort'));
Output::line(str_repeat('-', 110) . "\n");
foreach ($registry->selected($config) as $slug => $b) {
    $c = $store->currentFor($slug);
    if ($c === null) {
        Output::line(optout_pad('–', 10) . ' ' . optout_pad($b->name, 34) . " noch kein Vorgang (nächster Lauf legt ihn an)\n");
        continue;
    }
    $last = $c->replies() === [] ? '' : end($c->d['replies']);
    Output::line(optout_pad($c->ref(), 10) . ' ' . optout_pad($b->name, 34) . ' ' . optout_pad($c->kind()->label(), 10) . ' '
        . optout_pad($c->status()->label() . ($c->attention() ? '!' : ''), 22) . ' ' . optout_pad($fmt($c->int('due_at')), 11) . ' '
        . ($last ? date('d.m.', (int)$last['at']) . ' ' . $last['verdict'] : '') . "\n");
}
$meta = static fn (string $k) => $store->meta($k);
Output::line("\nLetzter Lauf: " . ($meta('last_run_at') ? date('d.m.Y H:i', (int)$meta('last_run_at')) : 'nie')
    . ' · Posteingang zuletzt gelesen: ' . ($meta('inbox_ok_at') ? date('d.m.Y H:i', (int)$meta('inbox_ok_at')) : 'nie') . "\n");
foreach ($registry->warnings() as $w) {
    Output::line("Warnung: $w\n");
}
