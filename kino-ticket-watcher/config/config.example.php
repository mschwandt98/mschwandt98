<?php
declare(strict_types=1);

/**
 * Konfiguration des Kino-Ticket-Watchers.
 *
 * Diese Datei nach config/config.php kopieren und ausfuellen.
 * config.php ist in .gitignore -- Zugangsdaten gehoeren nicht ins Repo.
 *
 * Alle hier nicht gesetzten Werte kommen aus Config::defaults().
 */

return [
    'timezone' => 'Europe/Berlin',

    'cinema' => [
        // Numerische Kino-Kennung der Showings-API. 1391 = CinemaxX Magdeburg,
        // belegt aus dem __NEXT_DATA__ der Programmseite. Leer lassen, um sie
        // automatisch ermitteln und im Zustand merken zu lassen.
        'id'           => '1391',
        'city'         => 'magdeburg',
        'name'         => 'CinemaxX Magdeburg',
        'base_url'     => 'https://www.cinemaxx.de',
        'program_path' => '/kinoprogramm/magdeburg',
    ],

    // Bewusst breit: Ein Fehlalarm kostet eine Mail, ein verpasster Treffer
    // kostet den Marathon. Weitere Eintraege hier ergaenzen, wenn du auch
    // andere Reihen oder Previews beobachten willst.
    //
    // CinemaxX nennt den Film 2026 "Der Herr der Ringe Triple (Extended
    // Version)" -- das erste Muster trifft darauf. Ein regulaerer
    // Hobbit-Termin trifft absichtlich NICHT; ein "Hobbit Trilogie Marathon"
    // dagegen schon.
    'watch_patterns' => [
        '/herr[ -]?der[ -]?ringe/u',
        '/lord[ -]?of[ -]?the[ -]?rings/u',
        '/mittelerde|tolkien/u',
        '/(triple|trilogie|marathon)/u',
    ],

    // Nur Vorstellungen in diesem Zeitfenster loesen Alarm aus (laeuft ueber
    // den Jahreswechsel). Verhindert, dass eine Hobbit-Wiederauffuehrung im
    // Maerz als Marathon durchgeht.
    'date_window' => ['from' => '10-01', 'to' => '02-28'],

    // Nur fuer die HTML-Rueckfallebene relevant, falls die API einmal ausfaellt.
    // Im Normalfall findet der Watcher den Film ueber die API, ohne eine URL
    // zu kennen -- wichtig, weil der Slug jedes Jahr anders lautet.
    'known_urls' => [
        'https://www.cinemaxx.de/kinoprogramm/magdeburg/film/der-herr-der-ringe-triple-extended-version',
    ],

    'smtp' => [
        'host'       => 'smtp.example.com',
        'port'       => 587,
        'user'       => 'dein-benutzername',
        'pass'       => 'dein-app-passwort',   // App-Passwort, nicht das Hauptpasswort
        'from'       => 'watcher@example.com',
        'from_name'  => 'Kino-Watcher',
        'to'         => 'du@example.com',
        'encryption' => 'tls',                 // tls = STARTTLS (587), ssl = implizit (465)
    ],

    // Mit "php8.5 bin/selftest.php --secret" erzeugen.
    'tick_secret' => '',

    'http' => [
        // Kontaktadresse eintragen: So kann CinemaxX sich melden, statt zu sperren.
        'user_agent' => 'KinoTicketWatcher/1.0 (persoenlicher Benachrichtigungs-Watcher; du@example.com)',
    ],
];
