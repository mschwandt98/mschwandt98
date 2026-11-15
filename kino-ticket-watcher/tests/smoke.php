#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Prueft die Entrypoints selbst -- die Lueckenklasse, die zweimal zugeschlagen
 * hat:
 *
 *  1. fwrite(STDOUT, ...) ist unter CGI ein fataler Fehler, weil die Konstante
 *     dort nicht existiert. Sichtbar war nur "500 Internal Server Error".
 *  2. Ein Aufruf von Kino\Output VOR dem require des Autoloaders schlaegt mit
 *     "Class not found" fehl -- genau in dem Pfad, der melden soll, dass die
 *     PHP-Version zu alt ist.
 *
 * Beides faellt keinem Unit-Test auf, der nur Klassen testet. Hier wird
 * deshalb der Quelltext der Entrypoints geprueft und sie werden ausgefuehrt.
 *
 *   php tests/smoke.php
 */

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;

/** Entfernt Kommentare, damit Pruefungen den Code lesen und nicht die Prosa. */
function stripComments(string $code): string
{
    $out = '';
    foreach (token_get_all($code) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $out .= $token[1];
        } else {
            $out .= $token;
        }
    }
    return $out;
}

function ok(bool $cond, string $label, string $detail = ''): void
{
    global $pass, $fail;
    $cond ? $pass++ : $fail++;
    echo '  ' . ($cond ? 'ok  ' : 'FAIL') . ' ' . $label
        . (!$cond && $detail !== '' ? "  ($detail)" : '') . "\n";
}

// --- 1. Keine CLI-Konstanten im Code ----------------------------------------
echo "\nCLI-Konstanten\n" . str_repeat('-', 54) . "\n";

$phpFiles = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($it as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $phpFiles[] = $file->getPathname();
    }
}

$offenders = [];
foreach ($phpFiles as $file) {
    if (str_ends_with($file, 'src/Output.php') || str_contains($file, '/tests/')) {
        continue; // Output.php erklaert sie, Tests laufen immer in der CLI
    }
    $code = (string)file_get_contents($file);
    if (preg_match('/\b(STDOUT|STDERR|STDIN)\b/', $code)) {
        $offenders[] = substr($file, strlen($root) + 1);
    }
}
ok($offenders === [], 'kein Zugriff auf STDOUT/STDERR/STDIN',
    'betroffen: ' . implode(', ', $offenders));

// --- 2. Nichts aus dem Projekt vor dem Autoloader ---------------------------
echo "\nReihenfolge in den Entrypoints\n" . str_repeat('-', 54) . "\n";

$entrypoints = ['bin/watch.php', 'bin/selftest.php', 'bin/discover.php',
                'bin/probe-api.php', 'public/tick.php'];

foreach ($entrypoints as $relative) {
    $code = (string)file_get_contents($root . '/' . $relative);
    $pos = strpos($code, 'autoload.php');
    if ($pos === false) {
        ok(false, "$relative laedt den Autoloader");
        continue;
    }
    // Kommentare entfernen: Der Text erklaert diese Regel teilweise selbst und
    // wuerde sie dann scheinbar brechen. Geprueft wird Code, nicht Prosa.
    $before = stripComments(substr($code, 0, $pos));
    $usesProjectCode = (bool)preg_match('/\b(Kino\\\\[A-Z]|Output::|Config::|Store::|Runner::)/', $before);
    ok(!$usesProjectCode, "$relative nutzt vor dem Autoloader keine Projektklasse",
        trim(preg_replace('/\s+/', ' ', $before) ?? ''));
}

// --- 3. Entrypoints wirklich starten ----------------------------------------
echo "\nAusfuehrung\n" . str_repeat('-', 54) . "\n";

$php = PHP_BINARY;
$tooOld = PHP_VERSION_ID < 80500;

/** @return array{out:string,code:int} */
function run(string $cmd): array
{
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return ['out' => implode("\n", $out), 'code' => $code];
}

$cases = [
    'bin/watch.php --help'      => $tooOld ? 3 : 0,
    'bin/selftest.php --secret' => 0,
];
foreach ($cases as $args => $expectedCode) {
    $res = run(escapeshellarg($php) . ' ' . escapeshellarg($root . '/' . strtok($args, ' '))
        . ' ' . substr($args, (int)strpos($args, ' ') + 1));
    $noFatal = !str_contains($res['out'], 'Fatal error')
            && !str_contains($res['out'], 'Uncaught');
    ok($noFatal, "$args laeuft ohne fatalen Fehler", substr($res['out'], 0, 160));
    ok($res['code'] === $expectedCode, "$args endet mit Exit-Code $expectedCode",
        'war: ' . $res['code']);
}

if ($tooOld) {
    echo "\n  Hinweis: PHP " . PHP_VERSION . " hier -- die Entrypoints verlangen 8.5 und\n";
    echo "  brechen deshalb an der Versionssperre ab. Genau dieser Pfad wird geprueft.\n";
}

// --- 4. tick.php verraet sich nicht -----------------------------------------
echo "\ntick.php\n" . str_repeat('-', 54) . "\n";
$tick = (string)file_get_contents($root . '/public/tick.php');
ok(str_contains($tick, 'hash_equals'), 'Secret wird zeitkonstant verglichen');
ok(str_contains($tick, '404'), 'antwortet mit 404 statt 403 -- verraet die Datei nicht');
$tickCode = stripComments($tick);
ok(!preg_match('/[\'"](?:pass|password|smtp|secret)[\'"]\s*=>/i', $tickCode),
    'enthaelt selbst keine Zugangsdaten');
ok(substr_count($tick, "\n") < 120, 'ist ein schlanker Stub, keine zweite Anwendung',
    substr_count($tick, "\n") . ' Zeilen');

echo "\n" . str_repeat('=', 60) . "\n";
printf("%d bestanden, %d fehlgeschlagen\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
