<?php
declare(strict_types=1);

/**
 * Uebersicht im Browser:  https://deine-subdomain/?key=<dashboard_secret>
 *
 * Ohne gesetztes dashboard_secret (mind. 24 Zeichen) ist die Seite aus.
 */

$appDir = dirname(__DIR__);

if (PHP_VERSION_ID < 80300) {
    http_response_code(500);
    exit("Falsche PHP-Version.\n");
}

require $appDir . '/src/autoload.php';

use Optout\Cases\Store;
use Optout\Config;
use Optout\Registry\Registry;
use Optout\Web\Dashboard;

function notFound(): never
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit("Not Found\n");
}

try {
    $config = Config::load($appDir . '/config/config.php');
} catch (Throwable) {
    notFound();
}

$expected = (string)$config->get('dashboard_secret');
$given = (string)($_POST['key'] ?? $_GET['key'] ?? '');
if (strlen($expected) < 24 || !hash_equals($expected, $given)) {
    notFound();
}

date_default_timezone_set((string)$config->get('timezone'));
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'");

$store = new Store($config->varDir());
$dashboard = new Dashboard($config, $store, Registry::load($config), $expected);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $flash = $dashboard->handlePost($_POST, time());
    // Post/Redirect/Get: Neu laden fuehrt die Aktion nicht ein zweites Mal aus.
    $view = (string)($_GET['view'] ?? '');
    header('Location: ?' . http_build_query(['key' => $expected, 'flash' => $flash] + ($view !== '' ? ['view' => $view] : [])), true, 303);
    exit;
}

echo $dashboard->render(mb_substr((string)($_GET['flash'] ?? ''), 0, 200), (string)($_GET['view'] ?? ''));
