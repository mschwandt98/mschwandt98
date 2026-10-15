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
mit ihren Vorstellungen, als JSON, mit `Last-Modified` für bedingte Requests.
Gefiltert wird über die Suchmuster auf den Filmtitel, nicht über eine feste URL:
Der Slug lautet nächstes Jahr anders, die API-Abfrage bleibt gleich.

Dieser Abruf ist rund **0,5 MB** groß. Im Minutentakt wäre das unhöflich, deshalb
läuft er nur alle 30 Minuten. Ist die Film-Kennung einmal bekannt
(`filmId=HO00001617`), fragt der Watcher dazwischen gezielt danach — wenige
Kilobyte statt einem halben Megabyte.

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

```bash
scp -r kino-ticket-watcher/ benutzer@dein-host:~/htdocs/
```

Der Ort ist weniger kritisch als er klingt, **solange keine Domain direkt auf
das Anwendungsverzeichnis zeigt** — siehe Schritt 4. Zeigt eine Domain darauf,
wäre `config/config.php` mit dem SMTP-Passwort über den Browser erreichbar; dann
greift nur noch die mitgelieferte `.htaccess`, und das ist im Browser
nachzuprüfen, nicht zu hoffen.

### 2. Konfigurieren

```bash
cd ~/htdocs/kino-ticket-watcher
cp config/config.example.php config/config.php
php8.5 bin/selftest.php --secret        # erzeugt das tick_secret
nano config/config.php                  # SMTP-Daten und Secret eintragen
```

### 3. Selbsttest

```bash
php8.5 bin/selftest.php                 # Umgebung, Netz, API, SMTP-Port
php8.5 bin/selftest.php --send-mail     # echte Testmail
```

Die Testmail muss im **Posteingang** ankommen, nicht im Spam. Falls doch:
Absender zur Kontaktliste hinzufügen und eine Regel auf das Betreff-Präfix
`[KINO-ALARM]` legen.

Sperrt IONOS ausgehendes SMTP auf Port 587, `'encryption' => 'ssl'` mit Port 465
probieren.

**Zur API-Authentifizierung**, auf einem IONOS-Webspace gemessen:

- **Kein `Origin`-Header.** Mit gesetztem `Origin` antwortet die API *immer*
  mit `401` — sie behandelt den Request dann als fremde Herkunft. Ein eigenes
  Backend schickt eben keinen. Der Integrationstest geht rot, falls jemand den
  Header wieder einträgt.
- **`Accept` muss JSON verlangen**, nicht HTML.
- **Die Sitzung der Programmseite** wird benötigt. Der Watcher bewahrt die
  Cookies im Zustand auf und lädt die Programmseite nur dann nach, wenn die API
  ablehnt. Dafür ist nichts einzustellen.

Antwortet die API trotzdem mit `401` oder `403`:

```bash
php8.5 bin/probe-api.php
```

Das Werkzeug probiert die Kombinationen durch, benennt die *schädlichen* Header,
dampft die funktionierende Variante auf das Nötige ein und gibt den fertigen
`config.php`-Abschnitt aus.

### 4. Subdomain auf `public/` zeigen lassen

Für den Minutentakt braucht `tick.php` eine erreichbare URL. Im IONOS-Panel eine
Subdomain anlegen (z. B. `kino.deine-domain.de`) und als Zielverzeichnis
**`public/`** eintragen:

```
/homepages/<dein-pfad>/htdocs/kino-ticket-watcher/public
```

Das ist die saubere Lösung, weil `public/` **nur** `tick.php` enthält. Alles
andere — `config/`, `src/`, `var/` — liegt dann oberhalb des ausgelieferten
Verzeichnisses und ist durch die Ordnerstruktur selbst unerreichbar, nicht bloß
durch eine `.htaccess`. An `tick.php` ist dabei nichts zu ändern: `$appDir`
zeigt von `public/` aus automatisch auf das Anwendungsverzeichnis.

Prüfen — alle drei müssen stimmen:

| Aufruf | Erwartung |
|---|---|
| `https://kino.deine-domain.de/tick.php` | **404** |
| `…/tick.php?key=falsch` | **404** |
| `…/tick.php?key=<dein Secret>` | **200**, `ok …` oder `skipped` |

**Kommt stattdessen eine `403`:** Dann setzt der Hoster die Subdomain als Alias
innerhalb des Haupt-vhosts um, und Apache wendet das `Require all denied` aus
dem übergeordneten Anwendungsverzeichnis mit an. Dagegen liegt in `public/`
eine Gegen-Regel (`Require all granted`); fehlt sie oder greift sie nicht, muss
`AllowOverride` für das Verzeichnis aktiv sein.

Zusätzlich prüfen, dass das Anwendungsverzeichnis **nicht** erreichbar ist:
`https://kino.deine-domain.de/../config/config.php` darf nichts liefern, und
falls eine andere Domain auf `htdocs/` zeigt, auch
`https://jene-domain.de/kino-ticket-watcher/config/config.php` nicht.

*Ohne erreichbare URL* entfällt der externe Taktgeber. Der Watcher läuft dann
nur über den IONOS-Cron alle 6 Stunden — funktionsfähig, aber im schlechtesten
Fall 6 Stunden Verzug beim Vorverkaufsstart.

### 5. Externer Taktgeber

Bei [cron-job.org](https://cron-job.org) einen Job anlegen:

- URL: `https://kino.deine-domain.de/tick.php?key=<dein Secret>`
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

## IONOS-Besonderheit: PHP läuft als CGI

`php8.5` ist auf IONOS kein CLI-Binary, sondern CGI. Zwei Folgen:

- Jede Ausgabe beginnt mit einer Zeile `Content-type: text/html; charset=UTF-8`.
  Das ist normal und harmlos.
- Die Konstanten `STDOUT` und `STDERR` **existieren dort nicht**. Ein
  `fwrite(STDOUT, …)` ist ein fataler Fehler, und man sieht nur
  `Status: 500 Internal Server Error` ohne weitere Erklärung. Der Watcher
  benutzt deshalb nirgends diese Konstanten, sondern `Kino\Output` — das
  funktioniert in beiden Umgebungen.

Wer das Projekt erweitert, sollte für Ausgaben `Output::line()` und
`Output::error()` verwenden, nicht `echo` mit `STDOUT`.

## Bedienung

```bash
php8.5 bin/watch.php --status                            # aktueller Zustand
php8.5 bin/watch.php --dry-run --verbose --ignore-schedule   # prüfen ohne Nebenwirkungen
php8.5 bin/watch.php --force-notify                      # Testmail durch die echte Kette
php8.5 bin/discover.php > discovery-report.txt           # API-Kontrolle
php8.5 bin/probe-api.php                                 # Header-Diagnose bei 401/403
```

Laufende Beobachtung:

```bash
tail -f var/events.jsonl        # jeder Zustandswechsel, jeder Fehler
ls -t var/snapshots | head      # Rohantworten für die Nachanalyse
```

---

## Tests

```bash
php tests/run.php           # 111 Unit-Tests, ohne Netzzugriff
php tests/integration.php   # 29 End-to-End-Tests gegen einen lokalen Testserver
php tests/smoke.php         # 14 Prüfungen der Entrypoints selbst
```

Der Integrationstest startet einen PHP-Webserver, der die API unter ihrem echten
Pfad nachbildet — inklusive der `401`-Antwort, wenn das Sitzungscookie fehlt
*oder* ein `Origin`-Header gesetzt ist. Er spielt den vollständigen Ablauf durch: Embargo → buchbar →
ausverkauft, dazu das selbständige Nachholen der Sitzung, die gezielte Abfrage
per Film-Kennung, den API-Ausfall mit Rückfall auf HTML und den `304`-Fall.

**Die API-Fixtures sind echte Antworten** aus einer HAR-Aufzeichnung der Seite,
nicht erfunden.

`smoke.php` prüft die Entrypoints selbst — die Fehlerklasse, die zweimal
zugeschlagen hat und die kein Unit-Test findet: Zugriff auf `STDOUT`/`STDERR`
(unter CGI fatal) und Benutzung einer Projektklasse *vor* dem `require` des
Autoloaders. Beides zeigte sich nur als nackter `500 Internal Server Error`.

Alle drei Suiten laufen ab PHP 8.3, damit sie auch auf einer
Entwicklungsmaschine ohne 8.5 durchlaufen. Die Produktiv-Entrypoints verlangen 8.5 und brechen sonst
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
