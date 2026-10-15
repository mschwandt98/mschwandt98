#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * End-to-End-Test gegen einen lokalen Webserver, der die Fixtures unter den
 * echten CinemaxX-Pfaden ausliefert.
 *
 *   php tests/integration.php
 *
 * Geprueft wird die vollstaendige Kette: robots.txt, Sitemap, Programmseite,
 * Filmseite, Titelfilter, Zustandsmaschine, Zustandsspeicher und Taktung --
 * ohne Netzzugriff und ohne echten Mailversand.
 */

require __DIR__ . '/../src/autoload.php';

use Kino\Config;
use Kino\Decide\Event;
use Kino\Model\Status;
use Kino\Runner;
use Kino\State\Store;

date_default_timezone_set('Europe/Berlin');

$pass = 0;
$fail = 0;
function ok(bool $cond, string $label, string $detail = ''): void
{
    global $pass, $fail;
    $cond ? $pass++ : $fail++;
    echo '  ' . ($cond ? 'ok  ' : 'FAIL') . ' ' . $label . (!$cond && $detail !== '' ? "  ($detail)" : '') . "\n";
}

// --- Testserver starten -----------------------------------------------------
$port = 8100 + random_int(0, 500);
$docRoot = __DIR__ . '/site';
$cmd = sprintf(
    'php -S 127.0.0.1:%d -t %s %s > /dev/null 2>&1 & echo $!',
    $port,
    escapeshellarg($docRoot),
    escapeshellarg($docRoot . '/router.php')
);
$pid = (int)trim((string)shell_exec($cmd));
register_shutdown_function(static function () use ($pid): void {
    if ($pid > 0) {
        @exec("kill $pid 2>/dev/null");
    }
});

$base = "http://127.0.0.1:$port";
for ($i = 0; $i < 50; $i++) {
    $probe = @file_get_contents($base . '/robots.txt');
    if ($probe !== false) {
        break;
    }
    usleep(100_000);
}
if ($probe === false) {
    fwrite(STDERR, "Testserver auf Port $port nicht erreichbar.\n");
    exit(1);
}

// --- Konfiguration ----------------------------------------------------------
$varDir = sys_get_temp_dir() . '/kino-integration-' . bin2hex(random_bytes(4));
@mkdir($varDir, 0775, true);

$config = Config::fromArray([
    'cinema'  => ['id' => '1391', 'base_url' => $base,
                  'program_path' => '/kinoprogramm/magdeburg', 'city' => 'magdeburg'],
    'known_urls' => [],   // ausdruecklich leer: Auffinden muss ueber Programm/Sitemap klappen
    'var_dir' => $varDir,
    'http'    => ['min_interval_per_host' => 0, 'retries' => 0, 'timeout' => 5],
    'smtp'    => ['host' => '', 'user' => '', 'pass' => '', 'from' => '', 'to' => ''],
    'schedule' => ['html_crosscheck_seconds' => 0],
]);

/** Legt fest, welches Szenario der Testserver ausliefert. */
function scenario(string $name): void
{
    file_put_contents(__DIR__ . '/site/current.txt', $name . "\n");
}

function runOnce(Config $config, string $varDir): array
{
    $store = new Store($varDir);
    $store->acquireLock();
    try {
        return (new Runner($config, $store))->run(ignoreSchedule: true);
    } finally {
        $store->releaseLock();
    }
}

// --- Header-Pruefung --------------------------------------------------------
// Der Testserver antwortet nur mit 200, wenn Referer und Origin ankommen --
// genau wie die echte API. Laeuft Lauf 1 durch, sind die Header unterwegs.
echo "\nAPI-Header\n" . str_repeat('-', 54) . "\n";
$bare = @file_get_contents($base . '/api/microservice/showings/cinemas/1391/films');
ok($bare === false, 'nackter Request wird vom Testserver abgewiesen (401, wie echt)');

// --- Lauf 1: Vorverkauf gesperrt (Embargo) --------------------------------
echo "\nLauf 1 -- Embargo, Vorverkauf noch nicht offen\n" . str_repeat('-', 54) . "\n";
scenario('embargo');
$r1 = runOnce($config, $varDir);

ok($r1['ran'], 'Lauf wurde ausgefuehrt');
ok(count($r1['screenings']) === 1, 'genau eine Vorstellung gefunden', 'gefunden: ' . count($r1['screenings']));
ok(($r1['screenings'][0]->status ?? null) === Status::Dated, 'Zustand ist DATED');
ok(($r1['screenings'][0]->source ?? null) === 'api',
    'Quelle ist die API -- die verlangten Header wurden also mitgeschickt');
ok(($r1['screenings'][0]->presaleStartsAt ?? null) === '2026-10-14T10:00:00',
    'Vorverkaufsstart aus embargoEndDate');
ok(($r1['screenings'][0]->startsAt ?? null) === '2026-11-07T12:00:00+01:00', 'echter Termin');

$newMatches = array_filter($r1['events'], static fn (Event $e) => $e->type === Event::NEW_MATCH);
ok(count($newMatches) === 1, 'genau ein new_match-Ereignis', 'Ereignisse: ' . count($r1['events']));

$state = json_decode((string)file_get_contents("$varDir/state.json"), true);
ok(($state['meta']['last_status'] ?? null) === 'DATED', 'Zustand wurde gespeichert');
ok(isset($state['meta']['next_check_at']), 'naechster Prueftermin wurde gesetzt');

// Der Hobbit ist im Kino buchbar -- darf aber nicht auftauchen.
$titles = array_map(static fn ($s) => $s->title, $r1['screenings']);
ok(!in_array('Der Hobbit: Smaugs Einoede', $titles, true),
    'buchbarer Hobbit loest keinen Marathon-Alarm aus');

ok($r1['errors'] !== [], 'fehlgeschlagener Mailversand wird als Fehler gemeldet');
ok(str_contains(implode(' ', $r1['errors']), 'SMTP'), 'Fehlermeldung nennt SMTP');

// --- Lauf 2: unveraendert ---------------------------------------------------
echo "\nLauf 2 -- unveraendert\n" . str_repeat('-', 54) . "\n";
$r2 = runOnce($config, $varDir);
ok($r2['events'] === [], 'keine Aenderung -> kein Ereignis, keine Mail',
    'Ereignisse: ' . count($r2['events']));

// --- Lauf 3: Vorverkauf startet --------------------------------------------
echo "\nLauf 3 -- Vorverkauf ist offen\n" . str_repeat('-', 54) . "\n";
scenario('bookable');
$r3 = runOnce($config, $varDir);

ok(($r3['screenings'][0]->status ?? null) === Status::Bookable, 'Zustand ist BOOKABLE');
$alarm = array_values(array_filter($r3['events'], static fn (Event $e) => $e->isAlarm()));
ok($alarm !== [], 'Hauptalarm wurde ausgeloest');
ok(($alarm[0]->previousStatus ?? null) === 'DATED',
    'Vorzustand DATED wird mitgeliefert -- der Fingerprint blieb stabil');
ok(str_contains((string)($r3['screenings'][0]->bookingUrl ?? ''), '/buchtickets/zusammenfassung/'),
    'Buchungslink zeigt in den echten Buchungsflow');

// --- Lauf 4: API meldet "unveraendert" (304) --------------------------------
// Der gefaehrlichste Fall: Ohne Daten darf NICHT gefolgert werden, dass die
// Vorstellung verschwunden ist.
echo "\nLauf 4 -- API antwortet 304\n" . str_repeat('-', 54) . "\n";
scenario('304');
$r4 = runOnce($config, $varDir);
$warnings = array_filter($r4['events'], static fn (Event $e) => $e->type === Event::WARNING);
ok($warnings === [], 'kein "verschwunden"-Fehlalarm bei fehlenden Daten',
    'Warnungen: ' . count($warnings));
$state = json_decode((string)file_get_contents("$varDir/state.json"), true);
ok(count($state['screenings']) === 1, 'gespeicherter Zustand bleibt erhalten');
ok(($state['meta']['last_status'] ?? null) === 'BOOKABLE', 'letzter bekannter Zustand bleibt BOOKABLE');

// --- Lauf 5: API faellt aus -> HTML-Rueckfallebene --------------------------
echo "\nLauf 5 -- API faellt aus\n" . str_repeat('-', 54) . "\n";
scenario('apifail');
$r5 = runOnce($config, $varDir);
ok(count($r5['screenings']) >= 1, 'die HTML-Rueckfallebene findet die Vorstellung trotzdem',
    'gefunden: ' . count($r5['screenings']));
ok(!in_array($r5['screenings'][0]->source ?? null, ['api', null], true),
    'Quelle ist jetzt die HTML-Ebene statt der API',
    'Quelle: ' . var_export($r5['screenings'][0]->source ?? null, true));
ok(str_contains(implode(' ', $r5['errors']), 'API'), 'der API-Ausfall wird als Fehler protokolliert');

// --- Lauf 6: ausverkauft ----------------------------------------------------
echo "\nLauf 6 -- ausverkauft\n" . str_repeat('-', 54) . "\n";
scenario('soldout');
$r6 = runOnce($config, $varDir);
ok(($r6['screenings'][0]->status ?? null) === Status::SoldOut, 'Zustand ist SOLD_OUT');

// --- Taktung ----------------------------------------------------------------
echo "\nTaktung\n" . str_repeat('-', 54) . "\n";
$state = json_decode((string)file_get_contents("$varDir/state.json"), true);
$next = strtotime((string)$state['meta']['next_check_at']);
ok($next > time() + 5 * 3600, 'nach SOLD_OUT wird deutlich seltener geprueft',
    'naechste Pruefung: ' . $state['meta']['next_check_at']);

$store = new Store($varDir);
$store->acquireLock();
$skipped = (new Runner($config, $store))->run();
$store->releaseLock();
ok(!$skipped['ran'], 'zu frueher Tick wird uebersprungen', $skipped['reason']);

// --- Aufraeumen -------------------------------------------------------------
scenario('embargo');
array_map('unlink', glob("$varDir/snapshots/*") ?: []);
@rmdir("$varDir/snapshots");
array_map('unlink', glob("$varDir/*") ?: []);
@rmdir($varDir);

echo "\n" . str_repeat('=', 60) . "\n";
printf("%d bestanden, %d fehlgeschlagen\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
