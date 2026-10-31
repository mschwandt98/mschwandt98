<?php
declare(strict_types=1);

/**
 * Web-Entrypoint fuer einen externen Taktgeber (cron-job.org), falls der
 * Hoster keinen echten Crontab bietet. Geschuetzt durch tick_secret.
 */

$appDir = dirname(__DIR__);

if (PHP_VERSION_ID < 80300) {
    http_response_code(500);
    exit("Falsche PHP-Version.\n");
}

require $appDir . '/src/autoload.php';

use Optout\App;
use Optout\Cases\Store;
use Optout\Config;

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

$expected = (string)$config->get('tick_secret');
$given = (string)($_GET['key'] ?? '');
if (strlen($expected) < 24 || !hash_equals($expected, $given)) {
    notFound();
}

date_default_timezone_set((string)$config->get('timezone'));
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
ignore_user_abort(true);
set_time_limit(300);

$store = null;
try {
    $store = new Store($config->varDir());
    if (!$store->acquireLock()) {
        exit("busy\n");
    }
    $r = App::runner($config, $store)->run(time());
    printf("ok sent=%d reminded=%d replies=%d attention=%d errors=%d\n",
        $r['sent'], $r['reminded'], $r['replies'], $r['attention'], count($r['errors']));
    if ($r['errors'] !== []) {
        http_response_code(500);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo "error\n";
    error_log('datenbroker-loeschung: ' . $e->getMessage());
} finally {
    $store?->releaseLock();
}
