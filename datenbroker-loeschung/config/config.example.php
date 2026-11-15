<?php
declare(strict_types=1);

/**
 * Konfiguration. Nach config/config.php kopieren und ausfuellen.
 * config.php ist in .gitignore -- Zugangsdaten und Anschrift gehoeren nicht ins Repo.
 *
 * Alles, was hier fehlt, kommt aus Optout\Config::defaults().
 */

return [
    // Erst auf true stellen, wenn bin/preview.php gut aussieht und
    // bin/selftest.php --smtp --imap durchlaeuft. Bis dahin geht nichts raus.
    'live' => false,

    // Was die Firmen ueber dich erfahren -- nur so viel, wie zur Zuordnung
    // noetig ist. Adresshaendler suchen ueber die Postanschrift.
    'identity' => [
        'name'    => 'Max Mustermann',
        'address' => "Musterstraße 1\n39104 Magdeburg",
        // Umzug in den letzten Jahren? Alte Anschriften helfen beim Finden.
        'previous_addresses' => [],
        // Alle Adressen, unter denen du bei Shops, Gewinnspielen usw. bekannt bist.
        'emails'  => ['max@example.com'],
        // Telefon und Geburtsdatum gehen NUR an Firmen, die sie laut Datenbank
        // verlangen (z. B. infoscore). Leer lassen ist erlaubt.
        'phones'    => [],
        'birthdate' => '',
        'include'   => ['name', 'address', 'emails'],
    ],

    'brokers' => [
        // Vorgegebene Pakete (php bin/preview.php zeigt, wer darin steckt):
        //   address-brokers   Adresshandel (Datenanfragen.de-Paket)
        //   credit-agencies   Auskunfteien -> Selbstauskunft statt Loeschung
        //   marketing-data    Marketing- und Zielgruppendaten
        //   people-search     Telefonbuch, Personen- und Kontaktsuchen
        'packs'   => ['address-brokers', 'marketing-data', 'people-search', 'credit-agencies'],

        // Weitere Slugs aus der Datenbank -- z. B. aus deiner Incogni-Liste,
        // gefunden mit: php bin/find.php --file=incogni.txt
        'add'     => [],

        'exclude' => [],

        // Firmen, die nicht in der Datenbank stehen. Felder wie bei
        // Datenanfragen.de; Pflicht sind 'slug' und 'name', dazu 'email'
        // oder 'webform'.
        'custom'  => [
            // [
            //     'slug'  => 'beispiel-broker',
            //     'name'  => 'Beispiel Data Inc.',
            //     'email' => 'privacy@beispiel.example',
            //     'web'   => 'https://beispiel.example',
            //     'relevant-countries' => ['all'],   // -> englischer Brief
            //     'categories' => ['addresses'],
            // ],
        ],

        // Art der Anfrage: 'erasure' (Loeschung + Widerspruch), 'objection'
        // (nur Werbewiderspruch), 'access' (Selbstauskunft).
        'kind_by_category' => ['credit agency' => 'access'],
        'kind_by_slug'     => [],
    ],

    // Versand. Am besten ein eigenes Postfach nur fuer diesen Zweck, z. B.
    // datenschutz@deine-domain.de -- dann liest der IMAP-Abruf nur Antworten.
    'smtp' => [
        'host' => 'smtp.ionos.de',
        'port' => 587,
        'user' => 'datenschutz@example.com',
        'pass' => 'app-passwort',
        'from' => 'datenschutz@example.com',
        // Leer = dein Name aus identity.name
        'from_name' => '',
        // Optional, falls dein Anbieter Plus-Adressen kennt:
        // 'reply_to' => 'datenschutz+{ref}@example.com',
        // Optional: Kopie jeder Anfrage als Beleg
        // 'bcc' => 'archiv@example.com',
    ],

    // Abruf der Antworten. Wird nur gelesen, nichts verschoben oder geloescht.
    'imap' => [
        'host' => 'imap.ionos.de',
        'port' => 993,
        'user' => 'datenschutz@example.com',
        'pass' => 'app-passwort',
        // Spam-Ordner mitlesen: Antworten von Ticketsystemen landen gern dort.
        // Namen mit php bin/selftest.php --imap pruefen.
        'folders' => ['INBOX'],
    ],

    'notify' => [
        // Hierhin gehen "du bist dran" und die Wochenuebersicht.
        'to'             => 'du@example.com',
        'subject_prefix' => '[DATENSCHUTZ]',
        'digest_weekday' => 1,   // 1 = Montag, -1 = nie
        'digest_hour'    => 8,
    ],

    // Fuer Beschwerde-Entwuerfe. Sachsen-Anhalt = 'desalbd'.
    // Alle: ls data/authorities
    'authority' => 'desalbd',

    'schedule' => [
        'max_sends_per_run' => 5,          // bei stuendlichem Cron: 5 pro Stunde
        'recheck_days'      => ['erasure' => 90, 'objection' => 180, 'access' => 365],
    ],

    // php bin/selftest.php --secret
    'tick_secret'      => '',
    'dashboard_secret' => '',
    // Erscheint in den Mails an dich (ohne Schluessel).
    'dashboard_url'    => '',
];
