#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Ermittelt, welche Request-Header die Showings-API verlangt.
 *
 *   php8.5 bin/probe-api.php
 *
 * Hintergrund: Vom Browser aus antwortet die API mit 200, einem nackten
 * Request vom Server aus mit 401. Der Endpunkt prueft also irgendetwas am
 * Request -- die Antwort traegt "Vary: Origin", was auf eine Herkunftspruefung
 * hindeutet. Welche Header genau noetig sind, ist nicht dokumentiert.
 *
 * Dieses Skript probiert die Kombinationen einzeln durch und sagt, welche
 * funktioniert. Das Ergebnis wird dann in config/config.php eingetragen.
 *
 * Es macht bewusst keine Annahmen und raet nichts -- es misst.
 */

require __DIR__ . '/../src/autoload.php';

use Kino\Config;

$configFile = dirname(__DIR__) . '/config/config.php';
$config = is_file($configFile) ? Config::load($configFile) : Config::fromArray([]);
date_default_timezone_set((string)$config->get('timezone'));

$base       = rtrim((string)$config->get('cinema.base_url'), '/');
$cinemaId   = (string)$config->get('cinema.id', '1391');
$programUrl = $base . (string)$config->get('cinema.program_path');
$apiUrl     = $base . '/api/microservice/showings/cinemas/' . rawurlencode($cinemaId)
            . '/films?minEmbargoLevel=1&includesSession=true&includeSessionAttributes=true';

$ownUa     = (string)$config->get('http.user_agent');
$browserUa = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) '
           . 'Chrome/140.0.0.0 Safari/537.36';

echo "Showings-API -- Header-Diagnose\n";
echo str_repeat('=', 72), "\n";
echo "URL: $apiUrl\n\n";

// Cookies der Programmseite einsammeln -- fuer die Varianten, die sie brauchen.
$cookieJar = tempnam(sys_get_temp_dir(), 'kinoprobe');
$warm = request($programUrl, [], $ownUa, $cookieJar, writeCookies: true);
printf("Programmseite vorab geladen: HTTP %d%s\n\n", $warm['status'],
    $warm['cookies'] > 0 ? ', ' . $warm['cookies'] . ' Cookie(s) erhalten' : ', keine Cookies');

$json     = ['Accept' => 'application/json, text/plain, */*'];
$referer  = ['Referer' => $programUrl];
$origin   = ['Origin' => $base];
$xhr      = ['X-Requested-With' => 'XMLHttpRequest'];
$lang     = ['Accept-Language' => 'de-DE,de;q=0.9'];

$variants = [
    'nackt (wie bisher)'                   => [[], $ownUa, false],
    'Accept: application/json'              => [$json, $ownUa, false],
    'Referer'                               => [$referer, $ownUa, false],
    'Referer + Origin'                      => [$referer + $origin, $ownUa, false],
    'Referer + Origin + Accept'             => [$referer + $origin + $json, $ownUa, false],
    'X-Requested-With'                      => [$xhr, $ownUa, false],
    'alle Header, eigener User-Agent'       => [$json + $referer + $origin + $xhr + $lang, $ownUa, false],
    'nur Browser-User-Agent'                => [[], $browserUa, false],
    'alle Header + Browser-User-Agent'      => [$json + $referer + $origin + $xhr + $lang, $browserUa, false],
    'alle Header + Browser-UA + Cookies'    => [$json + $referer + $origin + $xhr + $lang, $browserUa, true],
];

$winners = [];
foreach ($variants as $label => [$headers, $ua, $useCookies]) {
    $res = request($apiUrl, $headers, $ua, $cookieJar, useCookies: $useCookies);
    $ok  = $res['status'] === 200;
    if ($ok) {
        $winners[$label] = [$headers, $ua, $useCookies];
    }
    printf(
        "[%s] %-36s HTTP %-3d  %s\n",
        $ok ? ' ok ' : 'FEHL',
        $label,
        $res['status'],
        $ok ? strlen($res['body']) . ' Bytes' : firstLine($res['body'])
    );
    sleep(2); // hoeflich bleiben, auch beim Diagnostizieren
}

@unlink($cookieJar);

echo "\n", str_repeat('=', 72), "\n";

if ($winners === []) {
    echo "Keine Variante funktioniert.\n\n";
    echo "Dann liegt es nicht an den Headern. Moegliche Ursachen:\n";
    echo "  - Die API ist nur aus dem Browser erreichbar (Bot-Schutz vor dem Endpunkt).\n";
    echo "  - IONOS' ausgehende IP ist gesperrt.\n";
    echo "Der Watcher faellt dann auf die HTML-Rueckfallebene zurueck. Die funktioniert,\n";
    echo "ist aber fragiler. Bitte die Ausgabe oben zurueckmelden.\n";
    exit(1);
}

$label = array_key_first($winners);
[$headers, $ua, $useCookies] = $winners[$label];

echo "Es funktioniert: $label\n\n";
echo "Bitte in config/config.php eintragen:\n\n";
echo "    'http' => [\n";
if ($ua === $browserUa) {
    echo "        'user_agent' => '" . addslashes($ua) . "',\n";
    echo "        // Hinweis: Der Endpunkt verlangt einen Browser-User-Agent.\n";
    echo "        // Die Kontaktadresse geht damit verloren -- unschoen, aber\n";
    echo "        // ohne ihn antwortet die API nicht.\n";
}
echo "        'api_headers' => [\n";
foreach ($headers as $name => $value) {
    $tpl = $value === $programUrl ? '{program_url}' : ($value === $base ? '{base_url}' : $value);
    printf("            '%s' => '%s',\n", $name, addslashes($tpl));
}
echo "        ],\n";
if ($useCookies) {
    echo "        'warmup' => true,   // Programmseite vorab laden, Cookies noetig\n";
}
echo "    ],\n";

if (count($winners) > 1) {
    echo "\nEs funktionieren mehrere Varianten; oben steht die sparsamste.\n";
}
exit(0);

/**
 * @param array<string,string> $headers
 * @return array{status:int,body:string,cookies:int}
 */
function request(
    string $url,
    array $headers,
    string $userAgent,
    string $cookieJar,
    bool $writeCookies = false,
    bool $useCookies = false,
): array {
    $ch = curl_init($url);
    if ($ch === false) {
        return ['status' => 0, 'body' => 'curl_init fehlgeschlagen', 'cookies' => 0];
    }
    $list = [];
    foreach ($headers as $name => $value) {
        $list[] = $name . ': ' . $value;
    }
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => $userAgent,
        CURLOPT_HTTPHEADER     => $list,
        CURLOPT_ENCODING       => '',
    ];
    if ($writeCookies) {
        $opts[CURLOPT_COOKIEJAR] = $cookieJar;
    }
    if ($useCookies) {
        $opts[CURLOPT_COOKIEFILE] = $cookieJar;
    }
    curl_setopt_array($ch, $opts);

    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    $cookies = 0;
    if ($writeCookies && is_file($cookieJar)) {
        $cookies = count(array_filter(
            explode("\n", (string)file_get_contents($cookieJar)),
            static fn ($l) => $l !== '' && !str_starts_with($l, '#')
        ));
    }
    return [
        'status'  => $status,
        'body'    => $body === false ? ($err !== '' ? $err : 'Transferfehler') : (string)$body,
        'cookies' => $cookies,
    ];
}

function firstLine(string $body): string
{
    $line = trim(strtok($body, "\n") ?: '');
    $line = strip_tags($line);
    return $line === '' ? '(leere Antwort)' : mb_substr($line, 0, 60);
}
