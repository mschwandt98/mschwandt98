#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Aktualisiert die Firmendaten aus der Datenanfragen.de-Datenbank.
 *
 *   php bin/sync-db.php                 alle ausgewaehlten Firmen auffrischen
 *   php bin/sync-db.php --add=a,b       zusaetzliche Firmen nachladen
 *
 * Neue Fassungen landen in var/db/ und haben Vorrang vor data/companies/.
 * Einmal im Quartal reicht -- veraltete Adressen fallen ohnehin als
 * unzustellbar auf.
 */

require __DIR__ . '/bootstrap.php';

use Optout\Output;
use Optout\Registry\Registry;

const BASE = 'https://raw.githubusercontent.com/datenanfragen/data/master/companies/';

$options = getopt('', ['config:', 'add:']);
$config = optout_config($options);
$registry = Registry::load($config);

$slugs = array_keys($registry->selected($config));
if (isset($options['add'])) {
    $slugs = array_merge($slugs, array_filter(array_map('trim', explode(',', (string)$options['add']))));
}
$slugs = array_values(array_unique($slugs));

$dir = $config->varDir() . '/db';
if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    Output::error("Verzeichnis nicht anlegbar: $dir\n");
    exit(1);
}

$fetch = static function (string $url): ?string {
    $ctx = stream_context_create(['http' => [
        'timeout' => 15,
        'header'  => "User-Agent: datenbroker-loeschung (persoenliches Werkzeug)\r\n",
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $m)) {
            $status = (int)$m[1];
        }
    }
    return $status === 200 && is_string($body) ? $body : null;
};

$changed = 0;
$failed = 0;
foreach ($slugs as $slug) {
    if (preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
        Output::error("Ungültiger Slug: $slug\n");
        continue;
    }
    if ($registry->find($slug)?->source === 'config') {
        continue; // eigener Eintrag aus config.php
    }
    $body = $fetch(BASE . $slug . '.json');
    $record = $body === null ? null : json_decode($body, true);
    if (!is_array($record) || ($record['slug'] ?? '') !== $slug) {
        Output::error("✗  $slug: nicht abrufbar (gibt es den Slug noch? Firmen werden in der Datenbank manchmal umbenannt)\n");
        $failed++;
        continue;
    }
    $before = $registry->find($slug);
    $file = "$dir/$slug.json";
    $old = is_file($file) ? file_get_contents($file) : null;
    file_put_contents($file, $body);
    $new = Optout\Registry\Broker::fromRecord($record);
    if ($before === null) {
        Output::line("+  $slug: neu – {$new->name} <{$new->email}>\n");
        $changed++;
    } elseif ($before->email !== $new->email || $before->webform !== $new->webform) {
        Output::line("~  $slug: {$before->email} → {$new->email}\n");
        $changed++;
    } elseif ($old !== $body) {
        $changed++;
    }
    usleep(200_000);
}
Output::line(sprintf("\n%d Firmen geprüft, %d aktualisiert, %d nicht abrufbar.\n", count($slugs), $changed, $failed));
exit($failed === 0 ? 0 : 1);
