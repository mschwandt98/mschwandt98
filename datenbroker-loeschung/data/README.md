# Mitgelieferte Daten

Alles in diesem Verzeichnis außer `reminder.txt` und `complaint.txt` stammt aus
der Firmendatenbank von [Datenanfragen.de](https://github.com/datenanfragen/data)
und steht unter **CC0 1.0** (siehe `LICENSE-CC0.txt`). Der Stand ist in
`UPSTREAM-COMMIT` vermerkt.

| Pfad | Inhalt |
|---|---|
| `companies/*.json` | Kontaktdaten der Datenbroker, unverändert übernommen |
| `authorities/*.json` | deutsche Datenschutzaufsichtsbehörden (für Beschwerde-Entwürfe) |
| `packs-de.json` | die Firmenpakete von Datenanfragen.de für Deutschland |
| `templates/{de,en}/*-default.txt` | Brieftexte für Löschung, Widerspruch und Auskunft |
| `templates/{de,en}/reminder.txt`, `complaint.txt` | eigene Texte für Erinnerung und Beschwerde |

`bin/sync-db.php` aktualisiert die Firmendaten. Die neuen Fassungen landen in
`var/db/` und haben Vorrang vor diesem Verzeichnis, das selbst unangetastet
bleibt.
