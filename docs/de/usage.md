---
title: Verwendung
slug: usage
order: 30
summary: Das Bestell-Panel, der Belegbildschirm, das Protokoll und jeder Konsolenbefehl.
---

## Was passiert, wenn eine Bestellung abgeschlossen wird

1. Commerce markiert die Bestellung als abgeschlossen.
2. Bexy stellt einen Job in die Warteschlange. **Die Übermittlung läuft nie inline**, damit ein
   bexio-Ausfall die Kundschaft nicht am Bezahlen hindert.
3. Der Job ermittelt einen Kontakt, baut den Beleg, prüft die Summe gegen die von Commerce und
   erstellt ihn in bexio.
4. Sofern konfiguriert, wird die Rechnung gebucht und versendet.
5. Erfolgreiche Commerce-Belastungen werden als Zahlungen darauf verbucht.
6. `bexy/reconcile/run` holt später den Status von bexio zurück und setzt die Commerce-Bestellung
   entsprechend.

Jeder Schritt wird ins Verbindungsprotokoll geschrieben, mit beiden Inhalten, Secrets unkenntlich
gemacht.

## Das Bestell-Panel

Die Bearbeitungsansicht der Commerce-Bestellung erhält ein Panel **bexio**, das den Beleg zeigt,
seine Nummer, ob er gebucht und versendet wurde, die Bestellsumme gegen die Belegsumme, die
`api_reference`, die Versuche und wann zuletzt übermittelt und abgeglichen wurde.

Von dort lässt sich **übermitteln**, **erneut übermitteln**, **von bexio aktualisieren**, das
**PDF ansehen**, **in bexio stornieren** und der Eintrag von Bexy **verwerfen**. Verwerfen entfernt
nur die lokale Zeile; der bexio-Beleg bleibt unangetastet.

## Belege

**Bexy → Belege** listet jede Bestellung, die Bexy kennt, filterbar nach Status und durchsuchbar
nach Bestell- oder bexio-Nummer. *Benötigt Aufmerksamkeit* ist die Ansicht, die man im Auge behält:
eine fehlgeschlagene Übermittlung, eine Summenabweichung, eine Rückerstattung, die eine Gutschrift
braucht.

Ein geöffneter Beleg zeigt, was gesendet wurde, was zurückkam, die darauf verbuchten Zahlungen und
die letzte Meldung von bexio.

## Protokoll

**Bexy → Protokoll** ist jede HTTP-Anfrage, die das Plugin gemacht hat, mit Aktion, Endpunkt,
Status sowie Anfrage- und Antwortinhalt. Filterbar nach Aktion und Stufe.

Tokens, Secrets und Authorization Codes werden beim Schreiben unkenntlich gemacht, das Protokoll
ist also gefahrlos lesbar und gefahrlos in eine Support-Mail kopierbar.

## Konsolenbefehle

```sh
php craft bexy/doctor              # Verbindung, Tokens, Listen, Zuordnung, wahrscheinliche Probleme
```

### Übermitteln

```sh
php craft bexy/sync/preview 1234   # genau das ausgeben, was gesendet würde; sendet nichts
php craft bexy/sync/order 1234     # eine Bestellung übermitteln
php craft bexy/sync/pending        # alles übermitteln, was noch nicht in bexio ist
php craft bexy/sync/status         # Anzahl je Zustand
```

`sync/preview` läuft über denselben Builder wie die echte Übermittlung, eine Vorschau ist also
Byte für Byte das, was bexio erhält. Vor der ersten Live-Übermittlung verwenden, und um eine
Summenabweichung zu diagnostizieren.

### Abgleichen

```sh
php craft bexy/reconcile/run
```

Zeitgesteuert ausführen — stündlich reicht:

```
0 * * * * cd /pfad/zu/craft && php craft bexy/reconcile/run >> /dev/null 2>&1
```

### Listen

```sh
php craft bexy/meta/taxes           # nur aktive Umsatzsteuersätze
php craft bexy/meta/accounts
php craft bexy/meta/users
php craft bexy/meta/currencies
php craft bexy/meta/units
php craft bexy/meta/languages
php craft bexy/meta/payment-types
php craft bexy/meta/bank-accounts
php craft bexy/meta/flush           # zwischengespeicherte Listen verwerfen und neu holen
```

## Twig

```twig
{% set doc = craft.bexy.document(order) %}
{% if doc %}
    {{ doc.bexioNumber }} — {{ doc.status }}
{% endif %}
```

`craft.bexy.document()` gibt `null` zurück für eine Bestellung, die Bexy nie gesehen hat, und
kommt mit einer null-Bestellung klar.

## Idempotenz

Bexy schreibt `api_reference` auf jeden Beleg und sucht danach, bevor es etwas erstellt. Zusätzlich
ist `bexy_documents` pro `orderId` eindeutig und `bexy_payments` pro `transactionId`.

Praktisch heisst das: Dieselbe Bestellung zweimal zu übermitteln übernimmt den vorhandenen
bexio-Beleg, statt einen zweiten zu erstellen, und dieselbe Commerce-Belastung wird nie als zwei
Zahlungen verbucht. War ein Beleg in bexio schon da, bevor Bexy davon wusste, greift der
Übernahmepfad und sagt es.

## Rückerstattungen

- Eine **vollständige Rückerstattung**, auf die in bexio noch nichts bezahlt wurde, storniert die
  Rechnung, sofern das eingeschaltet ist.
- Alles andere wird **für eine Gutschrift markiert**. Die bexio-API hat keinen Endpunkt zum
  Erstellen von Gutschriften, das braucht also einen Menschen in bexio.

Bexy verbucht nie eine Negativzahlung, um die Lücke zu überdecken.
