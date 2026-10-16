#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Schlanker Testlaeufer -- bewusst ohne PHPUnit, damit das Projekt ohne
 * Composer auskommt.
 *
 *   php tests/run.php
 *
 * Laeuft ab PHP 8.3. Die Produktiv-Entrypoints verlangen 8.5; der
 * Bibliothekscode bleibt syntaktisch aeltere-PHP-kompatibel, damit die Tests
 * auch auf einer Entwicklungsmaschine ohne 8.5 durchlaufen.
 */

require __DIR__ . '/../src/autoload.php';

use Kino\Config;
use Kino\Decide\Event;
use Kino\Decide\Transitions;
use Kino\Http\Client;
use Kino\Http\Robots;
use Kino\Match\TitleMatcher;
use Kino\Model\Screening;
use Kino\Model\Status;
use Kino\Notify\Mailer;
use Kino\Notify\Templates;
use Kino\Parse\Dom;
use Kino\Parse\JsonLd;
use Kino\Parse\Normalize;
use Kino\Scheduler;
use Kino\Source\Discovery;
use Kino\Source\CinemaxxApi;
use Kino\Parse\NextData;
use Kino\Source\FilmPage;
use Kino\State\Store;

date_default_timezone_set('Europe/Berlin');

final class T
{
    public static int $pass = 0;
    public static int $fail = 0;
    private static string $group = '';

    public static function group(string $name): void
    {
        self::$group = $name;
        echo "\n$name\n" . str_repeat('-', strlen($name)) . "\n";
    }

    public static function ok(bool $cond, string $label, string $detail = ''): void
    {
        if ($cond) {
            self::$pass++;
            echo "  ok   $label\n";
            return;
        }
        self::$fail++;
        echo "  FAIL $label" . ($detail !== '' ? "  ($detail)" : '') . "\n";
    }

    public static function same(mixed $expected, mixed $actual, string $label): void
    {
        self::ok(
            $expected === $actual,
            $label,
            'erwartet ' . var_export($expected, true) . ', bekommen ' . var_export($actual, true)
        );
    }
}

$fixtures = __DIR__ . '/fixtures';
$config   = Config::fromArray([]);
$matcher  = new TitleMatcher(
    (array)$config->get('watch_patterns'),
    (string)$config->get('date_window.from'),
    (string)$config->get('date_window.to'),
);
$filmPage = new FilmPage(
    new Client('test', 1, 0, 0, false),
    (array)$config->get('booking_link_patterns'),
);

// ---------------------------------------------------------------- Normalize

T::group('Normalize');
T::same('der herr der ringe triple', Normalize::forFingerprint('Der Herr der Ringe Triple'),
    'Artikel bleibt erhalten, nur Zusaetze fliegen raus');
T::same(
    Normalize::forFingerprint('Der Herr der Ringe Triple (Extended Edition)'),
    Normalize::forFingerprint('Der Herr der Ringe Triple - Extended Version'),
    'kosmetische Fassungszusaetze ergeben denselben Fingerprint'
);
T::same(
    Normalize::forFingerprint('Der Herr der Ringe Triple OV'),
    Normalize::forFingerprint('Der Herr der Ringe Triple 3D'),
    'Format- und Sprachzusaetze ergeben denselben Fingerprint'
);
T::same('fuer groessere staedte', Normalize::title('Für grössere Städte'), 'Umlaute werden entfaltet');

// Regression: Dieselbe Vorstellung erreicht uns je nach Quelle mit oder ohne
// Uhrzeit. Ginge die Uhrzeit in den Fingerprint ein, wuerde ein Wechsel der
// Quelle den Uebergang "-> buchbar" verschlucken.
$fromText   = new Screening('Der Herr der Ringe Triple', 'u', Status::Dated, '2026-12-13');
$fromJsonLd = new Screening('Der Herr der Ringe Triple', 'u', Status::Bookable, '2026-12-13T11:00:00+01:00');
T::same($fromText->fingerprint(), $fromJsonLd->fingerprint(),
    'Datum mit und ohne Uhrzeit ergibt denselben Fingerprint');
$otherDay = new Screening('Der Herr der Ringe Triple', 'u', Status::Dated, '2026-12-14');
T::ok($fromText->fingerprint() !== $otherDay->fingerprint(),
    'ein anderer Tag ergibt dagegen einen anderen Fingerprint');
T::ok(
    Normalize::digest('<p>Hallo</p><script>var x=1</script>') === Normalize::digest('<p>Hallo</p><script>var x=2</script>'),
    'Script-Inhalte fliessen nicht in den Digest ein'
);
T::ok(
    Normalize::digest('<p>Hallo</p>') !== Normalize::digest('<p>Hallo Welt</p>'),
    'echte Textaenderung aendert den Digest'
);

// -------------------------------------------------------------- TitleMatcher

T::group('TitleMatcher');
T::ok($matcher->matches('Der Herr der Ringe Triple - Extended Version'), 'Marathon-Titel trifft');
T::ok($matcher->matches('The Lord of the Rings Marathon'), 'englischer Titel trifft');
T::ok($matcher->matchesUrl('/kinoprogramm/magdeburg/film/der-herr-der-ringe-triple-extended-version'),
    'Slug in der URL trifft');
T::ok(!$matcher->matches('Der Hobbit: Smaugs Einoede'),
    'eine normale Hobbit-Vorstellung loest KEINEN Marathon-Alarm aus');
T::ok(!$matcher->matches('Irgendein Blockbuster'), 'beliebiger Film trifft nicht');
T::ok($matcher->matches('Der Hobbit Trilogie Marathon'),
    'ein echter Hobbit-Marathon trifft dagegen sehr wohl');
T::ok($matcher->inDateWindow('2026-12-13'), 'Dezember liegt im Zeitfenster');
T::ok($matcher->inDateWindow('2027-01-08'), 'Januar liegt im Zeitfenster (Jahreswechsel)');
T::ok(!$matcher->inDateWindow('2026-05-04'), 'Mai liegt ausserhalb');
T::ok($matcher->inDateWindow(null), 'unbekannter Termin wird nicht vorschnell verworfen');

// ------------------------------------------------------------------ Dom/Parse

T::group('Dom / JSON-LD');
T::same(['2026-12-13'], Dom::dates('Termin: 13.12.2026 um 11:00 Uhr'), 'numerisches Datum');
T::same(['2026-12-13'], Dom::dates('Termin: 13. Dezember 2026'), 'ausgeschriebener Monat');
T::same('2026-10-14T10:00:00', Dom::presaleStart('Der Vorverkauf startet am 14.10.2026 um 10:00 Uhr.'),
    'Vorverkaufsdatum inklusive Uhrzeit');
T::ok(Dom::presaleStart('Nichts Besonderes hier.') === null, 'kein Vorverkaufsdatum -> null');
T::ok(Dom::looksSoldOut('Die Vorstellung ist leider ausverkauft'), 'Ausverkauft-Marker');
T::ok(!Dom::looksSoldOut('Noch Tickets verfuegbar'), 'kein falscher Ausverkauft-Treffer');
T::same('Der Herr der Ringe Triple - Extended Version',
    Dom::heading(file_get_contents("$fixtures/bookable.html")), 'h1 wird gelesen');
T::same(1, count(array_filter(JsonLd::extract(file_get_contents("$fixtures/bookable.html")), [JsonLd::class, 'isEvent'])),
    'JSON-LD-Event wird gefunden');

// ------------------------------------------------------------------ FilmPage

T::group('FilmPage -- Zustandserkennung');
$url = 'https://www.cinemaxx.de/kinoprogramm/magdeburg/film/der-herr-der-ringe-triple-extended-version';

$dated = $filmPage->parse(file_get_contents("$fixtures/dated.html"), $url);
T::same(1, count($dated), 'dated.html ergibt eine Vorstellung');
T::same(Status::Dated, $dated[0]->status, 'Vorverkauf noch nicht gestartet -> DATED');
T::same('2026-10-14T10:00:00', $dated[0]->presaleStartsAt, 'Vorverkaufsdatum uebernommen');
T::same('2026-12-13', $dated[0]->startsAt,
    'Vorstellungstermin, NICHT der frueher liegende Vorverkaufsstart');

$bookable = $filmPage->parse(file_get_contents("$fixtures/bookable.html"), $url);
T::same(Status::Bookable, $bookable[0]->status, 'availability=InStock -> BOOKABLE');
T::same('json_ld', $bookable[0]->source, 'JSON-LD wird der Heuristik vorgezogen');
T::ok(str_contains((string)$bookable[0]->bookingUrl, '/vorstellung/'), 'Buchungslink aus dem Angebot');

$soldOut = $filmPage->parse(file_get_contents("$fixtures/soldout.html"), $url);
T::same(Status::SoldOut, $soldOut[0]->status, 'availability=SoldOut -> SOLD_OUT');

$other = $filmPage->parse(file_get_contents("$fixtures/other-film.html"),
    'https://www.cinemaxx.de/kinoprogramm/magdeburg/film/der-hobbit-smaugs-einoede');
T::same(Status::Bookable, $other[0]->status, 'auch der Hobbit wird korrekt als buchbar erkannt');
T::ok(!$matcher->matches($other[0]->title, $other[0]->url),
    '... faellt aber durch den Titelfilter und loest keinen Alarm aus');

// ------------------------------------------------------- CinemaxX-API (echt)

T::group('CinemaxX Showings-API');
$api = new CinemaxxApi(new Client('test', 1, 0, 0, false), 'https://www.cinemaxx.de');

T::same(
    'https://www.cinemaxx.de/api/microservice/showings/cinemas/1391/films'
    . '?minEmbargoLevel=1&includesSession=true&includeSessionAttributes=true',
    $api->sessionsUrl('1391'),
    'URL fuer das ganze Kino'
);
T::ok(str_contains($api->sessionsUrl('1391', 'HO00001617'), 'filmId=HO00001617'),
    'URL mit Filmfilter');

// Die folgenden Fixtures stammen aus einer echten HAR-Aufzeichnung der Seite.
$bookable = $api->parse(file_get_contents("$fixtures/api/cinema-1391-bookable.json"));
T::same(1, count($bookable), 'echte Antwort ergibt eine Vorstellung');
T::same(Status::Bookable, $bookable[0]->status, 'isBookingAvailable=true -> BOOKABLE');
T::same('Der Herr der Ringe Triple (Extended Version)', $bookable[0]->title, 'Titel aus filmTitle');
T::same('2026-11-07T12:00:00+01:00', $bookable[0]->startsAt, 'Startzeit mit Zeitzone');
T::same('07.11.2026 12:00', $bookable[0]->when(), 'Termin wird in Ortszeit dargestellt');
T::same('https://www.cinemaxx.de/buchtickets/zusammenfassung/1391/HO00001617/44604',
    $bookable[0]->bookingUrl, 'Buchungslink wird absolut gemacht');
T::ok(str_contains((string)$bookable[0]->note, 'Kino 07'), 'Saal steht im Zusatz');
T::ok(!str_contains($bookable[0]->title, 'Kino 07'),
    'Saal steht NICHT im Titel -- er wuerde den Fingerprint destabilisieren');

$embargo = $api->parse(file_get_contents("$fixtures/api/cinema-1391-embargo.json"));
T::same(Status::Dated, $embargo[0]->status, 'Embargo -> DATED, noch nicht buchbar');
T::same('2026-10-14T10:00:00', $embargo[0]->presaleStartsAt,
    'embargoEndDate ist der Vorverkaufsstart');
T::same($bookable[0]->fingerprint(), $embargo[0]->fingerprint(),
    'Embargo und buchbar sind dieselbe Vorstellung');

$sold = $api->parse(file_get_contents("$fixtures/api/cinema-1391-soldout.json"));
T::same(Status::SoldOut, $sold[0]->status, 'isSoldOut=true -> SOLD_OUT');

T::same(0, count($api->parse(file_get_contents("$fixtures/api/cinema-1391-empty.json"))),
    'leeres Ergebnis ergibt keine Vorstellung');
T::same(0, count($api->parse('kein json')), 'Schrott-Antwort stuerzt nicht ab');
T::same(0, count($api->parse('{"result":null}')), 'result=null stuerzt nicht ab');

T::ok($matcher->matches($bookable[0]->title, $bookable[0]->url),
    'der echte Titel passt auf die konfigurierten Suchmuster');
T::ok($matcher->inDateWindow($bookable[0]->startsAt),
    'der echte Termin (7. November) liegt im Zeitfenster');

T::same('HO00001617', $bookable[0]->filmId, 'Film-Kennung wird uebernommen');
T::ok(str_contains($api->sessionsUrl('1391', $bookable[0]->filmId), 'filmId=HO00001617'),
    'mit der Kennung kann gezielt gefragt werden statt das ganze Kino zu laden');

T::group('API-Header');
$defaults = (array)Config::fromArray([])->get('http.api_headers');
T::ok(!isset($defaults['Origin']),
    'kein Origin in den Vorgaben -- die API antwortet damit gemessen immer 401');
T::ok(str_contains((string)($defaults['Accept'] ?? ''), 'json'),
    'Accept verlangt JSON, nicht HTML');

$resolved = CinemaxxApi::resolveHeaders(
    ['Referer' => '{program_url}', 'Origin' => '{base_url}', 'Fest' => 'bleibt'],
    'https://www.cinemaxx.de/',
    'https://www.cinemaxx.de/kinoprogramm/magdeburg',
);
T::same('https://www.cinemaxx.de/kinoprogramm/magdeburg', $resolved['Referer'],
    '{program_url} wird ersetzt');
T::same('https://www.cinemaxx.de', $resolved['Origin'],
    '{base_url} wird ersetzt und der Schraegstrich entfernt');
T::same('bleibt', $resolved['Fest'], 'feste Werte bleiben unveraendert');

T::group('Ausgabe ohne CLI-Konstanten');
T::same('php://stderr', \Kino\Output::errorTarget('cli'),
    'in der CLI gehen Fehler nach stderr');
T::same('php://output', \Kino\Output::errorTarget('cgi-fcgi'),
    'unter CGI nach php://output -- stderr landet dort in der Serverlogdatei');
ob_start();
\Kino\Output::line('hallo');
T::same('hallo', ob_get_clean(), 'Ausgabe funktioniert ohne STDOUT-Konstante');

// --------------------------------------------------------------- __NEXT_DATA__

T::group('__NEXT_DATA__');
$pageHtml = file_get_contents("$fixtures/film-page-nextdata.html");
$ids = NextData::idsFromHtml($pageHtml);
T::same('1391', $ids['cinemaId'], 'Kino-Kennung aus der echten Seite');
T::same('HO00001617', $ids['filmId'], 'Film-Kennung aus der echten Seite');
T::same('Der Herr der Ringe Triple (Extended Version)', $ids['filmName'], 'Filmname');
T::same(null, NextData::idsFromHtml('<html><body>nichts</body></html>')['cinemaId'],
    'Seite ohne __NEXT_DATA__ ergibt null statt Absturz');
T::same(null, NextData::idsFromHtml('<script id="__NEXT_DATA__">{kaputt</script>')['filmId'],
    'kaputtes JSON ergibt null statt Absturz');

// ----------------------------------------------------------------- Discovery

T::group('Discovery -- Linkfilter');
$discovery = new Discovery(new Client('test', 1, 0, 0, false), $matcher,
    'https://www.cinemaxx.de', '/kinoprogramm/magdeburg', 'magdeburg');
$links = $discovery->filmLinks(file_get_contents("$fixtures/program.html"),
    'https://www.cinemaxx.de/kinoprogramm/magdeburg');
T::same(3, count($links), 'nur Magdeburger Filmseiten, Hamburg wird ignoriert');
T::same(1, count(array_filter($links, fn ($l) => $matcher->matchesUrl($l))),
    'genau eine URL passt auf die Suchmuster');

// --------------------------------------------------------------- Transitions

T::group('Transitions');
$mk = static fn (Status $s): Screening => new Screening(
    'Der Herr der Ringe Triple', $url, $s, '2026-12-13T11:00:00+01:00'
);
$transitions = new Transitions([6 * 3600, 24 * 3600]);
$now = strtotime('2026-10-14 10:05:00');

$events = $transitions->detect([], [$mk(Status::Dated)], [], $now);
T::same(1, count($events), 'erster Fund erzeugt ein Ereignis');
T::same(Event::NEW_MATCH, $events[0]->type, 'Typ ist new_match');

$previous = [$mk(Status::Dated)->fingerprint() => $mk(Status::Dated)->toArray()];
$events = $transitions->detect($previous, [$mk(Status::Dated)], [], $now);
T::same(0, count($events), 'unveraenderter Zustand erzeugt KEINE Mail');

$events = $transitions->detect($previous, [$mk(Status::Bookable)], [], $now);
T::same(1, count($events), 'Uebergang DATED -> BOOKABLE wird gemeldet');
T::ok($events[0]->isAlarm(), 'und zwar als Hauptalarm');
T::same(Status::Dated->value, $events[0]->previousStatus, 'Vorzustand wird mitgeliefert');

$bookablePrev = [$mk(Status::Bookable)->fingerprint() => $mk(Status::Bookable)->toArray()];
$fp = $mk(Status::Bookable)->fingerprint();
$events = $transitions->detect($bookablePrev, [$mk(Status::Bookable)], [$fp => $now - 60], $now);
T::same(0, count($events), 'kurz nach dem Alarm kommt keine Wiederholung');
$events = $transitions->detect($bookablePrev, [$mk(Status::Bookable)], [$fp => $now - 6 * 3600 - 10], $now);
T::same(1, count($events), 'nach 6 Stunden folgt eine Erinnerung');
T::same(Event::REMINDER, $events[0]->type, 'Typ ist reminder');

// Das ist die eigentliche Zusicherung: Erinnerungen hoeren auf.
//
// Vorher wurde gegen den Zeitpunkt der LETZTEN Mail gerechnet -- den eine
// Erinnerung selbst neu setzte. Die Uhr begann dadurch nach jeder Erinnerung
// von vorn, und es kam endlos alle sechs Stunden Post. Jetzt wird ab der
// ersten Meldung gerechnet und mitgezaehlt.
$record = static fn (int $ageSeconds, int $sent): array => [$fp => [
    'at' => $now - $ageSeconds, 'status' => 'BOOKABLE', 'reminders' => $sent,
]];
$bookableNow = [$mk(Status::Bookable)];

T::same(0, count($transitions->detect($bookablePrev, $bookableNow, $record(3600, 0), $now)),
    'nach einer Stunde noch keine Erinnerung');
T::same(1, count($transitions->detect($bookablePrev, $bookableNow, $record(6 * 3600, 0), $now)),
    'erste Erinnerung nach 6 Stunden');
T::same(0, count($transitions->detect($bookablePrev, $bookableNow, $record(7 * 3600, 1), $now)),
    'die erste ist raus -- die zweite kommt nicht schon nach 7 Stunden');
T::same(1, count($transitions->detect($bookablePrev, $bookableNow, $record(24 * 3600, 1), $now)),
    'zweite Erinnerung nach 24 Stunden, gerechnet ab dem Alarm');
T::same(0, count($transitions->detect($bookablePrev, $bookableNow, $record(25 * 3600, 2), $now)),
    'danach ist Ruhe');
T::same(0, count($transitions->detect($bookablePrev, $bookableNow, $record(30 * 86400, 2), $now)),
    'auch nach einem Monat bleibt es ruhig');

$silent = new Transitions([]);
T::same(0, count($silent->detect($bookablePrev, $bookableNow, $record(10 * 86400, 0), $now)),
    'eine leere Liste schaltet Erinnerungen vollstaendig ab');

// Ein Zustand, der vor der Umstellung geschrieben wurde, enthaelt nur
// Zeitstempel. Die muessen weiter verstanden werden, sonst schlaegt ein
// laufender Watcher nach dem Update erneut Alarm.
T::same(['at' => 1760000000, 'status' => '', 'reminders' => 0],
    Transitions::normalizeRecord(1760000000), 'alter Zeitstempel wird verstanden');
T::same(null, Transitions::normalizeRecord('unsinn'), 'Schrott ergibt null statt Absturz');

$events = $transitions->detect($previous, [], [], $now);
T::same(1, count($events), 'verschwundene Vorstellung wird als Warnung gemeldet');
T::same(Event::WARNING, $events[0]->type, 'Typ ist warning');

// Nach dem Marathon verschwindet die Vorstellung ganz regulaer aus dem
// Programm. Ohne diese Unterscheidung kaeme jedes Jahr eine Warnmail, die nach
// einem Defekt klingt.
$afterwards = strtotime('2026-12-15 12:00:00');   // Termin ist der 13.12.
$events = $transitions->detect($previous, [], [], $afterwards);
T::same(1, count($events), 'auch danach genau ein Ereignis');
T::same(Event::STATUS_CHANGED, $events[0]->type,
    'ein vergangener Termin wird als stattgefunden gemeldet, nicht als Warnung');
T::ok(str_contains($events[0]->summary, 'stattgefunden'), 'und auch so benannt');

// Waehrend der Vorstellung selbst darf noch keine Entwarnung kommen: Das
// Triple laeuft ueber zwoelf Stunden.
$during = strtotime('2026-12-13 18:00:00');
$events = $transitions->detect($previous, [], [], $during);
T::same(Event::WARNING, $events[0]->type,
    'am Vorstellungstag gilt ein Verschwinden weiter als Warnung');

T::group('Watchdog -- Ausfall des Taktgebers');
$watchdog = new \Kino\Health\Watchdog((array)$config->get('notify'));
$sept = strtotime('2026-09-20 12:00:00');

T::ok($watchdog->checkTickSilence(date('c', $sept - 120), true, $sept) === null,
    'zwei Minuten Abstand sind normal');
$alert = $watchdog->checkTickSilence(date('c', $sept - 4 * 3600), true, $sept);
T::ok($alert !== null, 'vier Stunden Stille loesen eine Warnung aus');
T::ok(str_contains((string)$alert?->summary, '4 Stunden'), 'die Dauer steht in der Meldung');
T::ok($watchdog->checkTickSilence(date('c', $sept - 4 * 3600), false, $sept) === null,
    'ausserhalb der Saison wird nicht gewarnt');
T::ok($watchdog->checkTickSilence(null, true, $sept) === null,
    'beim ersten Lauf gibt es keinen Vorgaenger -- keine Warnung');

// ----------------------------------------------------------------- Scheduler

T::group('Scheduler');
$scheduler = new Scheduler((array)$config->get('schedule'));
$sept = strtotime('2026-09-20 12:00:00');
$may  = strtotime('2026-05-20 12:00:00');

T::same(12 * 3600, $scheduler->interval(Status::Absent, null, 0, $may), 'Mai ohne Treffer: 12 Stunden');
T::same(1800, $scheduler->interval(Status::Absent, null, 0, $sept), 'September ohne Treffer: 30 Minuten');
T::same(300, $scheduler->interval(Status::Dated, null, 0, $sept), 'Termin bekannt: 5 Minuten');
T::same(60, $scheduler->interval(Status::Dated, date('c', $sept + 900), 0, $sept),
    'Vorverkauf in 15 Minuten: jeder Tick');
T::same(900, $scheduler->interval(Status::Dated, date('c', $sept + 7200), 0, $sept),
    'Vorverkauf in 2 Stunden: 15 Minuten');
T::same(300, $scheduler->interval(Status::Absent, null, 1, $sept), 'erster Fehler: 5 Minuten Backoff');
T::same(3600, $scheduler->interval(Status::Absent, null, 99, $sept), 'Backoff ist bei 60 Minuten gedeckelt');
T::ok($scheduler->isHotSeason($sept), 'September ist heisse Phase');
T::ok(!$scheduler->isHotSeason($may), 'Mai ist es nicht');
T::ok(!$scheduler->isDue(date('c', $sept + 300), $sept), 'vor next_check_at wird uebersprungen');
T::ok($scheduler->isDue(date('c', $sept - 1), $sept), 'nach next_check_at wird geprueft');
T::ok($scheduler->isDue(null, $sept), 'ohne gespeicherten Termin wird geprueft');

// ------------------------------------------------------------------- Robots

T::group('robots.txt');
$robots = new Robots("User-agent: *\nDisallow: /intern/\nAllow: /intern/oeffentlich\n");
T::ok($robots->allows('/kinoprogramm/magdeburg'), 'nicht gesperrter Pfad ist erlaubt');
T::ok(!$robots->allows('/intern/geheim'), 'gesperrter Pfad ist untersagt');
T::ok($robots->allows('/intern/oeffentlich/seite'), 'laengere Allow-Regel gewinnt');

// ------------------------------------------------------------------- Mailer

T::group('Mail');
// Port 465 mit STARTTLS ist die haeufigste Fehlkonfiguration: Der Server
// wartet auf einen TLS-Handshake, bekommt "EHLO" und legt auf. 'auto'
// verhindert das, indem der Port entscheidet.
T::same('ssl', Mailer::resolveEncryption('auto', 465), 'Port 465 -> TLS sofort');
T::same('tls', Mailer::resolveEncryption('auto', 587), 'Port 587 -> STARTTLS');
T::same('tls', Mailer::resolveEncryption('auto', 25), 'Port 25 -> STARTTLS');
T::same('ssl', Mailer::resolveEncryption('', 465), 'leerer Wert verhaelt sich wie auto');
T::same('tls', Mailer::resolveEncryption('tls', 465), 'ausdrueckliche Angabe wird respektiert');
T::same('none', Mailer::resolveEncryption('NONE', 587), 'Gross-/Kleinschreibung egal');
T::same('auto', (string)Config::fromArray([])->get('smtp.encryption'),
    'Vorgabe ist auto, nicht ein fest verdrahteter Port');

// Vorher stand im Koerper "Content-Transfer-Encoding: 8bit" mit rohem UTF-8.
// Das war ein Protokollverstoss (8BITMIME war nie ausgehandelt) und ein
// Spam-Merkmal. Quoted-printable ist 7-bit-sicher und unauffaellig.
$encoded = Mailer::encodeBody("Zeile eins\nZeile zwei: Grosse Staedte\n");
T::ok(!str_contains($encoded, '=0A'),
    'Zeilenumbrueche bleiben echte Umbrueche und werden nicht als =0A kodiert');
T::ok(str_contains($encoded, "\r\n"), 'Umbrueche sind CRLF, wie SMTP es verlangt');
T::same("Zeile eins\r\nZeile zwei: Grosse Staedte\r\n", quoted_printable_decode($encoded),
    'der Empfaenger bekommt den Text unveraendert zurueck');

$umlaut = Mailer::encodeBody("Grosse Staedte: \u{e4}\u{f6}\u{fc}\n");
T::ok(str_contains($umlaut, '='), 'Umlaute werden kodiert statt roh gesendet');
T::same("Grosse Staedte: \u{e4}\u{f6}\u{fc}\r\n", quoted_printable_decode($umlaut),
    'und kommen unveraendert an');

T::ok(str_starts_with(Mailer::encodeBody(".htaccess am Zeilenanfang\n"), '..'),
    'ein Punkt am Zeilenanfang wird verdoppelt -- sonst endet der DATA-Block zu frueh');

T::same('Kurz und ASCII', Mailer::encodeHeader('Kurz und ASCII'), 'ASCII-Betreff bleibt unveraendert');
T::ok(str_starts_with(Mailer::encodeHeader('Grosse Auswahl fuer Städte'), '=?UTF-8?B?'),
    'Betreff mit Umlaut wird RFC-2047-kodiert');

$templates = new Templates();
T::ok(!str_contains($templates->text([$alarmProbe = new Event(
    Event::STATUS_CHANGED, 'x', $mk(Status::Bookable), Status::Dated->value)], 'Kino'), 'JETZT BUCHBAR'),
    'kein Schreien in Grossbuchstaben im Mailtext');
T::ok(str_contains((new Templates('[Test]'))->subject([$alarmProbe], 'Kino'), '[Test]'),
    'Betreff-Praefix ist konfigurierbar');
$alarm = new Event(Event::STATUS_CHANGED, 'Uebergang', $mk(Status::Bookable), Status::Dated->value);
$subject = $templates->subject([$alarm], 'CinemaxX Magdeburg');
T::ok(str_starts_with($subject, '[KINO-ALARM]'), 'Betreff traegt das feste Praefix');
T::ok(str_contains($subject, 'jetzt buchbar'), 'Betreff nennt den Anlass');
$text = $templates->text([$alarm], 'CinemaxX Magdeburg');
T::ok(str_contains($text, 'Jetzt buchbar'), 'Text fuehrt mit dem Alarm');
T::ok(strpos($text, 'http') < 200, 'Direktlink steht weit oben');

// -------------------------------------------------------------------- Store

T::group('Store');
$tmp = sys_get_temp_dir() . '/kino-test-' . bin2hex(random_bytes(4));
$store = new Store($tmp);
T::ok($store->acquireLock(), 'Lock wird erteilt');
$store->setMeta('next_check_at', '2026-12-01T10:00:00+01:00');
$store->setScreenings(['abc' => $mk(Status::Dated)->toArray()]);
$store->save();

$reloaded = new Store($tmp);
T::same('2026-12-01T10:00:00+01:00', $reloaded->meta('next_check_at'), 'Meta ueberlebt den Neustart');
T::same(1, count($reloaded->screenings()), 'Vorstellungen ueberleben den Neustart');

$second = new Store($tmp);
T::ok(!$second->acquireLock(), 'zweiter Lauf bekommt kein Lock -- keine doppelten Mails');
$store->releaseLock();
T::ok($second->acquireLock(), 'nach Freigabe ist das Lock wieder verfuegbar');
$second->releaseLock();

file_put_contents("$tmp/state.json", '{ kaputt');
$broken = new Store($tmp);
T::same(0, count($broken->screenings()), 'kaputte Zustandsdatei fuehrt nicht zum Absturz');
T::ok(count(glob("$tmp/state.json.broken.*")) === 1, 'sie wird beiseite gelegt statt ueberschrieben');

array_map('unlink', glob("$tmp/*") ?: []);
@rmdir($tmp);

// ------------------------------------------------------------------- Ergebnis

echo "\n" . str_repeat('=', 60) . "\n";
printf("%d bestanden, %d fehlgeschlagen\n", T::$pass, T::$fail);
exit(T::$fail === 0 ? 0 : 1);
