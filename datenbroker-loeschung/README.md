# Datenbroker-Löschung

Schreibt Datenbroker, Adresshändler und Auskunfteien an. Es stellt
Löschanträge nach Art. 17 DSGVO und widerspricht der Nutzung für Werbung
(Art. 21). Bei Auskunfteien holt es die kostenlose Selbstauskunft ein (Art. 15).
Antworten liest es aus dem Postfach und ordnet sie ein. Bleibt eine Antwort aus,
erinnert es. Hilft auch das nicht, legt es einen Beschwerde-Entwurf für die
Aufsichtsbehörde bereit. Alle paar Monate beginnt es von vorn, denn Broker
kaufen Daten nach.

Das ist im Kern das, was Incogni & Co. für rund 100 € im Jahr machen.

PHP 8.3+ (gedacht für 8.5 auf IONOS), keine Abhängigkeiten, kein Composer, keine
Datenbank. Der Zustand liegt in einer JSON-Datei, wie beim Kino-Watcher.

---

## Wie es funktioniert

Ein Cronjob startet `bin/run.php` einmal pro Stunde. Jeder Lauf erledigt
nacheinander:

1. **Vorgänge anlegen.** Für jede ausgewählte Firma, die noch keinen Vorgang hat
   oder deren letzte Runde lange genug zurückliegt.
2. **Posteingang lesen** (IMAP, nur lesend). Neue Antworten werden zugeordnet
   und eingeordnet.
3. **Fristen prüfen.** Ist die Monatsfrist abgelaufen, geht eine Erinnerung
   raus. Ist auch die Nachfrist abgelaufen, entsteht ein Beschwerde-Entwurf.
4. **Anfragen verschicken**, gedrosselt auf 5 pro Lauf.
5. **Dich benachrichtigen**, wenn du dran bist. Dazu kommt einmal pro Woche eine
   Übersicht.

Erst lesen, dann Fristen prüfen: Sonst bekäme eine Firma eine Erinnerung,
obwohl ihre Antwort im selben Lauf eintrifft.

### Lebenslauf eines Vorgangs

```
wartet auf Versand ──► Frist läuft ──(1 Monat + 3 Tage)──► erinnert ──(14 + 3 Tage)──► du bist dran (Beschwerde)
                          │    ▲                            │
                          │    └── Eingangsbestätigung,     │
                          │        Fristverlängerung        │
                          ▼                                 ▼
                       erledigt ◄── „gelöscht“, „gesperrt“, „keine Daten“
                          │
                          └──(90 Tage)──► neue Runde
```

Die Monatsfrist zählt kalendarisch (Art. 12 Abs. 3 DSGVO): Vom 31.1. aus
endet sie am 28.2., nicht nach 30 Tagen. Verlängert eine Firma nach
Art. 12 Abs. 3 S. 2, wird die Frist auf drei Monate gestreckt. Das gilt auch,
wenn die Verlängerung erst als Antwort auf die Erinnerung kommt.

### Wie Antworten zugeordnet werden

In dieser Reihenfolge, die zuverlässigste Methode zuerst:

| Merkmal | Beispiel |
|---|---|
| `In-Reply-To` / `References` | normale Antwort im Mailprogramm |
| Referenz im Betreff oder Text | `[Ref DB-7K3Q9X]`, auch aus dem Zitat |
| Plus-Adresse | `datenschutz+db-7k3q9x@…` (optional, `smtp.reply_to`) |
| Message-ID im Zustellbericht | Bounce |
| Absenderdomain | Ticketsystem antwortet ohne Thread und ohne Betreff |

Die Absenderdomain greift nur, wenn sie eindeutig ist. Gehören mehrere Firmen
zum selben Konzern, wird nur zugeordnet, wenn genau eine davon einen offenen
Vorgang hat. Alles andere bleibt unzugeordnet und taucht in der
Wochenübersicht auf.

### Wie Antworten eingeordnet werden

Nach Schlagworten auf Deutsch und Englisch. Der zitierte Teil der Mail wird
vorher abgeschnitten, denn dort steht unsere eigene Anfrage – und die steckt
voller Wörter wie „Löschung“, „keine“ und „Frist“.

| Einordnung | Folge |
|---|---|
| bestätigt („gelöscht“, „Werbesperrdatei“, „suppression list“) | **erledigt** |
| keine Daten | **erledigt** |
| Eingangsbestätigung, Autoantwort | Frist läuft weiter |
| Frist verlängert | Frist auf drei Monate |
| Identitätsnachweis, Bestätigungslink, Formular verlangt | **du bist dran** |
| abgelehnt („berechtigtes Interesse“, „unable to delete“) | **du bist dran** |
| unzustellbar | **du bist dran** |
| unklar | **du bist dran** |

**Grundsatz: Im Zweifel nicht abschließen.** Ein fälschlich als erledigt
markierter Vorgang fällt niemandem auf. Eine fälschlich als unklar markierte
Antwort kostet dich nur einen Blick. Deshalb schließen nur eindeutige
Bestätigungen ohne Gegenanzeichen ab. Jede Antwort wird zusätzlich im
Rohformat unter `var/letters/<Ref>/` abgelegt.

### Welche Mails du bekommst

| Anlass | Mail |
|---|---|
| Vorgänge brauchen dich (Nachweis, Ablehnung, unklar, Bounce) | sofort, gesammelt pro Lauf |
| Firma nur per Formular oder Brief erreichbar | sofort, mit dem fertigen Text zum Einfügen |
| Nachfrist abgelaufen | sofort, mit Beschwerde-Entwurf und Adresse der Behörde |
| Posteingang seit 24 h nicht lesbar | einmal |
| Wochenübersicht | montags ab 8 Uhr |

Was du dann tust, meldest du über die Weboberfläche oder `bin/case.php`
zurück: **erledigt**, **wartet wieder**, **erneut senden**, **ignorieren**.

---

## Welche Firmen angeschrieben werden

Die Kontaktdaten stammen aus der offenen Firmendatenbank von
[Datenanfragen.de](https://github.com/datenanfragen/data) (CC0), ebenso die
Brieftexte. Ein Stand liegt in `data/` bei, `bin/sync-db.php` frischt ihn auf.

| Paket | Inhalt | Anfrage |
|---|---|---|
| `address-brokers` | Adresshandel: AZ Direct, Deutsche Post Direkt/Adress, Acxiom, Schober, ABIS, … | Löschung + Widerspruch |
| `marketing-data` | Zielgruppendaten: beDirect, Nexiga, panadress, LiveRamp, Epsilon | Löschung + Widerspruch |
| `people-search` | Das Telefonbuch, MyLife, ZoomInfo, Apollo, Lusha, RocketReach, Kaspr, ContactOut | Löschung + Widerspruch |
| `credit-agencies` | SCHUFA, CRIF, infoscore, Regis24, IHD, Besurance HIS, Euro-Pro | **Selbstauskunft** |

Warum Auskunft statt Löschung bei Auskunfteien: Dort wird eine Löschung fast
immer mit „berechtigtem Interesse“ abgelehnt, und zwar rechtlich meist zu
Recht. Die jährliche Selbstauskunft ist dagegen kostenlos und deckt falsche
Einträge auf. Wer es anders will, stellt `brokers.kind_by_category` oder
`brokers.kind_by_slug` um.

`php bin/preview.php` zeigt die vollständige Liste, Art und Sprache der Anfrage
und den Weg (E-Mail, Formular, Brief).

### Deine Incogni-Liste übernehmen

Noch während das Abo läuft: die Liste der angeschriebenen Firmen aus dem
Incogni-Dashboard abschreiben oder kopieren, einen Namen pro Zeile, in
`incogni.txt`. Dann:

```bash
php8.5 bin/find.php --file=incogni.txt
```

Gefundene Firmen erscheinen mit Slug, und am Ende steht eine fertige
`'add' => [...]`-Zeile für `config.php`. Nicht gefundene sucht man auf
[datenanfragen.de](https://www.datenanfragen.de) und lädt sie mit
`bin/sync-db.php --add=<slug>` nach. Steht eine Firma dort nicht, trägt man sie
selbst unter `brokers.custom` ein.

Viele Firmen auf der Incogni-Liste sind US-Personensuchen, die nur ein
Webformular anbieten. Die landen als „du bist dran“ mit dem fertigen Text zum
Einfügen bei dir. Ein Formular auszufüllen nimmt dir das Werkzeug nicht ab.

---

## Installation

### 1. Eigenes Postfach anlegen

Zum Beispiel `datenschutz@deine-domain.de`, nur für diesen Zweck. Damit liest
der IMAP-Abruf nur Antworten und nicht deine sonstige Post. Außerdem stimmen
dann Absender und SMTP-Konto überein, was für die Zustellbarkeit nötig ist.

### 2. Hochladen und konfigurieren

```bash
scp -r datenbroker-loeschung/ benutzer@dein-host:~/
cd ~/datenbroker-loeschung
cp config/config.example.php config/config.php
php8.5 bin/selftest.php --secret      # zweimal: tick_secret und dashboard_secret
nano config/config.php
```

In `config.php` gehören Name, Anschrift und E-Mail-Adressen, unter denen du
bekannt bist, außerdem SMTP, IMAP, `notify.to` und die Aufsichtsbehörde.
**`'live' => false` stehen lassen.**

### 3. Prüfen

```bash
php8.5 bin/selftest.php --smtp --imap   # Umgebung, Testmail, Posteingang
php8.5 bin/preview.php                  # wer bekommt was
php8.5 bin/preview.php az-direct        # ein Brief im Wortlaut
php8.5 bin/run.php                      # Testlauf: legt Vorgänge an, verschickt nichts
php8.5 bin/status.php
```

Die Testmail muss im **Posteingang** ankommen. Liegt sie im Spam, landen die
Anfragen bei den Firmen vermutlich auch dort (siehe unten).

### 4. Scharf schalten

In `config.php` `'live' => true` setzen. Der nächste Lauf verschickt die ersten
fünf Anfragen, danach jede Stunde weitere fünf. Bei rund 30 Firmen ist die
erste Runde nach sechs bis sieben Stunden durch.

### 5. Cron

```cron
MAILTO=""
17 * * * * /usr/bin/php8.5 /homepages/<pfad>/datenbroker-loeschung/bin/run.php --quiet >> /homepages/<pfad>/datenbroker-loeschung/var/cron.log 2>&1
```

Ohne echten Crontab: Die Subdomain auf `public/` zeigen lassen und bei
[cron-job.org](https://cron-job.org) stündlich
`https://…/tick.php?key=<tick_secret>` aufrufen. Das funktioniert genauso wie
beim Kino-Watcher.

### 6. Weboberfläche (optional)

Mit derselben Subdomain auf `public/` ist die Übersicht unter
`https://…/?key=<dashboard_secret>` erreichbar. Dort steht, wer dich braucht
(oben), und für jeden Vorgang gibt es Verlauf, Antworten, die abgelegten Briefe
und die Knöpfe. Die URL kommt in die Lesezeichen. In `dashboard_url` (ohne
Schlüssel) eingetragen, erscheint sie auch in den Mails.

Ohne `dashboard_secret` antwortet die Seite mit 404. Ohne Weboberfläche geht
alles auch per SSH:

```bash
php8.5 bin/status.php DB-7K3Q9X        # ein Vorgang im Detail
php8.5 bin/case.php DB-7K3Q9X done     # done | wait | resend | ignore | reopen
```

---

## Zustellbarkeit

Ein Löschantrag im Spamordner des Brokers ist ein Antrag, der nie gestellt
wurde. Deshalb echter SMTP-Versand wie beim Kino-Watcher (quoted-printable,
korrekte `Message-ID`, keine Mailer-Kennung). Was der Code nicht lösen kann:

1. **Absender und SMTP-Konto aus derselben Domain.** `bin/selftest.php` warnt,
   wenn nicht.
2. **SPF und DKIM** für die Absenderdomain einrichten. Bei IONOS sind das zwei
   Klicks.
3. Briefe an Firmen tragen bewusst **keinen** `Auto-Submitted`-Header. Manche
   Ticketsysteme legen für solche Mails gar keinen Vorgang an.

---

## Was in `var/` liegt – und warum es geschützt sein muss

| Pfad | Inhalt |
|---|---|
| `state.json` | alle Vorgänge, Fristen, Posteingangs-Cursor |
| `events.jsonl` | jedes Ereignis, append-only |
| `letters/<Ref>/` | jeder verschickte Brief im Wortlaut und jede Antwort als `.eml` |
| `db/` | per `sync-db.php` aktualisierte Firmendaten |

`letters/` ist dein Beleg für eine Beschwerde. Es enthält aber auch deine
Anschrift und die Antworten der Firmen. Das Verzeichnis darf deshalb nie
unter einer Domain erreichbar sein. Die Ordnerstruktur sorgt dafür, wenn nur
`public/` ausgeliefert wird. Die `.htaccess`-Dateien sind die zweite
Sicherung.

---

## Grenzen

**Die Einordnung beruht auf Schlagworten.** Sie ist bewusst vorsichtig
eingestellt. Rechne trotzdem damit, dass in der ersten Runde ein Drittel der
Antworten als „unklar“ bei dir landet. Typische Muster lassen sich in
`src/Cases/Classifier.php` nachschärfen. Die Rohantworten dafür liegen in
`var/letters/`.

**Formulare und Briefe bleiben Handarbeit.** Das Werkzeug bereitet den Text
vor und erinnert dich daran, ausfüllen musst du selbst. Gleiches gilt für
Bestätigungslinks und Rückfragen.

**Keine Ausweiskopien.** Fordert eine Firma einen Ausweis an, landet das bei
dir. Nach Art. 12 Abs. 6 DSGVO darf sie zusätzliche Nachweise nur bei
begründeten Zweifeln an deiner Identität verlangen. Name und Anschrift reichen
fast immer.

**Die Datenbank kann veralten.** Eine geänderte Datenschutzadresse fällt als
Bounce auf. Danach hilft `bin/sync-db.php`. Das lohnt sich einmal im Quartal
ohnehin.

**Keine Rechtsberatung.** Die Brieftexte sind die erprobten Vorlagen von
Datenanfragen.de. Ob eine Ablehnung rechtens ist, beurteilt das Werkzeug nicht.

---

## Tests

```bash
php tests/run.php     # ohne Netz
```

Die Suite deckt Folgendes ab:

- Register und Pakete
- Brieftexte auf Deutsch und Englisch, mit Pflichtangaben
- Fristen über das Monatsende
- MIME (Latin-1, quoted-printable, base64, Nur-HTML, Bounces)
- Einordnung von gut zwanzig typischen Antwortmustern, auch mit zitierter eigener Anfrage
- SMTP und IMAP gegen ein vorbereitetes Socket-Paar
- den Ablauf durch mehrere Runden: Versand mit Drossel, Zuordnung über Thread,
  Referenz, Domain und Bounce, Doppelzustellung, Erinnerung im Thread,
  Fristverlängerung, Eskalation, Handaktionen, neue Runde, Nachzügler zur
  alten Runde
- Testmodus, SMTP- und IMAP-Ausfall
- Wochenübersicht und Weboberfläche
