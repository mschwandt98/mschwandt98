#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Prueft die CinemaxX-Quellen auf dem Zielsystem und berichtet, was die API
 * tatsaechlich liefert.
 *
 *   php8.5 bin/discover.php > discovery-report.txt
 *
 * Anders als beim ersten Entwurf muss hier nichts mehr geraten werden: Die
 * Showings-API ist bekannt und dokumentiert (siehe PLAN.md). Dieses Skript
 * dient noch der Kontrolle -- ob die API vom Server aus erreichbar ist, ob
 * die Kino-Kennung stimmt und was robots.txt sagt.
 *
 * Alle Rohantworten landen in var/discovery/ und taugen als Testfixtures.
 */

require __DIR__ . '/../src/autoload.php';

use Kino\Config;
use Kino\Http\Client;
use Kino\Match\TitleMatcher;
use Kino\Parse\Dom;
use Kino\Parse\NextData;
use Kino\Source\CinemaxxApi;

$configFile = dirname(__DIR__) . '/config/config.php';
$config = is_file($configFile) ? Config::load($configFile) : Config::fromArray([]);
date_default_timezone_set((string)$config->get('timezone'));

$base   = rtrim((string)$config->get('cinema.base_url'), '/');
$city   = trim((string)$config->get('cinema.city'), '/');
$outDir = $config->varDir() . '/discovery';
@mkdir($outDir, 0775, true);

// robots.txt wird hier bewusst nicht beachtet -- wir wollen ja gerade sehen,
// was darin steht. Der eigentliche Watcher haelt sich daran.
$http = new Client((string)$config->get('http.user_agent'), 20, 1, 2, respectRobots: false);
$api  = new CinemaxxApi($http, $base);
$matcher = new TitleMatcher(
    (array)$config->get('watch_patterns'),
    (string)$config->get('date_window.from'),
    (string)$config->get('date_window.to'),
);

line('CinemaxX-Discovery');
line('Zeitpunkt: ' . date('c'));
line('Basis:     ' . $base);
line(str_repeat('=', 72));

// --- 1. robots.txt ----------------------------------------------------------
line('');
line('### robots.txt');
$res = $http->get($base . '/robots.txt');
if ($res->ok()) {
    save($outDir, 'robots', $res->body);
    line(trim($res->body));
} else {
    line('FEHLER: ' . ($res->error ?? '?'));
}

// --- 2. Kino-Kennung --------------------------------------------------------
line('');
line('### Kino-Kennung');
$configured = (string)$config->get('cinema.id', '');
line('konfiguriert: ' . ($configured !== '' ? $configured : '(leer)'));

$programUrl = $base . (string)$config->get('cinema.program_path');
$res = $http->get($programUrl);
$cinemaId = $configured !== '' ? $configured : null;
if ($res->ok()) {
    save($outDir, 'program', $res->body);
    $ids = NextData::idsFromHtml($res->body);
    line('aus der Programmseite: ' . ($ids['cinemaId'] ?? '(nicht gefunden)'));
    line('__NEXT_DATA__ vorhanden: ' . (NextData::extract($res->body) !== null ? 'ja' : 'NEIN'));
    $cinemaId ??= $ids['cinemaId'];
    if ($configured !== '' && $ids['cinemaId'] !== null && $ids['cinemaId'] !== $configured) {
        line('!! ABWEICHUNG: konfiguriert ' . $configured . ', Seite sagt ' . $ids['cinemaId']);
    }
    $links = array_values(array_filter(
        Dom::links($res->body, $programUrl),
        static fn ($l) => str_contains($l, "/$city/film/")
    ));
    line('Filmlinks im HTML (Rueckfallebene): ' . count($links));
} else {
    line('FEHLER beim Abruf der Programmseite: ' . ($res->error ?? '?'));
}

if ($cinemaId === null) {
    line('');
    line('Ohne Kino-Kennung kann die API nicht abgefragt werden. Abbruch.');
    exit(1);
}

// --- 3. Die eigentliche Quelle: Showings-API --------------------------------
line('');
line('### Showings-API');
$url = $api->sessionsUrl($cinemaId);
line('URL: ' . $url);

$res = $http->get($url);
if (!$res->ok()) {
    line('FEHLER: ' . ($res->error ?? '?'));
    line('Damit faellt der Watcher auf die HTML-Ebene zurueck -- das funktioniert,');
    line('ist aber deutlich fragiler. Bitte melden.');
    exit(1);
}
save($outDir, 'api-cinema-' . $cinemaId, $res->body);
line(sprintf('HTTP %d, %d Bytes', $res->status, strlen($res->body)));
line('Cache-Control: ' . ($res->headers['cache-control'] ?? '-'));
line('ETag: ' . ($res->etag ?? '-') . ' | Last-Modified: ' . ($res->lastModified ?? '-'));

$data = json_decode($res->body, true);
$films = is_array($data) ? ($data['result'] ?? []) : [];
line('Filme im Kino: ' . (is_array($films) ? count($films) : 0));

$screenings = $api->parse($res->body);
line('Vorstellungen gesamt: ' . count($screenings));
line('');

$hits = 0;
foreach ($screenings as $s) {
    $isHit = $matcher->matches($s->title, $s->url);
    if ($isHit) {
        $hits++;
    }
    line(sprintf(
        '%s [%-11s] %-52s %s%s',
        $isHit ? '>>' : '  ',
        $s->status->value,
        mb_substr($s->title, 0, 52),
        $s->when(),
        $s->presaleStartsAt !== null ? '  VVK ab ' . $s->presaleStartsAt : ''
    ));
}

line('');
line('Treffer auf die konfigurierten Suchmuster: ' . $hits);
if ($hits === 0) {
    line('');
    line('Kein Treffer. Das ist ausserhalb der Marathon-Saison normal. Falls der');
    line('Marathon gerade im Programm steht, bitte die watch_patterns pruefen --');
    line('die Titel oben zeigen, wie CinemaxX den Film tatsaechlich benennt.');
}

line('');
line(str_repeat('=', 72));
line('Rohantworten in: ' . $outDir);

function save(string $dir, string $name, string $body): void
{
    file_put_contents($dir . '/' . $name . '.txt', $body);
}

function line(string $s): void
{
    fwrite(STDOUT, $s . "\n");
}
