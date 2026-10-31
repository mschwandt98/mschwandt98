<?php
declare(strict_types=1);

/**
 * Minimaler PSR-4-Autoloader fuer den Namespace Optout\ -> src/.
 * Bewusst kein Composer: Das Werkzeug soll jahrelang per Cron laufen, ohne
 * dass ein vendor/-Verzeichnis gepflegt werden muss.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Optout\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
