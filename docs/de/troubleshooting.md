---
title: Fehlersuche
slug: troubleshooting
order: 40
summary: Was jeder Fehler tatsächlich bedeutet, und wie er behoben wird.
---

Hier anfangen:

```sh
php craft bexy/doctor
```

Dann **Bexy → Protokoll**, gefiltert auf *Fehler*. Beide Inhalte sind da, Secrets unkenntlich
gemacht.

## Verbindung

### «bexio hat die Zugangsdaten abgelehnt. Verbinden Sie neu oder stellen Sie einen neuen Access Token aus.»

Ein 401. Bei einem persönlichen Zugriffstoken heisst das fast immer, dass die 60 Tage um sind —
bexio lässt sie stillschweigend verfallen. Neuen ausstellen oder auf OAuth wechseln.

Bei OAuth heisst es, die Erneuerung ist gescheitert. Neu verbinden.

### Die OAuth-Verbindung stirbt nach einer Stunde

`offline_access` fehlte in den Scopes, bexio hat also nie einen Refresh-Token ausgestellt. In bexio
zur App hinzufügen und neu verbinden.

### «Der Authorization State stimmte nicht überein. Starten Sie die Verbindung erneut.»

Der Callback kam nicht in der Session an, die ihn gestartet hat. Meist ein veralteter Tab, ein
anderer Browser oder eine Redirect-URL, die nicht exakt der in bexio registrierten entspricht.

### «bexio hat keinen Authorization Code zurückgesendet.»

Die Einwilligung wurde abgebrochen, oder die Redirect-URL in bexio zeigt woandershin als auf die,
die Bexy im Einstellungsbildschirm anzeigt.

### Die Erneuerung hat einmal geklappt und dann nicht mehr

bexio **rotiert** Refresh-Tokens: Jede Erneuerung gibt einen neuen zurück und entwertet den alten.
Erneuern zwei Prozesse gleichzeitig, hält einer davon am Ende einen toten Token. Neu verbinden.
Bexy speichert den rotierten Token jedes Mal, das beisst also nur, wenn ausserhalb von Bexy
ebenfalls erneuert wird.

## Belege

### «Die Belegsumme (…) stimmt nicht mit der Bestellsumme (…) überein»

Die Differenz liegt innerhalb der Toleranz, aber die Rundungsposition ist aus, also hat Bexy sie
gemeldet statt geschlossen. Entweder **Abweichung mit einer Rundungsposition ausgleichen**
einschalten oder die Ursache beheben.

### «Das ist zu gross für eine Rundung: Prüfen Sie die Steuerzuordnung …»

Die Lücke hat die **Grösste auszugleichende Differenz** überschritten, Bexy hat also nicht
ausgeglichen. Das ist beabsichtigt. Eine Differenz dieser Grössenordnung ist eines von:

- eine Commerce-Steuerkategorie ohne zugeordneten bexio-Steuersatz, sodass bexio den Belegstandard
  angewendet hat
- das falsche **Wie Preise übermittelt werden** — Bruttopreise als netto gesendet oder umgekehrt
- eine Drittanbieter-Anpassung, die nicht das ist, wofür Sie sie gehalten haben

`php craft bexy/sync/preview <orderId>` gibt die Positionen und die Rechnung aus. Die falsche Zeile
ist meist offensichtlich.

Die Grenze hochzusetzen, damit die Meldung verschwindet, schliesst einen echten Buchungsfehler mit
einer Zeile namens «Rundung». Nicht tun.

### «Für diese Bestellung konnte kein bexio-Kontakt ermittelt werden.»

bexio lehnt einen Beleg ohne Kontakt ab. Entweder ist **Kontakte anlegen** aus und es gab keinen
Treffer, oder die Kontakterstellung ist gescheitert — der Protokolleintrag darüber sagt warum. Die
übliche Ursache ist ein fehlender **bexio-Benutzer** in Bexys Einstellungen; bexio verlangt auf
jedem Kontakt `user_id` *und* `owner_id`.

### «Für … ist kein bexio-Steuersatz zugeordnet»

Eine Warnung, kein Fehler. Die Position wurde mit dem Belegstandard gebucht, was der falsche Satz
sein kann. Zuordnung unter **Steuer- und Kontozuordnung** ergänzen und erneut übermitteln.

### «bexio kennt keine Währung namens …»

Die Währung der Bestellung ist in der bexio-Firma nicht eingerichtet. Der Beleg wurde in der
Firmenstandardwährung gebucht, die Zahlen stimmen also nicht. Währung in bexio anlegen.

### «bexio hat den Beleg angenommen, aber keine ID zurückgegeben.»

Selten. Der Beleg existiert meist trotzdem — in bexio nachsehen und im Bestell-Panel **Von bexio
aktualisieren** verwenden, oder erneut übermitteln und die `api_reference`-Suche den Beleg
übernehmen lassen.

### Dieselbe Bestellung erscheint zweimal in bexio

Sollte nicht passieren. `bexy_documents` ist pro `orderId` eindeutig, und Bexy sucht vor dem
Erstellen nach `api_reference`. Ein Duplikat heisst, der zweite Beleg wurde ausserhalb von Bexy
erstellt. Dort stornieren, dann **Von bexio aktualisieren**.

## Zahlungen

### Eine Rechnung steht in bexio offen, obwohl Commerce bezahlt meldet

- **Zahlungen an bexio verbuchen** ist aus, oder
- die Belegart ist *Auftrag* — auf einen bexio-Auftrag lassen sich keine Zahlungen verbuchen, oder
- die Rechnung wurde nie gebucht, es gibt also nichts, worauf gezahlt werden könnte.

### «Die Zahlung über … konnte in bexio nicht verbucht werden»

Meist ein fehlendes **Bankkonto für Zahlungen**, oder die Rechnung ist in einem Zustand, in dem
bexio keine Zahlung annimmt. Der Antwortinhalt im Protokoll nennt das Feld.

## Abgleich

### Es kommen nie Status zurück

`bexy/reconcile/run` läuft nicht. Es ist ein zeitgesteuerter Befehl; niemand ruft ihn für Sie auf.

Auch **Nur Belege prüfen aus den letzten** kontrollieren — ein älterer Beleg wird übersprungen.

### «Bestellung … konnte nicht auf … gesetzt werden»

Das Commerce-Status-Handle in der **Statuszuordnung** existiert nicht mehr, oder ein
Commerce-Event hat die Änderung abgelehnt. Status neu auswählen.

## Rückerstattungen

### «… dafür braucht es eine Gutschrift in bexio.»

Erwartet. Die bexio-API kann keine Gutschrift ausstellen, eine Rückerstattung, die sich nicht durch
Stornieren der Rechnung erledigen lässt, wird also für einen Menschen markiert. Gutschrift in bexio
ausstellen.

### «… die bexio-Rechnung konnte nicht storniert werden»

bexio storniert keine Rechnung, auf die bezahlt wurde. Stattdessen eine Gutschrift ausstellen.

## Anfragelimits

### «Das Anfragelimit von bexio wurde erreicht und blieb erreicht.»

bexio limitiert pro Firma und Minute. Bexy hält sich an den Header `RateLimit-Reset` und wartet in
einer Web-Anfrage bis zu 3 Sekunden, in Konsole und Queue bis zu 60. Gibt es trotzdem auf, hämmert
noch etwas anderes auf dieselbe Firma ein. Queue-Job wiederholen.

## Es passiert überhaupt nichts

- Läuft Crafts Queue? Die Übermittlung ist immer ein Queue-Job.
- Ist **Bestellungen automatisch senden** ein?
- Steht **Nur wenn die Bestellung folgenden Status erreicht** auf einem Status, den die Bestellung
  nie erreicht?
- `php craft bexy/sync/status` zeigt die Anzahlen. `php craft bexy/sync/pending` übermittelt den
  Rückstau.
