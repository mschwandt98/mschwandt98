<?php
declare(strict_types=1);

/**
 * Gemeinsamer Start fuer alle Kommandozeilen-Werkzeuge.
 *
 * Die Versionspruefung laeuft VOR dem Autoloader und benutzt nur echo: Unter
 * einer zu alten PHP-Version soll eine lesbare Meldung kommen, kein Parse-Error.
 * Unter IONOS-CGI gibt es STDOUT/STDERR nicht -- deshalb Optout\Output.
 */
if (PHP_VERSION_ID < 80300) {
    echo sprintf(
        "Dieses Werkzeug setzt PHP 8.3 oder neuer voraus, laeuft aber unter %s.\n" .
        "Im Cron den vollen Pfad zur richtigen Version eintragen (z. B. /usr/bin/php8.5).\n",
        PHP_VERSION
    );
    exit(3);
}
foreach (['mbstring', 'openssl'] as $ext) {
    if (!extension_loaded($ext)) {
        echo "PHP-Extension '$ext' fehlt.\n";
        exit(3);
    }
}

require __DIR__ . '/../src/autoload.php';

/** Liest --config=<pfad> und laedt die Konfiguration. */
function optout_config(array $options): Optout\Config
{
    $config = Optout\Config::load(isset($options['config']) && is_string($options['config']) ? $options['config'] : null);
    date_default_timezone_set((string)$config->get('timezone'));
    return $config;
}

/** Spalte auffuellen -- sprintf("%-10s") zaehlt Bytes, "Löschung" ist dann zu kurz. */
function optout_pad(string $text, int $width): string
{
    $text = mb_strimwidth($text, 0, $width, '…');
    return $text . str_repeat(' ', max(0, $width - mb_strwidth($text)));
}
