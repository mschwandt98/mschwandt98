<?php
declare(strict_types=1);

/**
 * Router fuer den eingebauten PHP-Webserver. Bildet die echten CinemaxX-Pfade
 * nach -- sowohl die Showings-API als auch die HTML-Seiten -- damit der
 * Integrationstest die vollstaendige Kette durchlaufen kann.
 *
 * Welches Szenario ausgeliefert wird, steht in current.txt:
 *   embargo | bookable | soldout | empty   -> API-Fixture
 *   304                                     -> API antwortet "unveraendert"
 *   apifail                                 -> API antwortet 500 (HTML-Rueckfall)
 */

$fixtures = __DIR__ . '/../fixtures';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$film = '/kinoprogramm/magdeburg/film/der-herr-der-ringe-triple-extended-version';
$scenario = trim(@file_get_contents(__DIR__ . '/current.txt') ?: 'embargo');

if ($path === '/api/microservice/showings/cinemas/1391/films') {
    // Die echte API antwortet einem nackten Request mit 401 und prueft die
    // Herkunft. Das wird hier nachgebildet, damit der Test beweist, dass der
    // Runner die noetigen Header wirklich mitschickt.
    if ($scenario !== 'noauthcheck'
        && (empty($_SERVER['HTTP_REFERER']) || empty($_SERVER['HTTP_ORIGIN']))) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Unauthorized';
        exit;
    }
    if ($scenario === 'apifail') {
        http_response_code(500);
        exit;
    }
    if ($scenario === '304') {
        http_response_code(304);
        exit;
    }
    $file = $fixtures . '/api/cinema-1391-' . basename($scenario) . '.json';
    if (!is_file($file)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=60');
    readfile($file);
    return true;
}

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
        // Fuer die HTML-Rueckfallebene: buchbar, sobald das Szenario es sagt.
        $html = in_array($scenario, ['bookable', 'apifail'], true) ? 'bookable' : 'dated';
        readfile($fixtures . '/' . $html . '.html');
        return true;

    case '/kinoprogramm/magdeburg/film/der-hobbit-smaugs-einoede':
        readfile($fixtures . '/other-film.html');
        return true;
}

http_response_code(404);
echo 'Not Found';
return true;
