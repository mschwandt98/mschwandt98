<?php
declare(strict_types=1);

namespace Kino;

/**
 * Ausgabe, die in jeder PHP-Umgebung funktioniert.
 *
 * IONOS fuehrt php8.5 als CGI aus, nicht als CLI. Dort sind die Konstanten
 * STDOUT und STDERR **nicht definiert** -- ein fwrite(STDOUT, ...) ist dann
 * ein fataler Fehler, und der Aufrufer sieht nur "500 Internal Server Error".
 *
 * Deshalb wird hier nirgends auf diese Konstanten zugegriffen. php://output
 * gibt es in beiden Umgebungen; Fehler gehen in der CLI nach stderr, sonst
 * ebenfalls nach php://output -- denn php://stderr landet unter CGI in der
 * Logdatei des Webservers und waere ueber SSH unsichtbar.
 */
final class Output
{
    /** @var array<string,resource> */
    private static array $streams = [];

    public static function line(string $text): void
    {
        self::writeTo('php://output', $text);
    }

    public static function error(string $text): void
    {
        self::writeTo(self::errorTarget(), $text);
    }

    /**
     * Wohin Fehlermeldungen gehen.
     *
     * @param string|null $sapi nur fuer Tests; sonst die laufende Umgebung
     */
    public static function errorTarget(?string $sapi = null): string
    {
        return ($sapi ?? PHP_SAPI) === 'cli' ? 'php://stderr' : 'php://output';
    }

    private static function writeTo(string $target, string $text): void
    {
        if (!isset(self::$streams[$target])) {
            $handle = fopen($target, 'w');
            if ($handle === false) {
                // Letzter Ausweg: Wenigstens etwas ausgeben, statt zu scheitern.
                echo $text;
                return;
            }
            self::$streams[$target] = $handle;
        }
        fwrite(self::$streams[$target], $text);
    }
}
