#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Prueft die Umgebung, bevor man sich auf den Watcher verlaesst.
 *
 *   php8.5 bin/selftest.php              # nur pruefen
 *   php8.5 bin/selftest.php --send-mail  # zusaetzlich eine echte Testmail
 *   php8.5 bin/selftest.php --secret     # ein neues tick_secret erzeugen
 */

require __DIR__ . '/../src/autoload.php';

use Kino\Config;
use Kino\Http\Client;
use Kino\Notify\Mailer;

$options = getopt('', ['send-mail', 'secret', 'verbose']);

if (isset($options['secret'])) {
    fwrite(STDOUT, "Neues tick_secret (in config/config.php eintragen):\n\n");
    fwrite(STDOUT, bin2hex(random_bytes(32)) . "\n\n");
    exit(0);
}

$failures = 0;
$warnings = 0;

function check(string $label, bool $ok, string $detail = '', bool $fatal = true): void
{
    global $failures, $warnings;
    if (!$ok) {
        $fatal ? $failures++ : $warnings++;
    }
    printf("[%s] %-42s %s\n", $ok ? ' ok ' : ($fatal ? 'FEHL' : 'warn'), $label, $detail);
}

echo "Kino-Ticket-Watcher -- Selbsttest\n" . str_repeat('=', 72) . "\n\n";

echo "PHP\n";
check('Version >= 8.5', PHP_VERSION_ID >= 80500, PHP_VERSION);
foreach (['curl', 'dom', 'libxml', 'json', 'mbstring', 'openssl'] as $ext) {
    check("Extension $ext", extension_loaded($ext));
}
check('Zeitzone gesetzt', ini_get('date.timezone') !== '' || true, date_default_timezone_get(), fatal: false);

echo "\nKonfiguration\n";
try {
    $config = Config::load();
    check('config/config.php lesbar', true);
} catch (Throwable $e) {
    check('config/config.php lesbar', false, $e->getMessage());
    echo "\nOhne Konfiguration koennen die weiteren Tests nicht laufen.\n";
    exit(1);
}
date_default_timezone_set((string)$config->get('timezone'));

$secret = (string)$config->get('tick_secret');
check('tick_secret gesetzt', strlen($secret) >= 32,
    $secret === '' ? 'leer -- mit --secret erzeugen' : strlen($secret) . ' Zeichen');

foreach (['smtp.host', 'smtp.user', 'smtp.pass', 'smtp.from', 'smtp.to'] as $key) {
    check("$key gefuellt", trim((string)$config->get($key)) !== '');
}

echo "\nDateisystem\n";
$varDir = $config->varDir();
if (!is_dir($varDir)) {
    @mkdir($varDir, 0775, true);
}
check('var/ beschreibbar', is_dir($varDir) && is_writable($varDir), $varDir);
check('var/.htaccess vorhanden', is_file($varDir . '/.htaccess'),
    'schuetzt Zustand und Snapshots', fatal: false);
check('config/ nicht im Document Root', !isDocumentRootPath(dirname($varDir)),
    'sonst ist das SMTP-Passwort ueber den Browser lesbar', fatal: false);

echo "\nNetzwerk\n";
$http = new Client((string)$config->get('http.user_agent'), 15, 1, 0, respectRobots: false);
$base = rtrim((string)$config->get('cinema.base_url'), '/');

$robots = $http->get($base . '/robots.txt');
check('robots.txt erreichbar', $robots->ok(), $robots->error ?? 'HTTP ' . $robots->status, fatal: false);

$programUrl = $base . (string)$config->get('cinema.program_path');
$program = $http->get($programUrl);
check('Programmseite erreichbar', $program->ok(),
    $program->ok() ? strlen($program->body) . ' Bytes' : ($program->error ?? '?'));

$cinemaId = (string)$config->get('cinema.id', '');
if ($program->ok()) {
    $found = Kino\Parse\NextData::idsFromHtml($program->body)['cinemaId'];
    check('Kino-Kennung aus der Seite lesbar', $found !== null, $found ?? 'nicht gefunden', fatal: false);
    if ($cinemaId !== '' && $found !== null && $found !== $cinemaId) {
        check('Kino-Kennung stimmt mit der Konfiguration ueberein', false,
            "konfiguriert $cinemaId, Seite sagt $found");
    }
    $cinemaId = $cinemaId !== '' ? $cinemaId : (string)$found;
}

// Die Showings-API ist die eigentliche Quelle -- sie muss erreichbar sein.
if ($cinemaId === '') {
    check('Showings-API abfragbar', false, 'keine Kino-Kennung');
} else {
    $api = new Kino\Source\CinemaxxApi($http, $base);
    $apiRes = $http->get($api->sessionsUrl($cinemaId));
    check('Showings-API erreichbar', $apiRes->ok(),
        $apiRes->ok() ? strlen($apiRes->body) . ' Bytes' : ($apiRes->error ?? '?'));

    if ($apiRes->ok()) {
        $screenings = $api->parse($apiRes->body);
        check('API liefert Vorstellungen', $screenings !== [],
            count($screenings) . ' Vorstellung(en) im Kino', fatal: false);
        if ($screenings === []) {
            echo "       -> Null Vorstellungen loest im Betrieb die Canary-Warnung aus.\n";
            echo "          Bitte bin/discover.php laufen lassen und den Bericht pruefen.\n";
        }

        $matcher = new Kino\Match\TitleMatcher(
            (array)$config->get('watch_patterns'),
            (string)$config->get('date_window.from'),
            (string)$config->get('date_window.to'),
        );
        $hits = array_filter($screenings, static fn ($s) => $matcher->matches($s->title, $s->url));
        echo '       Treffer auf die Suchmuster: ' . count($hits) . "\n";
        foreach ($hits as $hit) {
            printf("         [%s] %s -- %s\n", $hit->status->value, $hit->title, $hit->when());
        }
    }
}

echo "\nSMTP\n";
$smtp = (array)$config->get('smtp');
if (trim((string)($smtp['host'] ?? '')) === '') {
    check('SMTP konfiguriert', false, 'host fehlt');
} else {
    $port = (int)($smtp['port'] ?? 587);
    $errno = 0; $errstr = '';
    $sock = @fsockopen(
        (strtolower((string)($smtp['encryption'] ?? 'tls')) === 'ssl' ? 'ssl://' : '') . $smtp['host'],
        $port, $errno, $errstr, 10
    );
    check("Port $port erreichbar", $sock !== false, $sock === false ? "$errstr ($errno)" : 'offen');
    if ($sock !== false) {
        fclose($sock);
    }
    if ($sock === false) {
        echo "       -> Manche Hoster sperren ausgehendes SMTP. Dann Port 465 (ssl) probieren\n";
        echo "          oder den SMTP-Server des Hosters verwenden.\n";
    }

    if (isset($options['send-mail'])) {
        $mailer = new Mailer($smtp);
        $error = $mailer->send(
            '[KINO-ALARM] Selbsttest',
            "Dies ist eine Testmail des Kino-Ticket-Watchers.\n\n"
            . "Wenn du sie liest, funktioniert der SMTP-Versand.\n"
            . "Bitte pruefe, ob sie im Posteingang und nicht im Spam gelandet ist.\n\n"
            . 'Zeitpunkt: ' . date('c') . "\n"
        );
        check('Testmail versandt', $error === null, $error ?? 'zugestellt an ' . $smtp['to']);
        if ($error !== null && isset($options['verbose'])) {
            echo "\n--- SMTP-Protokoll ---\n" . $mailer->transcript() . "\n";
        }
    } else {
        echo "       (echten Versand mit --send-mail testen)\n";
    }
}

echo "\n" . str_repeat('=', 72) . "\n";
printf("%d Fehler, %d Warnungen.\n", $failures, $warnings);
if ($failures === 0) {
    echo "Bereit. Naechster Schritt: php8.5 bin/watch.php --dry-run --verbose --ignore-schedule\n";
}
exit($failures === 0 ? 0 : 1);

function isDocumentRootPath(string $path): bool
{
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if ($docRoot === '') {
        return false; // im CLI meist unbekannt -- dann nicht falsch alarmieren
    }
    $real = realpath($path);
    $root = realpath($docRoot);
    return $real !== false && $root !== false && str_starts_with($real, $root);
}
