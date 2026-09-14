#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Einmalige Analyse der CinemaxX-Seite -- auf dem Zielsystem auszufuehren.
 *
 *   php8.5 bin/discover.php > discovery-report.txt
 *
 * Das Skript raet nichts. Es berichtet, was tatsaechlich auf der Seite steht:
 * welche strukturierten Daten vorhanden sind, wie Buchungslinks aussehen, was
 * robots.txt erlaubt. Aus diesem Bericht werden die Parser praezisiert.
 *
 * Alle Rohantworten landen in var/discovery/ -- diese Dateien sind zugleich
 * die Testfixtures. Besonders wertvoll ist ein Abzug, solange der Vorverkauf
 * noch laeuft: Der Zustand "buchbar" ist spaeter nicht mehr beschaffbar.
 */

require __DIR__ . '/../src/autoload.php';

use Kino\Config;
use Kino\Http\Client;
use Kino\Parse\Dom;
use Kino\Parse\JsonLd;
use Kino\Parse\Normalize;

// Discovery soll auch vor dem Ausfuellen der config.php laufen koennen --
// fuer den Bericht braucht es weder SMTP-Daten noch ein Secret.
$configFile = dirname(__DIR__) . '/config/config.php';
$config = is_file($configFile) ? Config::load($configFile) : Config::fromArray([]);
date_default_timezone_set((string)$config->get('timezone'));

$base    = rtrim((string)$config->get('cinema.base_url'), '/');
$city    = trim((string)$config->get('cinema.city'), '/');
$outDir  = $config->varDir() . '/discovery';
@mkdir($outDir, 0775, true);

// robots.txt wird hier bewusst NICHT beachtet: Wir wollen ja gerade
// herausfinden, was sie sagt. Der Bericht weist darauf hin.
$http = new Client((string)$config->get('http.user_agent'), 20, 1, 2, respectRobots: false);

$targets = [
    'robots'  => $base . '/robots.txt',
    'sitemap' => $base . '/sitemap.xml',
    'program' => $base . (string)$config->get('cinema.program_path'),
];
foreach ((array)$config->get('known_urls') as $i => $url) {
    $targets['film' . ($i + 1)] = $url;
}

line('CinemaxX-Discovery');
line('Zeitpunkt: ' . date('c'));
line('Basis:     ' . $base);
line(str_repeat('=', 72));

foreach ($targets as $name => $url) {
    line('');
    line("### $name");
    line('URL: ' . $url);

    $res = $http->get($url);
    if (!$res->ok()) {
        line('FEHLER: ' . ($res->error ?? 'unbekannt'));
        continue;
    }
    $file = $outDir . '/' . $name . '.txt';
    file_put_contents($file, $res->body);
    line(sprintf('HTTP %d, %d Bytes -> %s', $res->status, strlen($res->body), $file));
    line('Content-Type: ' . ($res->headers['content-type'] ?? '?'));
    line('ETag: ' . ($res->etag ?? '-') . ' | Last-Modified: ' . ($res->lastModified ?? '-'));

    if ($name === 'robots') {
        line('--- Inhalt ---');
        line(trim($res->body));
        continue;
    }
    if ($name === 'sitemap') {
        preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $res->body, $m);
        $all  = $m[1] ?? [];
        $film = array_values(array_filter($all, static fn ($u) => str_contains($u, "/$city/film/")));
        line(sprintf('Eintraege gesamt: %d, davon Filmseiten %s: %d', count($all), $city, count($film)));
        line('Index-Sitemap: ' . (stripos($res->body, '<sitemapindex') !== false ? 'ja' : 'nein'));
        foreach (array_slice($all, 0, 8) as $u) {
            line('  ' . $u);
        }
        continue;
    }

    analyseHtml($res->body, $url, $city);
}

line('');
line(str_repeat('=', 72));
line('Bitte diesen Bericht zurueckschicken. Die Dateien in ' . $outDir);
line('bitte ebenfalls aufheben -- sie werden zu den Testfixtures.');

function analyseHtml(string $html, string $url, string $city): void
{
    $text = Normalize::visibleText($html);

    line('<title>: ' . (Dom::title($html) ?? '-'));
    line('<h1>:    ' . (Dom::heading($html) ?? '-'));
    line('Sichtbarer Text: ' . strlen($text) . ' Zeichen');

    // 1. JSON-LD -- die stabilste Quelle, wenn vorhanden
    $nodes = JsonLd::extract($html);
    line('');
    line('JSON-LD-Knoten: ' . count($nodes));
    foreach ($nodes as $node) {
        $types = implode('/', JsonLd::types($node));
        $line  = '  @type=' . $types;
        if (JsonLd::isEvent($node)) {
            $line .= ' | name=' . (JsonLd::string($node, 'name') ?? '-')
                   . ' | startDate=' . (JsonLd::string($node, 'startDate') ?? '-')
                   . ' | availability=' . (JsonLd::availability($node) ?? '-');
        }
        line($line);
    }
    if ($nodes === []) {
        line('  (keine -- dann entscheidet die HTML-Heuristik)');
    }

    // 2. Eingebettete JSON-Blobs (Next.js/Nuxt und Verwandte)
    line('');
    $blobs = [];
    foreach (['__NEXT_DATA__', '__NUXT__', '__INITIAL_STATE__', 'window.__DATA__'] as $marker) {
        if (str_contains($html, $marker)) {
            $blobs[] = $marker;
        }
    }
    line('Eingebettete JSON-Blobs: ' . ($blobs === [] ? 'keine' : implode(', ', $blobs)));

    // 3. URLs, die nach einer API aussehen
    preg_match_all('#["\'](/[^"\'\s]*(?:/api/|\.json|graphql)[^"\'\s]*)["\']#i', $html, $m);
    $api = array_slice(array_unique($m[1] ?? []), 0, 15);
    line('API-verdaechtige Pfade: ' . count($api));
    foreach ($api as $path) {
        line('  ' . $path);
    }

    // 4. Links -- die Grundlage fuer die Buchungserkennung
    $links = Dom::links($html, $url);
    $filmLinks = array_values(array_filter($links, static fn ($l) => str_contains($l, "/$city/film/")));
    line('');
    line(sprintf('Links gesamt: %d, Filmseiten (%s): %d', count($links), $city, count($filmLinks)));

    $interesting = array_values(array_filter($links, static fn ($l) =>
        preg_match('#(vorstellung|booking|ticket|buchen|kaufen|checkout|seat|platz|show)#i', $l) === 1));
    line('Buchungsverdaechtige Links: ' . count($interesting));
    foreach (array_slice($interesting, 0, 15) as $l) {
        line('  ' . $l);
    }

    // 5. Textmarker -- die letzte Rueckfallebene
    line('');
    line('Marker "ausverkauft":        ' . (Dom::looksSoldOut($text) ? 'JA' : 'nein'));
    line('Marker "noch nicht buchbar": ' . (Dom::looksPending($text) ? 'JA' : 'nein'));
    line('Vorverkaufsdatum erkannt:    ' . (Dom::presaleStart($text) ?? '-'));
    $dates = Dom::dates($text);
    line('Gefundene Datumsangaben (' . count($dates) . '): ' . implode(', ', array_slice($dates, 0, 12)));

    foreach (['vorverkauf', 'ausverkauft', 'tickets', 'buchen', 'marathon', 'herr der ringe'] as $needle) {
        $pos = mb_stripos($text, $needle);
        if ($pos !== false) {
            line(sprintf('Kontext "%s": ...%s...', $needle, mb_substr($text, max(0, $pos - 60), 160)));
        }
    }
}

function line(string $s): void
{
    fwrite(STDOUT, $s . "\n");
}
