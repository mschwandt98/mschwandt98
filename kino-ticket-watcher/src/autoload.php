<?php
declare(strict_types=1);

/**
 * Minimaler PSR-4-Autoloader fuer den Namespace Kino\ -> src/.
 * Bewusst kein Composer: Das Skript soll 11 Monate im Standby stehen,
 * ohne dass ein vendor/-Verzeichnis gepflegt werden muss.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Kino\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
