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

### Wie viele Mails kommen tatsächlich?

| Anlass | Mails |
|---|---|
| Marathon taucht im Programm auf | 1 |
| Termin steht, Vorverkauf noch gesperrt | 1 |
| **Vorverkauf startet** | 1 Alarm, dann Erinnerungen nach 6 h und 24 h |
| ausverkauft | 1 |
| Vorstellung hat stattgefunden | 1 |
| Lebenszeichen ab September | 1 pro Woche, montags |

Die Erinnerungen werden **ab der ersten Alarmmail gerechnet und mitgezählt** —
nach der zweiten ist endgültig Ruhe, auch wenn der Vorverkauf noch Wochen läuft.
Wer gar keine Erinnerung will, setzt in `config.php`:

```php
'notify' => ['bookable_repeat_after' => []],
```

Dann kommt genau eine Mail, wenn der Vorverkauf öffnet. Mehr nicht.

### Der Taktgeber

Gibt es auf dem Server einen echten `crontab` (per SSH `crontab -e`), ist das
die beste Lösung — ein Eintrag, kein Drittanbieter, kein öffentlicher Endpunkt:

```cron
MAILTO=""
*/2 * * * * /usr/bin/php8.5 /pfad/zum/kino-ticket-watcher/bin/watch.php --quiet >> /pfad/zum/kino-ticket-watcher/var/cron.log 2>&1
```

Der Cron klopft alle zwei Minuten an; ob daraus ein echter Prüflauf wird,
entscheidet der Scheduler anhand von `next_check_at`. CinemaxX sieht also nie
mehr Last als in der Tabelle unten, egal wie oft getickt wird.

**Falls kein `crontab` verfügbar ist** (nur ein Web-Panel mit 6-Stunden-Limit):
Dann ruft ein externer Dienst wie [cron-job.org](https://cron-job.org) die
Datei `public/tick.php` per URL auf, minütlich und mit einem Secret geschützt.
Der Panel-Cron läuft parallel als Sicherheitsnetz. Siehe Schritt 4 und 5 der
Installation.

Beide Wege führen in denselben `Runner` — die Prüflogik existiert genau einmal.

**Ausfallerkennung:** Liegt der vorige Tick mehr als eine Stunde zurück, obwohl
die heiße Phase läuft, kommt eine Warnmail. Damit fällt ein stillgelegter
Cronjob auf, statt unbemerkt zu bleiben.

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

### 4. Taktgeber einrichten

**Weg A — echter `crontab` (bevorzugt).** Per SSH prüfen:

```bash
crontab -l            # funktioniert der Befehl?
which php8.5          # absoluter Pfad zum Binary
pwd                   # Heimatverzeichnis = Basis für den Pfad unten
```

Dann `crontab -e` und eintragen:

```cron
MAILTO=""
*/2 * * * * /usr/bin/php8.5 /homepages/<dein-pfad>/kino-ticket-watcher/bin/watch.php --quiet >> /homepages/<dein-pfad>/kino-ticket-watcher/var/cron.log 2>&1
```

`MAILTO=""` verhindert, dass der Cron-Daemon bei jedem Lauf eine Mail
verschickt. `--quiet` sorgt dafür, dass nur Ereignisse und Fehler im Log
landen, nicht die Routinezeilen — sonst wächst die Datei um Tausende Zeilen
pro Tag.

Dass es wirklich läuft, zeigt sich nach etwa zehn Minuten:

```bash
php8.5 bin/watch.php --status      # Zeile "Letzter Lauf" muss sich bewegen
cat var/cron.log                   # bei --quiet normalerweise leer
```

Bewegt sich nichts, wird der Crontab nicht ausgeführt — manche Hoster erlauben
`crontab -e`, starten die Einträge aber nicht. Dann Weg B.

**Weg B — externer Dienst plus `tick.php`.** Nur nötig, wenn Weg A nicht geht.

Erst eine Subdomain im Hoster-Panel anlegen (z. B. `kino.deine-domain.de`) und
als Zielverzeichnis **`public/`** eintragen:

```
/homepages/<dein-pfad>/kino-ticket-watcher/public
```

Das ist die saubere Lösung, weil `public/` **nur** `tick.php` enthält. Alles
andere — `config/`, `src/`, `var/` — liegt oberhalb des ausgelieferten
Verzeichnisses und ist durch die Ordnerstruktur unerreichbar, nicht bloß durch
eine `.htaccess`. An `tick.php` ist nichts zu ändern: `$appDir` zeigt von
`public/` aus automatisch auf das Anwendungsverzeichnis.

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

Dann bei [cron-job.org](https://cron-job.org) einen Job anlegen:

- URL: `https://kino.deine-domain.de/tick.php?key=<dein Secret>`
- Intervall: jede Minute
- Fehlerbenachrichtigung an deine Mailadresse **aktivieren**

### 5. Sicherheitsnetz (optional)

Erlaubt das Hoster-Panel nur einen 6-Stunden-Cron per HTTP GET, lohnt er sich
als zweiter, unabhängiger Taktgeber: URL wie oben, Intervall 6 Stunden. Er
prüft selbst und meldet, wenn der eigentliche Taktgeber stumm geworden ist.

Nutzt du Weg A, ist das der sinnvollste Einsatz dieses Panel-Crons — die beiden
Mechanismen bewachen sich dann gegenseitig, ohne dass ein Drittanbieter nötig
ist.

### 6. Posteingangsregel

Regel auf Betreff enthält `[KINO-ALARM]` → als wichtig markieren und
Push-Benachrichtigung aktivieren. Ohne diesen Schritt liegt der Alarm im
Posteingang, während der Vorverkauf läuft.

---

## Mail landet im Spam

Das ist der eine Fehlerfall, der den ganzen Watcher wertlos macht: Eine
Alarmmail, die niemand sieht, ist schlimmer als keine, weil man sich auf sie
verlässt.

Was der Code dafür tut:

- **Quoted-printable** statt rohem 8-Bit-UTF-8. Letzteres war zugleich ein
  Protokollverstoß — `8BITMIME` wurde nie ausgehandelt — und ein Spam-Merkmal.
- **Kein eigener `X-Mailer`-Header.** Ein unbekannter Mailer-Name kostet Punkte,
  sein Fehlen ist unauffällig.
- **`Reply-To`** gesetzt, `Message-ID` mit der Absenderdomain, `Date` korrekt.
- Keine Großbuchstaben-Schreierei im Text.

Was der Code **nicht** lösen kann — in dieser Reihenfolge abarbeiten:

**1. Absender und SMTP-Konto müssen zur selben Domain gehören.**
Schickst du über `smtp.example.com` mit dem Konto `ich@example.com`, muss
`smtp.from` ebenfalls `…@example.com` sein. Weicht die Domain ab, schlägt die
SPF-Prüfung beim Empfänger fehl, egal wie saubergebaut die Mail ist. Der
Selbsttest prüft das und warnt.

**2. Absender als Kontakt anlegen und „kein Spam" markieren.**
Unspektakulär, aber die einzige Maßnahme, die mit Sicherheit wirkt. Dazu eine
Posteingangsregel auf das Betreff-Präfix `[KINO-ALARM]` legen — die lässt sich
in den meisten Mail-Apps mit einer Push-Benachrichtigung verbinden.

**3. SPF und DKIM für die Absenderdomain einrichten.**
Bei einer eigenen Domain beim Hoster meist zwei Klicks. Ohne diese Einträge
bleibt jede Mail von einer eigenen Domain grenzwertig.

**Alternative, falls das zu mühsam ist:** Nimm als Absender *und* Empfänger
dieselbe große Freemail-Adresse (Gmail, GMX) mit App-Passwort. Dann sendet der
Watcher über deren Server, SPF und DKIM stimmen von sich aus, und die Mail
landet praktisch immer im Posteingang. Der Preis: Die Absenderadresse ist dann
nicht mehr deine eigene Domain.

**Prüfen, ob es geholfen hat:** `php8.5 bin/selftest.php --send-mail` und
nachsehen. Der wöchentliche Lebenszeichen-Brief ab September prüft das danach
von selbst weiter — wenn er ausbleibt, weißt du, dass die Zustellung klemmt,
bevor es darauf ankommt.

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
php tests/run.php           # 144 Unit-Tests, ohne Netzzugriff
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
- **Tick-Überwachung** — meldet, wenn der Taktgeber stumm wird. Liegt der
  *vorige* Tick über eine Stunde zurück, kommt eine Warnmail.
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
