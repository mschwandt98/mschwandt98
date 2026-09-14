# Entwurfsentscheidungen

Warum der Watcher so gebaut ist, wie er gebaut ist. Die Bedienung steht im
[README](README.md).

## Ausgangslage

Der Herr-der-Ringe-Marathon läuft einmal jährlich gegen Jahresende im CinemaxX
Magdeburg. Newsletter-Abo und wöchentliches manuelles Nachsehen haben 2026 nicht
gereicht: Als der Vorverkauf sichtbar wurde, waren die guten Plätze weg.

Daraus folgt alles Weitere:

1. **Latenz zählt.** Ein Treffer, der sechs Stunden zu spät gemeldet wird, ist
   wertlos.
2. **Stiller Ausfall ist das Hauptrisiko.** Ein Watcher, auf den man sich
   verlässt und der seit Wochen nichts mehr findet, ist schlimmer als keiner.
3. **Einmal pro Jahr scharf.** Elf Monate Standby, keine Gelegenheit zum
   Nachbessern im entscheidenden Moment.

## Randbedingungen

| | |
|---|---|
| Zielsystem | IONOS Shared Hosting, Cron nur über Webinterface, **frühestens alle 6 Stunden** |
| Sprache | PHP 8.5 (Produktiv-Entrypoints erzwingen das) |
| Abhängigkeiten | keine — kein Composer |
| Benachrichtigung | ausschließlich Mail, SMTP |

## Die vier tragenden Entscheidungen

### 1. Das Programm durchsuchen, nicht eine URL beobachten

Der Slug von 2026 (`der-herr-der-ringe-triple-extended-version`) wird nächstes
Jahr anders lauten. Ein Watcher, der nur diese URL pollt, ist genau dann blind,
wenn es darauf ankommt. Deshalb laufen drei Wege parallel: Programmübersicht,
`sitemap.xml` und die bekannten URLs aus der Konfiguration.

Die Sitemap ist der beste Frühindikator — ausdrücklich für Crawler gedacht,
stabil im Format, und eine neue Film-URL steht dort oft, bevor sie prominent
verlinkt ist.

### 2. Klassifikator **und** Roh-Diff

Ein Klassifikator muss die Seitenstruktur kennen. Diese Struktur war bei der
Entwicklung nicht einsehbar und kann sich im Standby ändern. Ein Klassifikator
allein kann also stillschweigend falsch liegen.

Deshalb zwei Ebenen: der Klassifikator für präzise Alarme, und ein Digest über
den normalisierten sichtbaren Text als Sicherheitsnetz. Ändert sich der Inhalt,
ohne dass der Klassifikator etwas erkennt, geht trotzdem eine Mail raus. Bei
einem Ereignis, das einmal im Jahr stattfindet, ist eine Mail zu viel die
richtige Richtung für den Fehler.

### 3. Zustand als JSON-Datei, nicht SQLite

Auf Shared Hosting ist jede Extension ein Risiko, und die Datenmenge ist winzig.
`var/state.json` wird atomar geschrieben (Temp-Datei + `rename`), `flock`
verhindert überlappende Läufe, `var/events.jsonl` ist die Append-only-Historie.
Beides per FTP herunterladbar und mit bloßem Auge lesbar — das ist beim Debuggen
in einem Jahr mehr wert als eine Datenbank.

Eine kaputte Zustandsdatei wird beiseitegelegt statt überschrieben.

### 4. Zwei Taktgeber statt eines Dauerprozesses

Sechs Stunden Prüfintervall sind für einen Vorverkaufsstart wertlos. Ein
Dauerprozess, der zwischen zwei Cron-Läufen am Leben bleibt, wurde verworfen:
Shared Hosting räumt solche Prozesse gern ab, und ob IONOS das tut, ließe sich
nur durch einen mehrstündigen Versuch klären, dessen Ausgang den ganzen Entwurf
umwerfen würde. Ein Fundament, das vielleicht trägt, ist das falsche Fundament.

Stattdessen: externer Cron-Dienst im Minutentakt als Prüftakt, IONOS-Cron alle
6 Stunden als Sicherheitsnetz, das zusätzlich meldet, wenn der externe Taktgeber
stumm wird. Keiner der beiden ist ein Single Point of Failure.

Wie oft tatsächlich bei CinemaxX angefragt wird, entscheidet allein der
Scheduler — die meisten Minutenticks enden als No-op.

## Zwei Fehler, die die Tests gefunden haben

Beide sind festgehalten, weil sie im Ernstfall teuer gewesen wären und als
Regressionstests weiterleben:

**Der Vorverkaufsstart wurde als Vorstellungstermin gelesen.** Auf einer
Filmseite stehen mindestens zwei Daten — der Termin und der Vorverkaufsstart.
Das früheste ist fast immer der Vorverkauf. Blind das erste zu nehmen, setzt den
falschen Tag in Betreff und Fingerprint.

**Der Fingerprint war nicht stabil über Quellenwechsel hinweg.** Dieselbe
Vorstellung erreicht uns je nach Quelle als `2026-12-13` (Seitentext) oder als
`2026-12-13T11:00:00+01:00` (JSON-LD). Ging die Uhrzeit in den Fingerprint ein,
galt sie nach einem Quellenwechsel als neue Vorstellung — und genau der Übergang
„→ buchbar" wäre verloren gegangen. Der Fingerprint nutzt deshalb nur den
Kalendertag.

## Offen: Schärfung an der echten Seite

Die Parser-Heuristiken sind noch nicht an `cinemaxx.de` kalibriert — die
Entwicklungsumgebung kam nicht an die Seite heran, und geraten wurde bewusst
nichts. JSON-LD wird bevorzugt ausgewertet; darunter greifen grobe Heuristiken
auf Merkmale zurück, die praktisch jede deutsche Kinoseite hat.

Nächster Schritt: `php8.5 bin/discover.php > discovery-report.txt` auf dem
Zielsystem ausführen. Der Bericht zeigt, ob es JSON-LD gibt, wie Buchungslinks
aussehen und was `robots.txt` erlaubt. Danach lassen sich
`booking_link_patterns` und die Parser präzisieren.

**Zeitkritisch:** Solange der Vorverkauf noch läuft, einen Abzug der Seite im
Zustand *buchbar* sichern (`var/discovery/`, HTML speichern, DevTools →
Netzwerk → „Save all as HAR"). Das ist die wertvollste Testfixture und in
wenigen Wochen nicht mehr beschaffbar.
