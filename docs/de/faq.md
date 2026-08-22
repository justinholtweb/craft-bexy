---
title: FAQ
slug: faq
order: 50
summary: Preis, Umfang und die Fragen, die vor dem Kauf aufkommen.
---

## Was kostet es?

99 $, einmalig. Eine Edition, keine Verlängerung, keine gesperrten Funktionen. Updates für Craft 5
sind enthalten.

## Was macht es genau?

Abgeschlossene Commerce-Bestellungen werden zu bexio-Rechnungen oder bexio-Aufträgen. Commerce-
Belastungen werden als Zahlungen darauf verbucht. Der Status von bexio kommt zurück nach Commerce.
Die Summen werden geprüft, bevor irgendetwas gesendet wird.

## Legt ein bexio-Ausfall meinen Checkout lahm?

Nein. Die Übermittlung ist immer ein Queue-Job, und alles auf dem Checkout-Pfad scheitert offen.
Kundschaft kann bezahlen, während bexio down ist; die Bestellung wird übermittelt, sobald es wieder
da ist.

## Rechnungen oder Aufträge?

Beides. Rechnungen können gebucht, per E-Mail versendet und bezahlt werden — das ist die übliche
Wahl. bexio-Aufträge sind die Stufe vor der Rechnung, und Zahlungen lassen sich darauf nicht
verbuchen.

## Warum OAuth statt eines Tokens?

Die persönlichen Zugriffstokens von bexio verfallen 60 Tage nach der Erstellung, stillschweigend.
Ein damit verbundener Shop hört zwei Monate später auf zu synchronisieren, und nichts weist darauf
hin. OAuth-Refresh-Tokens rotieren und funktionieren weiter. Bexy unterstützt beides; deshalb ist
OAuth der Standard.

## Kann es die Rechnung an die Kundschaft senden?

Ja, über das Zustellnetz von bexio statt über das von Craft. Die Rechnung muss vorher gebucht sein.
Ihr E-Mail-Text muss `[Network Link]` enthalten, sonst bekommt die Kundschaft eine Mail ohne
Rechnung — Bexy weigert sich, einen Text ohne diesen Platzhalter zu speichern.

## Erzeugt es doppelte Belege?

Nein. Bexy schreibt `api_reference` auf jeden Beleg und sucht danach, bevor es etwas erstellt, und
die lokale Tabelle ist pro Bestellung eindeutig. Dieselbe Bestellung zweimal senden: Die zweite
Übermittlung übernimmt den bereits vorhandenen Beleg.

## Was ist mit Rückerstattungen und Gutschriften?

Eine vollständige Rückerstattung storniert die Rechnung, wo bexio es zulässt — also nur, wenn noch
nichts darauf bezahlt wurde. Alles andere wird für eine Gutschrift markiert.

Die bexio-API hat keinen Endpunkt zum Erstellen von Gutschriften, nur einen PDF-Abruf. Bexy täuscht
dafür keine Negativzahlung vor, denn dann würden Ihre Bücher etwas behaupten, das nie passiert ist.

## Fasst es meine Commerce-Transaktionen an?

Nein. Der Abgleich verschiebt den Commerce-**Bestellstatus** und sonst nichts. Bexy erfindet nie
eine Commerce-Transaktion, damit eine Bestellung bezahlt aussieht.

## Wie funktioniert die MWST?

Jede Commerce-Steuerkategorie wird einem bexio-Steuersatz und einem Ertragskonto zugeordnet.
`mwst_type` wird standardmässig aus der Bestellung gelesen — ein Commerce-Steuersatz mit «im Preis
enthalten» macht die Preise brutto — und kann bei Bedarf erzwungen werden.

Angeboten werden nur die **aktiven Umsatzsteuersätze** von bexio, weil bexio jede andere Art auf
einem Beleg ablehnt.

## Was, wenn die Summen nicht übereinstimmen?

Bexy rechnet die Belegsumme vor dem Senden aus und vergleicht sie mit der von Commerce. Eine
Differenz im Rappenbereich bekommt eine steuerfreie Rundungsposition. Eine grössere wird abgelehnt
und erklärt, denn eine Differenz dieser Grössenordnung ist eine falsche Steuerzuordnung, und sie zu
schliessen würde das Problem verdecken.

## Funktioniert es mit mehreren Commerce-Stores oder Währungen?

Währungen ja, sofern die Währung in Ihrer bexio-Firma existiert; wenn nicht, warnt Bexy und bexio
bucht in der Firmenstandardwährung. Bexy verbindet eine Craft-Installation mit einer bexio-Firma.

## Kann ich vorher sehen, was gesendet wird?

```sh
php craft bexy/sync/preview 1234
```

Es nutzt denselben Builder wie die echte Übermittlung, die Vorschau ist also Byte für Byte das, was
bexio erhält.

## In welchen Sprachen gibt es das Control Panel?

Englisch, Deutsch, Französisch und Italienisch.

## Welche Craft- und Commerce-Versionen?

Craft CMS 5.3+, Craft Commerce 5.0+, PHP 8.2+.

## Gibt es eine Testversion?

Plugins aus dem Craft Plugin Store lassen sich in einer Entwicklungsumgebung beliebig lange testen.
Bezahlt wird erst beim Gang in die Produktion.

## Wie bekomme ich Hilfe?

E-Mail an [justin@justinholt.com](mailto:justin@justinholt.com). Bitte die passenden Einträge aus
**Bexy → Protokoll** mitschicken — Secrets sind bereits unkenntlich gemacht — sowie die Ausgabe von
`php craft bexy/doctor`.
