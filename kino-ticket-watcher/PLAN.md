# Plan: Kino-Ticket-Watcher (Herr-der-Ringe-Marathon)

Ziel: Eine Mail bekommen, **sobald** der Vorverkauf für den jährlichen
Herr-der-Ringe-Marathon startet — nicht Tage später, wenn die guten Plätze weg sind.

Sprache: **PHP 8.5**, Ausführung als CLI-Skript per **Cronjob**.

---

## 1. Ausgangslage und was daraus folgt

Beobachtung aus diesem Jahr: Newsletter + wöchentlicher manueller Blick haben
nicht gereicht. Der Vorverkauf wurde freigeschaltet und war innerhalb von
Stunden (nicht Tagen) für die guten Plätze durch.

Daraus folgen drei Anforderungen, die den ganzen Entwurf bestimmen:

1. **Latenz zählt, nicht Vollständigkeit.** Ein Treffer, der 6 Stunden zu spät
   gemeldet wird, ist wertlos. Zielgröße: Alarm < 5 Minuten nach Freischaltung
   in der heißen Phase.
2. **Stiller Ausfall ist das Hauptrisiko.** Ein Watcher, auf den man sich
   verlässt und der seit drei Wochen wegen eines Layout-Wechsels nichts mehr
   findet, ist schlimmer als gar kein Watcher — er wiegt in Sicherheit.
   Deshalb: Heartbeat und Plausibilitätsprüfungen sind **Pflichtteil**, nicht Kür.
3. **Das Ereignis tritt einmal pro Jahr ein.** Man bekommt keine zweite Chance
   zum Nachbessern. Also muss das Ding im August trockengetestet werden, mit
   echten Fixtures.

### Sofortmaßnahme (noch vor der ersten Zeile Code)

Die Seite befindet sich **gerade jetzt** im Zustand „buchbar / teilweise
ausverkauft“. Genau dieser Zustand ist die schwierigste Testfixture und in
zwei Wochen nicht mehr beschaffbar. Bitte jetzt sichern:

- Vollständiges HTML der Programm-/Filmseite (`curl` oder Browser „Seite speichern“)
- DevTools → Netzwerk → alle XHR/Fetch-Responses als JSON (Rechtsklick → „Save as HAR“)
- Screenshot des Buchungsbuttons / Sitzplans
- URL des Buchungsflows (der Link, der vom Button weggeht)
- Falls vorhanden: die Newsletter-Mail von heute, inkl. der darin verlinkten URLs

Ablage in `tests/fixtures/2025-bookable/`. Das kostet 10 Minuten und spart
später den Blindflug.

---

## 2. Was „Tickets verfügbar“ technisch überhaupt heißt

Zwischen „nichts da“ und „ausverkauft“ liegen mehrere Stufen. Der Crawler muss
sie unterscheiden, weil der interessante Moment ein **Übergang** ist, kein Zustand:

| Stufe | Was auf der Seite steht | Reaktion |
|---|---|---|
| `UNKNOWN` | Marathon kommt nicht vor | nichts, weiter beobachten |
| `ANNOUNCED` | Teaser/News „Marathon kommt wieder“, kein Termin | **Mail (Vorwarnung)** + Takt erhöhen |
| `PRESALE_PENDING` | Termin im Programm, aber „VVK ab TT.MM.“ / Button inaktiv | **Mail** + Takt auf Minutenbereich, VVK-Datum in den Scheduler übernehmen |
| `BOOKABLE` | Buchungsbutton aktiv / Sitzplan liefert freie Plätze | **HAUPTALARM** |
| `LOW` | buchbar, aber < N freie Plätze | Mail (Eskalation) |
| `SOLD_OUT` | ausverkauft | Mail (Info: Fenster zu) |

Wichtig ist die Stufe `PRESALE_PENDING`: sie nennt fast immer das
Vorverkaufsdatum im Klartext. Wenn der Watcher das erkennt und daraus ableitet
„ab TT.MM. 09:00 alle 60 Sekunden prüfen“, ist die eigentliche Aufgabe
praktisch gelöst. Das ist der wertvollste einzelne Mechanismus im ganzen Plan.

---

## 3. Datenquelle wählen — in dieser Reihenfolge

HTML-Scraping ist der **letzte** Ausweg, nicht der erste. Reihenfolge der
Prüfung in Meilenstein M0:

1. **Interne JSON-API der Website.** DevTools → Netzwerk → Filter „Fetch/XHR“.
   Fast jede moderne Kinoseite lädt ihr Programm per JSON nach. Das ist stabil,
   klein, eindeutig und ändert sich seltener als das Markup.
2. **Ticketsystem direkt.** Deutsche Kinos hängen typischerweise an
   Kinoheld/Eventim.Light, Reservix, CTS Eventim, Vivaticket, TicketPAY oder an
   der hauseigenen Kette (CineStar, Cinemaxx, Cineplex, Yorck, UCI). Die haben
   meist eine JSON-Schnittstelle pro Kino-ID mit Vorstellungen und
   Sitzplatzverfügbarkeit. Vorsicht: hier zuerst die AGB prüfen (siehe §9).
3. **JSON-LD im HTML.** `<script type="application/ld+json">` mit
   `schema.org/ScreeningEvent` bzw. `Event`. Der Schlüssel
   `offers.availability` liefert direkt `InStock`, `PreOrder`, `SoldOut`.
   Sehr stabil, weil für Google gepflegt — ändert sich seltener als das sichtbare Layout.
4. **RSS-/ICS-Feed** des Programms, falls vorhanden.
5. **HTML-Parsing** mit `DOMDocument` + XPath. Fragil, aber als Fallback ok.
6. **Sitemap-Diff** als groben Zusatztrigger: taucht eine neue URL auf, die auf
   den Marathon hindeutet, lohnt ein Sofortcheck.

Wenn die Seite rein clientseitig rendert: **keinen Headless-Browser** aus PHP
heraus bauen. Stattdessen die API ansprechen, die das JavaScript selbst aufruft —
die gibt es immer, und sie ist schneller, stabiler und schonender für den Server.

**Mehrere Quellen parallel** sind ausdrücklich erwünscht: Quelle A (JSON-LD der
Kinoseite) und Quelle B (Ticketsystem) gleichzeitig laufen zu lassen kostet
nichts und halbiert das Risiko, dass eine stille Änderung alles lahmlegt.

---

## 4. Architektur

Ein einzelnes CLI-Skript, das der Cron alle 2 Minuten anstößt und das **selbst
entscheidet**, ob dieser Tick ein echter Prüflauf wird. Dieses Muster
(„Cron-Tick + eigenes Scheduling“) ist deutlich flexibler als mehrere
Crontab-Zeilen, weil der Takt sich adaptiv an den Zustand anpassen kann,
ohne dass jemand die Crontab anfasst.

```
kino-ticket-watcher/
├── bin/
│   └── watch.php                  # CLI-Entrypoint, Arg-Parsing, Exit-Codes
├── config/
│   ├── config.php                 # aus .env gelesen, keine Secrets im Repo
│   └── config.example.php
├── src/
│   ├── Config.php
│   ├── Runner.php                 # Orchestrierung eines Laufs
│   ├── Http/
│   │   └── HttpClient.php         # curl, Retry+Backoff, ETag/If-Modified-Since,
│   │                              # Timeouts, UA, Response-Snapshots
│   ├── Source/
│   │   ├── SourceInterface.php    # fetch(): Screening[]
│   │   ├── JsonApiSource.php
│   │   ├── JsonLdSource.php
│   │   ├── HtmlSource.php
│   │   └── FixtureSource.php      # für Tests
│   ├── Model/
│   │   ├── Screening.php          # readonly class
│   │   └── Availability.php       # enum: UNKNOWN|ANNOUNCED|PRESALE_PENDING|
│   │                              #       BOOKABLE|LOW|SOLD_OUT
│   ├── Matcher/
│   │   └── TitleMatcher.php       # Regex + Normalisierung + Zeitfenster
│   ├── State/
│   │   ├── StateStore.php         # SQLite
│   │   └── migrations/
│   ├── Decide/
│   │   └── TransitionDetector.php # alt vs. neu -> Liste von Ereignissen
│   ├── Notify/
│   │   ├── NotifierInterface.php
│   │   ├── MailNotifier.php       # SMTP
│   │   └── PushNotifier.php       # optional, siehe §7
│   └── Health/
│       └── Watchdog.php           # Heartbeat, Canary, Struktur-Hash
├── var/                           # state.db, logs/, snapshots/  (gitignored)
├── tests/
│   ├── fixtures/                  # echte HTML/JSON-Snapshots je Zustand
│   └── ...
├── composer.json
└── README.md
```

**Abhängigkeiten bewusst minimal.** Pflicht-Extensions: `curl`, `dom`,
`libxml`, `json`, `pdo_sqlite`, `mbstring`, `openssl`. Einzige sinnvolle
Composer-Abhängigkeit ist ein Mailer (`symfony/mailer` oder
`phpmailer/phpmailer`) — siehe §7, warum `mail()` keine Option ist.
Alles andere lässt sich mit Bordmitteln lösen, und je weniger Abhängigkeiten,
desto weniger kann in den 11 Monaten Standby verrotten.

### Ablauf eines Laufs

```
1.  flock() auf var/watch.lock  -> läuft schon einer? dann sofort Exit 0
2.  Scheduler: ist next_check_at erreicht? wenn nein -> Exit 0
3.  Zufälliger Jitter 0–20 s (nicht exakt zur vollen Minute anklopfen)
4.  Für jede aktive Quelle:
      HTTP-GET (conditional, Timeout 10 s, 2 Retries mit Backoff)
      Rohantwort nach var/snapshots/ (die letzten N behalten)
      Parsen -> Screening[]
5.  TitleMatcher: Treffer im Zeitfenster herausfiltern
6.  Watchdog-Checks (Canary: 0 Vorstellungen insgesamt = Fehler, nicht "leer")
7.  Mit dem letzten Zustand aus SQLite vergleichen -> Übergänge ermitteln
8.  Neuen Zustand schreiben, Lauf protokollieren
9.  Für meldepflichtige Übergänge: Cooldown prüfen -> Mail senden -> quittieren
10. next_check_at neu berechnen (adaptiv, siehe §6)
11. Exit-Code: 0 ok, 1 Fehler, 2 degradiert
```

---

## 5. Zustand und Dedup (SQLite)

Ohne Zustandsspeicher mailt der Watcher entweder bei jedem Lauf oder nie.
SQLite-Datei unter `var/state.db`, keine Serverinstallation nötig.

```sql
screenings(
  fingerprint TEXT PRIMARY KEY,   -- sha1(source|title_normalisiert|starts_at)
  source TEXT, title TEXT, starts_at TEXT, booking_url TEXT,
  availability TEXT, seats_free INTEGER,
  first_seen TEXT, last_seen TEXT
)
transitions(id, fingerprint, from_state, to_state, detected_at, payload_json)
notifications(id, kind, fingerprint, sent_at, ok, error)
runs(id, started_at, finished_at, ok, source, http_status,
     items_total, items_matched, error, structure_hash)
meta(key, value)                  -- next_check_at, consecutive_failures, ...
```

Der `fingerprint` muss **stabil** sein. Titel vorher normalisieren:
lowercase, Umlaute entfalten, Interpunktion und Mehrfach-Leerzeichen weg,
Zusätze wie „(OV)“, „Extended Edition“, „3D“, „Teil 1“ nicht in den
Fingerprint aufnehmen. Sonst gilt jede Kosmetikänderung im Titel als neue
Vorstellung und löst einen Fehlalarm aus.

**Gemeldet wird nur bei Übergang**, plus Wiederholung nach Cooldown:

- `→ BOOKABLE`: sofort, und danach alle 6 h erneut, solange unquittiert
  (Quittierung z. B. durch Anlegen einer Datei `var/ack` oder CLI `--ack`)
- `→ ANNOUNCED` / `→ PRESALE_PENDING` / `→ LOW` / `→ SOLD_OUT`: je einmal
- Watchdog-Alarm: einmal, dann täglich, bis wieder grün

---

## 6. Cron-Strategie

**Crontab (eine einzige Zeile, ganzjährig):**

```cron
*/2 * * * * /usr/bin/php8.5 /pfad/kino-ticket-watcher/bin/watch.php >> /pfad/var/cron.log 2>&1
```

Das Skript entscheidet selbst, wie oft es wirklich prüft (`meta.next_check_at`):

| Situation | Prüfintervall |
|---|---|
| Jan–Aug, Zustand `UNKNOWN` | 1× täglich |
| ab 1. September, `UNKNOWN` | alle 30 min |
| `ANNOUNCED` | alle 10 min |
| `PRESALE_PENDING`, VVK-Datum unbekannt | alle 5 min |
| `PRESALE_PENDING`, VVK-Datum bekannt, > 1 h entfernt | alle 15 min |
| `PRESALE_PENDING`, VVK-Datum bekannt, < 1 h entfernt | alle 2 min (also jeder Tick) |
| `BOOKABLE` | alle 10 min (nur noch zur Verlaufsbeobachtung) |
| `SOLD_OUT` | alle 6 h |
| nach Fehler | exponentiell 5/10/20/40 min, gedeckelt bei 60 min |

Das Datumsfenster für relevante Vorstellungen ist großzügig zu wählen
(z. B. 1. Oktober bis 28. Februar), damit ein ungewöhnlich früher oder später
Termin nicht durchrutscht.

`flock` verhindert Überlappung, falls ein Lauf mal hängt. Timeout pro
HTTP-Request 10 s, harter Gesamt-Timeout des Skripts 60 s.

---

## 7. Benachrichtigung

**Mailversand: SMTP, nicht `mail()`.** Über `mail()` verschickte Nachrichten
landen ohne SPF/DKIM zuverlässig im Spam — und eine Alarmmail im Spam-Ordner
ist genau der stille Ausfall aus §1. Also SMTP mit App-Passwort
(z. B. Gmail-App-Passwort, Zugangsdaten in `.env`, nicht im Repo).

Die Mail muss so gebaut sein, dass sie auf dem Handy in drei Sekunden
verstanden ist:

- **Betreff:** `[KINO-ALARM] HdR-Marathon 13.12. — Buchung offen (142 Plätze)`
  — eindeutiges Präfix, damit sich eine Posteingangsregel/Push darauf setzen lässt
- **Body (Text + einfaches HTML):** Direktlink zum Buchungsflow ganz oben,
  darunter Termin(e), Kinosaal, freie Plätze, Zeitstempel der Erkennung,
  Quelle, und der vorherige Zustand („war vor 4 Minuten noch `PRESALE_PENDING`“)

**Ehrlicher Hinweis:** Eine Mail weckt nachts niemanden, und der Vorverkauf
startet nicht selten früh morgens. Mail bleibt der Pflichtkanal wie gewünscht,
aber ein zweiter Kanal für den `BOOKABLE`-Alarm ist billig und deutlich
wirksamer — Telegram-Bot, `ntfy.sh`, Pushover oder ein SMS-Gateway sind alle
in ~30 Zeilen angebunden. Das Interface `NotifierInterface` ist im Entwurf
genau dafür da; ob du es nutzt, entscheidest du später.

---

## 8. Ausfallsicherheit (der Teil, der am häufigsten fehlt)

- **Heartbeat / Dead-Man-Switch:** kein erfolgreicher Lauf seit X Stunden
  (X = 3× das erwartete Intervall) → Alarmmail „Watcher meldet sich nicht“.
  Zusätzlich ein externer Dienst (Healthchecks.io o. ä.), den das Skript nach
  jedem erfolgreichen Lauf anpingt — der merkt auch, wenn der ganze Server
  hängt und der Watcher gar nicht mehr zum Mailen kommt.
- **Canary-Assertion:** Findet der Parser **null** Vorstellungen, obwohl das
  Kino garantiert Programm hat, ist das ein Parserfehler, kein leeres Ergebnis.
  → Alarm. Diese eine Regel fängt die Mehrzahl aller Layout-Wechsel ab.
- **Struktur-Hash:** Hash über die Schlüsselstruktur der Antwort (JSON-Keys
  bzw. die Selektorpfade). Ändert er sich, Warnmail — auch wenn das Parsen
  noch klappt. Frühwarnung vor dem echten Bruch.
- **Snapshots:** jede Rohantwort der letzten N Läufe unter `var/snapshots/`
  aufheben. Wenn etwas schiefgeht, ist das die einzige Möglichkeit zur
  Nachanalyse — und es liefert nebenbei neue Testfixtures.
- **Lebenszeichen-Mail:** in der heißen Phase (ab September) einmal wöchentlich
  eine kurze „läuft, Zustand X, letzter erfolgreicher Lauf um Y“-Mail. Nervt
  minimal, beweist aber, dass die gesamte Kette inklusive Mailversand
  funktioniert.
- **Kalendereintrag „Watcher-Trockenlauf“ für Mitte August**, jedes Jahr.

---

## 9. Höflichkeit und Rechtliches

Das ist kein Ticketkauf-Bot, sondern ein Benachrichtigungswerkzeug für eine
öffentlich einsehbare Seite. Diese Grenze sollte der Code auch einhalten:

- `robots.txt` respektieren
- Eigener User-Agent mit Kontaktadresse:
  `KinoTicketWatcher/1.0 (persönlicher Benachrichtigungs-Watcher; <deine Mail>)`
- Conditional Requests (ETag / If-Modified-Since) — spart beiden Seiten Traffic
- Rate-Limit: maximal ein Request alle 2 Minuten pro Endpunkt, keine parallelen
  Bursts, keine Sitzplan-Abfragen in Schleife
- **Nur lesend.** Kein automatisches Reservieren, kein Login-Bereich, keine
  Captcha-Umgehung, kein Warenkorb.
- AGB des Ticketanbieters prüfen: viele untersagen automatisiertes Auslesen
  ausdrücklich. Im Zweifel nur die Kino-Website selbst pollen (die will ja
  gefunden werden) und nicht den Ticketdienstleister.

Bei diesem Takt (ein paar hundert Requests im Jahr, in der heißen Phase ein
paar tausend) ist die Last für das Kino irrelevant — solange man nicht in den
Sekundentakt geht.

---

## 10. Tests

- **Fixtures aus echten Snapshots** für jeden Zustand aus §2, mindestens für
  `PRESALE_PENDING`, `BOOKABLE` und `SOLD_OUT`. Der `BOOKABLE`-Snapshot ist der
  wichtigste und **nur jetzt** beschaffbar (siehe §1).
- Unit-Tests für `TitleMatcher` (auch Negativfälle: ein normaler
  „Der Hobbit“-Termin darf keinen Marathon-Alarm auslösen) und für
  `TransitionDetector` (jeder Übergang genau einmal gemeldet, Cooldown greift).
- Integrationstest: `FixtureSource` durchlaufen lassen, Mailversand gegen einen
  Dummy-Transport, geprüft wird die erzeugte Mail.
- CLI-Flags zum manuellen Prüfen:
  `--dry-run`, `--verbose`, `--force-notify`, `--fixture=<pfad>`,
  `--source=<name>`, `--ignore-schedule`, `--ack`, `--status`

---

## 11. Meilensteine

| # | Inhalt | Aufwand |
|---|---|---|
| **M0** | Recherche: DevTools-Analyse der Kinoseite, Quelle nach §3 festlegen, Fixtures sichern (siehe §1) | 1–2 h |
| **M1** | Gerüst: `HttpClient`, `SourceInterface` + eine konkrete Quelle, `TitleMatcher`, CLI-Ausgabe der Treffer | 3–4 h |
| **M2** | SQLite-State, Zustandsmaschine, Übergangserkennung, Dedup | 2–3 h |
| **M3** | SMTP-Mailversand, Mail-Templates, Cooldown/Quittierung | 2 h |
| **M4** | Watchdog: Heartbeat, Canary, Struktur-Hash, Snapshots, Logging | 2–3 h |
| **M5** | Deployment: Server, PHP 8.5 prüfen, Crontab, `.env`, Logrotation, Ende-zu-Ende-Test | 1–2 h |
| **M6** | Zweite Quelle als Redundanz + Trockenlauf im August | 2 h |

M0–M5 sind ein gutes Wochenendprojekt. M6 kann warten, sollte aber vor
September stehen.

---

## 12. Risiken

| Risiko | Gegenmaßnahme |
|---|---|
| Layout-/API-Wechsel in den 11 Monaten Standby | Canary-Assertion, Struktur-Hash, zweite Quelle, Trockenlauf im August |
| Marathon wird über einen ganz anderen Kanal angekündigt (Instagram, Aushang) | Newsletter-Abo behalten; Titel-Regex breit halten (`marathon`, `mittelerde`, `tolkien`, `lord of the rings`, `hdr`) |
| Alarmmail landet im Spam | SMTP statt `mail()`, Absender auf Whitelist, wöchentliche Lebenszeichen-Mail als Beweis der Kette |
| Alarm kommt nachts, keiner sieht ihn | optionaler Push-Kanal (§7) |
| Cron läuft nicht mehr / Server weg | externer Heartbeat-Dienst |
| Titel weicht ab und Regex greift nicht | zusätzlich auf Dauer > 8 h oder Eintrag in einer „Specials/Events“-Rubrik prüfen; bei unsicheren Treffern lieber einmal zu viel mailen |
| Vorverkauf läuft nur über Telefon/Kasse | im Plan nicht lösbar — beim ersten `ANNOUNCED` selbst beim Kino nachfragen |

---

## 13. Offene Punkte / was ich von dir brauche

1. **URL des Kinos** bzw. der Programmseite → entscheidet §3 und damit den
   konkreten Adapter. (Du wolltest sie nachreichen.)
2. Läuft der Cronjob auf einem Server, einem NAS oder einem Raspberry Pi?
   → bestimmt, ob PHP 8.5 verfügbar ist und welcher SMTP-Weg praktikabel ist.
3. Ziel-Mailadresse und SMTP-Zugang (Provider genügt, Zugangsdaten kommen
   in die `.env` und niemals ins Repo).
4. Sollen auch andere Events überwacht werden (weitere Marathons, Previews)?
   → wenn ja, Suchmuster von Anfang an als Liste konfigurierbar, nicht hart
   auf Herr der Ringe verdrahtet.
5. Zweiter Benachrichtigungskanal gewünscht (Telegram/ntfy/Pushover)? Optional,
   aber für einen Alarm um 09:00 Uhr deutlich wirksamer als Mail allein.

---

## Anhang: PHP-8.5-Hinweise

Auf dem Zielserver zuerst prüfen:

```bash
php8.5 -v
php8.5 -m | grep -E 'curl|dom|json|pdo_sqlite|mbstring|openssl'
```

Sprachmittel, die dem Entwurf entgegenkommen:

- `enum Availability` für die Zustandsmaschine aus §2 (seit 8.1) und
  `readonly class Screening` als unveränderliches Wertobjekt (seit 8.2)
- **Fatal-Error-Backtraces** (neu in 8.5) — bei einem Cronjob, der nur eine
  Logdatei hinterlässt, ist das der praktisch wertvollste Zugewinn
- **Pipe-Operator `|>`** (neu in 8.5) für die Parser-Kette
  (roh → dekodieren → normalisieren → filtern) statt verschachtelter Aufrufe
- `array_first()` / `array_last()` (neu in 8.5) — spart die üblichen
  `reset()`/`end()`-Klimmzüge beim Zugriff auf Trefferlisten
- `#[\NoDiscard]` (neu in 8.5) für Funktionen, deren Rückgabewert man nicht
  ignorieren darf — z. B. das Ergebnis des Mailversands

Falls auf dem Zielsystem nur PHP 8.3/8.4 verfügbar ist: der Entwurf läuft
unverändert, es entfallen lediglich die vier zuletzt genannten Annehmlichkeiten.
