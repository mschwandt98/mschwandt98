# Kino-Ticket-Watcher

Beobachtet das Programm des CinemaxX Magdeburg und schickt eine Mail, **sobald**
der Vorverkauf für den Herr-der-Ringe-Marathon startet.

PHP 8.5, keine Abhängigkeiten, kein Composer. Zustand in einer JSON-Datei.

---

## Wie es funktioniert

Der Watcher fragt die **öffentliche Showings-API** von cinemaxx.de ab:

```
GET /api/microservice/showings/cinemas/1391/films
    ?minEmbargoLevel=1&includesSession=true&includeSessionAttributes=true
```

`1391` ist CinemaxX Magdeburg. Ein einziger Request liefert alle Filme des Kinos
mit ihren Vorstellungen — ohne Authentifizierung, als JSON, mit `Last-Modified`
für bedingte Requests. Gefiltert wird über die Suchmuster auf den Filmtitel, nicht
über eine feste URL: Der Slug lautet nächstes Jahr anders, die API-Abfrage bleibt
gleich.

Entscheidend ist, dass die API die Frage direkt beantwortet, statt sie raten zu
lassen:

| Feld | Bedeutung |
|---|---|
| `session.isBookingAvailable` | buchbar |
| `session.isSoldOut` | ausverkauft |
| `film.embargoEndDate` | Vorverkaufsstart, solange noch gesperrt |
| `session.bookingUrl` | Direktlink in den Buchungsflow |

`embargoEndDate` ist dabei der wertvollste Wert: Sobald CinemaxX den Termin
einstellt, steht dort der Vorverkaufsstart auf die Minute genau. Der Scheduler
übernimmt ihn und prüft kurz davor bei jedem Tick.

Fällt die API aus, greift eine HTML-Rückfallebene über Programmseite und
`sitemap.xml`. Sie ist fragiler und deshalb nur Reserve.

Für jede gefundene Vorstellung bestimmt der Watcher einen Zustand:

| Zustand | Bedeutung | Mail |
|---|---|---|
| `ABSENT` | kein passender Film im Programm | – |
| `PAGE_EXISTS` | Film gelistet, aber ohne Vorstellung | ja |
| `DATED` | Termin steht, Vorverkauf noch gesperrt | ja |
| `BOOKABLE` | Buchung offen | **Hauptalarm** |
| `SOLD_OUT` | ausverkauft | ja |

Gemailt wird nur bei **Zustandswechseln**, nicht bei jedem Lauf.

### Zwei Taktgeber

IONOS erlaubt Cronjobs frühestens alle 6 Stunden — zu grob für einen
Vorverkaufsstart. Deshalb:

1. **cron-job.org** (kostenlos, Minutentakt) ruft `tick.php` auf. Das ist der
   eigentliche Prüftakt.
2. **IONOS-Cron alle 6 Stunden** ruft `bin/watch.php` auf. Er prüft ohnehin
   und meldet zusätzlich, wenn Taktgeber 1 stumm geworden ist.

Die beiden sichern sich gegenseitig ab. Wie oft tatsächlich bei CinemaxX
angefragt wird, entscheidet allein der Scheduler — die meisten Minutenticks
enden als No-op.

---

## Installation auf IONOS

### 1. Dateien hochladen

Die Anwendung gehört **außerhalb des Document Root** — `config/config.php`
enthält das SMTP-Passwort und darf nie über den Browser lesbar sein.

```bash
scp -r kino-ticket-watcher/ benutzer@dein-host:~/kino-watcher/
```

Wo der Document Root liegt, zeigt `pwd` nach dem SSH-Login. Liegt die Anwendung
zwangsläufig darin, schützt die mitgelieferte `.htaccess` sie zusätzlich —
**das danach im Browser wirklich nachprüfen.**

### 2. Konfigurieren

```bash
cd ~/kino-watcher
cp config/config.example.php config/config.php
php8.5 bin/selftest.php --secret        # erzeugt das tick_secret
nano config/config.php                  # SMTP-Daten und Secret eintragen
```

### 3. Selbsttest

```bash
php8.5 bin/selftest.php                 # Umgebung, Netz, SMTP-Port
php8.5 bin/selftest.php --send-mail     # echte Testmail
```

Die Testmail muss im **Posteingang** ankommen, nicht im Spam. Falls doch:
Absender zur Kontaktliste hinzufügen und eine Regel auf das Betreff-Präfix
`[KINO-ALARM]` legen.

Sperrt IONOS ausgehendes SMTP auf Port 587, `'encryption' => 'ssl'` mit Port 465
probieren.

### 4. `tick.php` in den Document Root

```bash
cp public/tick.php ~/pfad/zum/docroot/kino-tick.php
```

Dann in der kopierten Datei `$appDir` auf das Anwendungsverzeichnis zeigen
lassen, z. B. `$appDir = '/home/benutzer/kino-watcher';`

Prüfen — in dieser Reihenfolge, alle drei müssen stimmen:

| Aufruf | Erwartung |
|---|---|
| `https://deine-domain.de/kino-tick.php` | **404** |
| `…/kino-tick.php?key=falsch` | **404** |
| `…/kino-tick.php?key=<dein Secret>` | **200**, `ok …` oder `skipped` |

### 5. Externer Taktgeber

Bei [cron-job.org](https://cron-job.org) einen Job anlegen:

- URL: `https://deine-domain.de/kino-tick.php?key=<dein Secret>`
- Intervall: jede Minute
- Fehlerbenachrichtigung an deine Mailadresse **aktivieren** — so erfährst du
  auch dann von einem Problem, wenn der Watcher selbst keine Mail mehr schickt

### 6. IONOS-Cron

Im IONOS-Panel einen Cronjob alle 6 Stunden anlegen:

```
/usr/bin/php8.5 /home/benutzer/kino-watcher/bin/watch.php
```

Der genaue Pfad zum Binary kommt aus `ls /usr/bin/php*`. Lässt das Panel nur
eine URL zu, stattdessen dieselbe `tick.php`-URL eintragen.

---

## Bedienung

```bash
php8.5 bin/watch.php --status                            # aktueller Zustand
php8.5 bin/watch.php --dry-run --verbose --ignore-schedule   # prüfen ohne Nebenwirkungen
php8.5 bin/watch.php --force-notify                      # Testmail durch die echte Kette
php8.5 bin/discover.php > discovery-report.txt           # API-Kontrolle
```

Laufende Beobachtung:

```bash
tail -f var/events.jsonl        # jeder Zustandswechsel, jeder Fehler
ls -t var/snapshots | head      # Rohantworten für die Nachanalyse
```

---

## Tests

```bash
php tests/run.php           # 101 Unit-Tests, ohne Netzzugriff
php tests/integration.php   # 26 End-to-End-Tests gegen einen lokalen Testserver
```

Der Integrationstest startet einen PHP-Webserver, der die API unter ihrem echten
Pfad nachbildet, und spielt den vollständigen Ablauf durch: Embargo → buchbar →
ausverkauft, dazu den API-Ausfall mit Rückfall auf HTML und den `304`-Fall.

**Die API-Fixtures sind echte Antworten** aus einer HAR-Aufzeichnung der Seite,
nicht erfunden.

Beide Suiten laufen ab PHP 8.3, damit sie auch auf einer Entwicklungsmaschine
ohne 8.5 durchlaufen. Die Produktiv-Entrypoints verlangen 8.5 und brechen sonst
mit Exit-Code 3 ab — ein falsch konfigurierter Cron soll laut scheitern.

---

## Was den Watcher davor bewahrt, still auszufallen

Ein Watcher, auf den man sich verlässt und der seit drei Wochen nichts mehr
findet, ist schlimmer als gar keiner. Dagegen:

- **Canary** — meldet die API für das ganze Kino null Filme, ist das ein
  Auswertungsfehler und kein leeres Ergebnis. Ein Multiplex hat immer Programm.
- **HTML-Rückfallebene** — fällt die API aus, wird über Programmseite und
  Sitemap weitergesucht. Der Ausfall wird protokolliert und fließt in den
  Fehler-Backoff ein.
- **`304`-Schutz** — antwortet die API „unverändert", wird der gespeicherte
  Zustand nicht angetastet. Sonst würde jede Vorstellung als „verschwunden"
  gemeldet.
- **Tick-Überwachung** — meldet, wenn der externe Taktgeber stumm wird.
- **Lebenszeichen-Mail** — wöchentlich ab September. Beweist, dass die ganze
  Kette inklusive SMTP-Zustellung funktioniert.
- **Snapshots + `events.jsonl`** — die Grundlage, um eine Fehlfunktion in
  Minuten statt in Stunden zu korrigieren.

---

## Grenzen

**Die API ist nicht dokumentiert und kann sich ändern.** Sie wurde aus einer
HAR-Aufzeichnung der Seite rekonstruiert; die Feldnamen stimmen mit den echten
Antworten überein, aber CinemaxX gibt keine Stabilitätszusage. Dagegen helfen
Canary, HTML-Rückfallebene und die aufgehobenen Snapshots.

**Der Embargo-Fall ist nicht an echten Daten geprüft.** Zum Zeitpunkt der
Aufzeichnung lief der Vorverkauf bereits. Die Embargo-Fixture wurde aus der
echten Antwort abgeleitet, indem `embargoEndDate` gesetzt und
`isBookingAvailable` auf `false` gestellt wurde. Das entspricht der Semantik der
Felder, ist aber nicht dasselbe wie eine echte Embargo-Antwort. Falls CinemaxX
dort anders antwortet, ist das der Punkt zum Nachschärfen — deshalb legt der
Watcher Snapshots ab.

**Nicht enthalten:** automatischer Ticketkauf, Sitzplatzreservierung,
Sitzplatz-Zählung, Weboberfläche, andere Benachrichtigungswege als Mail.

**Etikette:** Nur lesende Zugriffe auf öffentliche Seiten, `robots.txt` wird
respektiert, bedingte Requests (ETag/If-Modified-Since), höchstens ein Request
alle zwei Minuten pro Host, User-Agent mit Kontaktadresse.
