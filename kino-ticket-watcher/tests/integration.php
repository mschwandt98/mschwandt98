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
    'cinema'  => ['base_url' => $base, 'program_path' => '/kinoprogramm/magdeburg', 'city' => 'magdeburg'],
    'known_urls' => [],   // ausdruecklich leer: Auffinden muss ueber Programm/Sitemap klappen
    'var_dir' => $varDir,
    'http'    => ['min_interval_per_host' => 0, 'retries' => 0, 'timeout' => 5],
    'smtp'    => ['host' => '', 'user' => '', 'pass' => '', 'from' => '', 'to' => ''],
]);

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

// --- Lauf 1: Vorverkauf noch nicht gestartet --------------------------------
echo "\nLauf 1 -- Vorverkauf angekuendigt, noch nicht buchbar\n" . str_repeat('-', 54) . "\n";
file_put_contents($docRoot . '/current.txt', 'dated');
$r1 = runOnce($config, $varDir);

ok($r1['ran'], 'Lauf wurde ausgefuehrt');
ok(count($r1['screenings']) === 1, 'genau eine Vorstellung gefunden', 'gefunden: ' . count($r1['screenings']));
ok(($r1['screenings'][0]->status ?? null) === Status::Dated, 'Zustand ist DATED');
ok(($r1['screenings'][0]->presaleStartsAt ?? null) === '2026-10-14T10:00:00', 'Vorverkaufsdatum erkannt');
$newMatches = array_filter($r1['events'], static fn (Event $e) => $e->type === Event::NEW_MATCH);
ok(count($newMatches) === 1, 'genau ein new_match-Ereignis', 'Ereignisse: ' . count($r1['events']));

$state = json_decode((string)file_get_contents("$varDir/state.json"), true);
ok(($state['meta']['last_status'] ?? null) === 'DATED', 'Zustand wurde gespeichert');
ok(isset($state['meta']['next_check_at']), 'naechster Prueftermin wurde gesetzt');

// Der Hobbit liegt im Programm und ist buchbar -- darf aber nicht auftauchen.
$titles = array_map(static fn ($s) => $s->title, $r1['screenings']);
ok(!in_array('Der Hobbit: Smaugs Einoede', $titles, true),
    'buchbarer Hobbit loest keinen Marathon-Alarm aus');

// Mailversand ist bewusst unkonfiguriert -- der Fehler muss auffallen.
ok($r1['errors'] !== [], 'fehlgeschlagener Mailversand wird als Fehler gemeldet');
ok(str_contains(implode(' ', $r1['errors']), 'SMTP'), 'Fehlermeldung nennt SMTP');

// --- Lauf 2: unveraendert ---------------------------------------------------
echo "\nLauf 2 -- unveraendert\n" . str_repeat('-', 54) . "\n";
$r2 = runOnce($config, $varDir);
ok($r2['events'] === [], 'keine Aenderung -> kein Ereignis, keine Mail',
    'Ereignisse: ' . count($r2['events']));

// --- Lauf 3: Vorverkauf startet --------------------------------------------
echo "\nLauf 3 -- Vorverkauf ist offen\n" . str_repeat('-', 54) . "\n";
file_put_contents($docRoot . '/current.txt', 'bookable');
$r3 = runOnce($config, $varDir);

ok(($r3['screenings'][0]->status ?? null) === Status::Bookable, 'Zustand ist BOOKABLE');
$alarm = array_values(array_filter($r3['events'], static fn (Event $e) => $e->isAlarm()));
ok($alarm !== [], 'Hauptalarm wurde ausgeloest');
ok(($alarm[0]->previousStatus ?? null) === 'DATED', 'Vorzustand DATED wird mitgeliefert');
ok(str_contains((string)($r3['screenings'][0]->bookingUrl ?? ''), '/vorstellung/'),
    'Buchungslink zeigt in den Buchungsflow');

// --- Lauf 4: ausverkauft ----------------------------------------------------
echo "\nLauf 4 -- ausverkauft\n" . str_repeat('-', 54) . "\n";
file_put_contents($docRoot . '/current.txt', 'soldout');
$r4 = runOnce($config, $varDir);
ok(($r4['screenings'][0]->status ?? null) === Status::SoldOut, 'Zustand ist SOLD_OUT');
ok(count(array_filter($r4['events'], static fn (Event $e) => $e->type === Event::STATUS_CHANGED)) === 1,
    'Uebergang BOOKABLE -> SOLD_OUT wird gemeldet');

// --- Taktung ----------------------------------------------------------------
echo "\nTaktung\n" . str_repeat('-', 54) . "\n";
$state = json_decode((string)file_get_contents("$varDir/state.json"), true);
$next = strtotime((string)$state['meta']['next_check_at']);
ok($next > time() + 5 * 3600, 'nach SOLD_OUT wird deutlich seltener geprueft',
    'naechste Pruefung: ' . $state['meta']['next_check_at']);

// Und der Scheduler muss einen zu fruehen Tick abweisen.
$store = new Store($varDir);
$store->acquireLock();
$skipped = (new Runner($config, $store))->run();
$store->releaseLock();
ok(!$skipped['ran'], 'zu frueher Tick wird uebersprungen', $skipped['reason']);

// --- Aufraeumen -------------------------------------------------------------
file_put_contents($docRoot . '/current.txt', "dated\n");
array_map('unlink', glob("$varDir/snapshots/*") ?: []);
@rmdir("$varDir/snapshots");
array_map('unlink', glob("$varDir/*") ?: []);
@rmdir($varDir);

echo "\n" . str_repeat('=', 60) . "\n";
printf("%d bestanden, %d fehlgeschlagen\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
