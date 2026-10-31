#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 *   php bin/selftest.php                Umgebung und Konfiguration pruefen
 *   php bin/selftest.php --smtp         Testmail an notify.to schicken
 *   php bin/selftest.php --imap         Posteingang lesen (nur lesend)
 *   php bin/selftest.php --secret       zufaelliges Secret fuer tick/dashboard erzeugen
 */

require __DIR__ . '/bootstrap.php';

use Optout\App;
use Optout\Mail\Imap;
use Optout\Mail\OutgoingMessage;
use Optout\Mail\Smtp;
use Optout\Output;
use Optout\Registry\Registry;

$options = getopt('', ['config:', 'smtp', 'imap', 'secret']);
if (isset($options['secret'])) {
    Output::line(bin2hex(random_bytes(24)) . "\n");
    exit(0);
}

$fail = 0;
$check = static function (bool $ok, string $label, string $hint = '') use (&$fail): void {
    Output::line(($ok ? '  ok    ' : '  FEHLT ') . $label . ($ok || $hint === '' ? '' : "\n        → $hint") . "\n");
    if (!$ok) {
        $fail++;
    }
};

Output::line("Umgebung\n");
$check(true, 'PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ')');
$check(extension_loaded('openssl'), 'openssl');
$check(extension_loaded('mbstring'), 'mbstring');
$check(function_exists('iconv'), 'iconv (Rückfall für exotische Zeichensätze)', 'optional');
$check((bool)ini_get('allow_url_fopen'), 'allow_url_fopen (für bin/sync-db.php)', 'optional, nur für das Aktualisieren der Firmendaten');

$config = optout_config($options);
$var = $config->varDir();
$check(is_dir($var) && is_writable($var), "var/ beschreibbar ($var)");

Output::line("\nKonfiguration\n");
$problems = App::configProblems($config);
foreach ($problems as $p) {
    $check(false, $p);
}
if ($problems === []) {
    $check(true, 'Pflichtangaben vollständig');
}
$check((string)$config->get('imap.host') !== '', 'IMAP eingerichtet', 'Ohne Posteingang werden Antworten nicht ausgewertet. Es läuft dann alles in Erinnerungen.');
$authority = (string)$config->get('authority');
$registry = Registry::load($config);
$check($authority !== '' && $registry->authority($authority) !== null, "Aufsichtsbehörde '$authority'",
    'Für Beschwerde-Entwürfe. Möglich: ' . implode(', ', array_keys($registry->authorities())));
$selected = $registry->selected($config);
$check($selected !== [], count($selected) . ' Unternehmen ausgewählt');
foreach ($registry->warnings() as $w) {
    Output::line("  Warnung: $w\n");
}
$check((string)$config->get('tick_secret') === '' || strlen((string)$config->get('tick_secret')) >= 24, 'tick_secret lang genug', 'mindestens 24 Zeichen: php bin/selftest.php --secret');
$check((string)$config->get('dashboard_secret') === '' || strlen((string)$config->get('dashboard_secret')) >= 24, 'dashboard_secret lang genug', 'mindestens 24 Zeichen');
Output::line('  Modus: ' . ($config->live() ? "LIVE – Anfragen werden verschickt\n" : "Testmodus – es wird nichts verschickt\n"));

if (isset($options['smtp'])) {
    Output::line("\nSMTP\n");
    $smtp = new Smtp(App::smtpConfig($config));
    $to = (string)($config->get('notify.to') ?: $config->get('smtp.from'));
    $err = $smtp->send(new OutgoingMessage(
        to: [$to],
        subject: trim((string)$config->get('notify.subject_prefix') . ' Testmail'),
        text: "Wenn du das liest, funktioniert der Versand.\n\nLiegt die Mail im Spam, landen die Anfragen an die Firmen vermutlich auch dort. Siehe README, Abschnitt „Zustellbarkeit“.",
        messageId: OutgoingMessage::newId((string)$config->get('smtp.from')),
        autoSubmitted: true,
    ));
    $check($err === null, "Testmail an $to", (string)$err);
    if ($err !== null) {
        Output::line("\nProtokoll:\n" . $smtp->transcript());
    }
}

if (isset($options['imap'])) {
    Output::line("\nIMAP\n");
    $imap = new Imap((array)$config->get('imap'));
    try {
        $imap->open();
        $check(true, 'Anmeldung');
        foreach ((array)$config->get('imap.folders', ['INBOX']) as $folder) {
            $info = $imap->examine((string)$folder);
            $check(true, sprintf('%s: %d Nachrichten, UIDVALIDITY %d', $folder, $info['exists'], $info['uidvalidity']));
        }
    } catch (Throwable $e) {
        $check(false, 'IMAP', $e->getMessage());
        Output::line("\nProtokoll:\n" . $imap->transcript());
    } finally {
        $imap->close();
    }
}

Output::line($fail === 0 ? "\nAlles in Ordnung.\n" : "\n$fail Problem(e).\n");
exit($fail === 0 ? 0 : 1);
