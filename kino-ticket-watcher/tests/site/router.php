<?php
declare(strict_types=1);

/**
 * Router fuer den eingebauten PHP-Webserver. Liefert die Fixtures unter den
 * echten CinemaxX-Pfaden aus, damit der Integrationstest die vollstaendige
 * Kette inklusive HTTP, robots.txt und Sitemap durchlaufen kann.
 *
 * Welche Fixture die Filmseite ausliefert, steht in current.txt -- so laesst
 * sich im Test der Zustandswechsel "noch nicht buchbar" -> "buchbar" ausloesen.
 */

$fixtures = __DIR__ . '/../fixtures';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$film = '/kinoprogramm/magdeburg/film/der-herr-der-ringe-triple-extended-version';

header('Content-Type: text/html; charset=UTF-8');

switch ($path) {
    case '/robots.txt':
        header('Content-Type: text/plain; charset=UTF-8');
        echo "User-agent: *\nDisallow: /intern/\n";
        return true;

    case '/sitemap.xml':
        header('Content-Type: application/xml; charset=UTF-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ([$film, '/kinoprogramm/magdeburg/film/der-hobbit-smaugs-einoede'] as $loc) {
            echo '<url><loc>http://' . $_SERVER['HTTP_HOST'] . $loc . '</loc></url>';
        }
        echo '</urlset>';
        return true;

    case '/kinoprogramm/magdeburg':
        readfile($fixtures . '/program.html');
        return true;

    case $film:
        $current = trim(@file_get_contents(__DIR__ . '/current.txt') ?: 'dated');
        readfile($fixtures . '/' . basename($current) . '.html');
        return true;

    case '/kinoprogramm/magdeburg/film/der-hobbit-smaugs-einoede':
        readfile($fixtures . '/other-film.html');
        return true;
}

http_response_code(404);
echo 'Not Found';
return true;
