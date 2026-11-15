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

### 1. Die API abfragen, nicht die Seite lesen

Eine HAR-Aufzeichnung der Filmseite hat gezeigt, dass cinemaxx.de eine
öffentliche JSON-API hat:

```
GET /api/microservice/showings/cinemas/{cinemaId}/films
    ?minEmbargoLevel=1&includesSession=true&includeSessionAttributes=true
```

Keine Authentifizierung, `Cache-Control: public, max-age=60`, `Last-Modified`
vorhanden. Ein Request liefert alle Filme des Kinos samt Vorstellungen. Die
Felder beantworten die Frage direkt, statt sie raten zu lassen:
`isBookingAvailable`, `isSoldOut`, `embargoEndDate`, `bookingUrl`.

Damit entfällt der gesamte Interpretationsaufwand. **JSON-LD gibt es auf der
Seite übrigens nicht** — die ursprünglich geplante Bevorzugung von
`schema.org`-Daten ging ins Leere.

Die Kennungen stehen im `__NEXT_DATA__` der Seite (Next.js auf Sitecore):

```
context.cinema.cinemaId.value  -> "1391"        CinemaxX Magdeburg
context.film.filmId.value      -> "HO00001617"  Der Herr der Ringe Triple
```

Die GUIDs daneben (`context.cinema.id`) sind Sitecore-intern und für die API
nutzlos — eine Falle, in die man beim ersten Hinsehen tappt.

Gefiltert wird über den Filmtitel, nicht über eine URL: Der Slug lautet nächstes
Jahr anders, die API-Abfrage bleibt gleich.

### 2. HTML als Rückfallebene, nicht als Hauptweg

Die API ist nicht dokumentiert und kann sich ändern. Fällt sie aus, sucht der
Watcher über Programmseite und `sitemap.xml` weiter und wertet Filmseiten mit
Heuristiken aus. Das ist deutlich fragiler und deshalb ausdrücklich Reserve —
aber es verhindert, dass ein API-Umbau den Watcher komplett blind macht.

Der Ausfall wird protokolliert und fließt in den Fehler-Backoff ein, damit er
nicht unbemerkt zum Dauerzustand wird.

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

## Drei Fehler, die die Tests gefunden haben

Beide sind festgehalten, weil sie im Ernstfall teuer gewesen wären und als
Regressionstests weiterleben:

**Der Vorverkaufsstart wurde als Vorstellungstermin gelesen.** Auf einer
Filmseite stehen mindestens zwei Daten — der Termin und der Vorverkaufsstart.
Das früheste ist fast immer der Vorverkauf. Blind das erste zu nehmen, setzt den
falschen Tag in Betreff und Fingerprint.

**Der Fingerprint war nicht stabil über Quellenwechsel hinweg.** Dieselbe
Vorstellung erreicht uns je nach Quelle als `2026-12-13` (Seitentext) oder als
`2026-12-13T11:00:00+01:00` (API). Ging die Uhrzeit in den Fingerprint ein,
galt sie nach einem Quellenwechsel als neue Vorstellung — und genau der Übergang
„→ buchbar" wäre verloren gegangen. Der Fingerprint nutzt deshalb nur den
Kalendertag.

Aus demselben Grund steht der Saalname (`Kino 07`) **nicht** im Titel, obwohl
die API ihn liefert: Ein umgelegter Saal hätte dieselbe Vorstellung als neue
erscheinen lassen. Er steht in einem rein informativen Zusatzfeld.

**Ein `304 Not Modified` hätte alle Vorstellungen als „verschwunden" gemeldet.**
Die API schickt `Last-Modified`, der Watcher stellt bedingte Requests. Bei `304`
kommt kein Body — und der naive Vergleich „was war vorher da, was ist jetzt da"
hätte daraus geschlossen, die Vorstellung sei aus dem Programm genommen worden,
samt Warnmail. Ohne frische Daten wird der gespeicherte Zustand jetzt gar nicht
erst angefasst.

## Offen: der Embargo-Fall

Der Zustand *buchbar* ist an echten Daten geprüft — die API-Fixtures stammen aus
einer HAR-Aufzeichnung der Seite vom 14.09.2026, als der Vorverkauf für den
Marathon am **7. November 2026, 12:00 Uhr, Kino 07** bereits lief.

Genau deshalb fehlt der interessantere Fall: Wie die API antwortet, **bevor** der
Vorverkauf freigeschaltet wird, ist nicht belegt. Die Embargo-Fixture wurde aus
der echten Antwort abgeleitet, indem `embargoEndDate` gesetzt und
`isBookingAvailable` auf `false` gestellt wurde. Das entspricht der Semantik der
Feldnamen und dem Query-Parameter `minEmbargoLevel=1`, ist aber eine begründete
Annahme und keine Messung.

Falls CinemaxX dort anders antwortet — etwa indem embargierte Filme gar nicht
erst in der Liste auftauchen —, ist das der Punkt zum Nachschärfen. Dagegen
helfen drei Dinge: die HTML-Rückfallebene, der Canary bei null Filmen, und die
Snapshots jeder Rohantwort unter `var/snapshots/`.

Wenn der Marathon 2027 angekündigt wird, lohnt ein Blick in `var/snapshots/`,
sobald der Zustand `DATED` das erste Mal auftaucht: Dann liegt die echte
Embargo-Antwort vor und die Annahme lässt sich bestätigen oder korrigieren.
