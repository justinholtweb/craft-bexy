---
title: Konfiguration
slug: configuration
order: 20
summary: Jede Einstellung, ihre Wirkung, und die, auf die es für eine korrekte MWST-Abrechnung ankommt.
---

Alles davon liegt unter **Bexy → Einstellungen**. Nichts hier ist Pflichtfeld, eine frische
Installation lässt sich also immer speichern.

## Was erstellt wird

| Einstellung | Hinweise |
|---|---|
| **Belegart** | *Rechnung* oder *Auftrag*. Rechnungen können gebucht, versendet und bezahlt werden. Aufträge sind die Stufe davor; Zahlungen lassen sich darauf nicht verbuchen. |
| **Bestellungen automatisch senden** | Stellt die Übermittlung in die Warteschlange, sobald eine Bestellung abgeschlossen ist. Sie läuft immer über die Warteschlange, damit bexio einen Checkout nie aufhalten kann. |
| **Nur wenn die Bestellung folgenden Status erreicht** | Alle Status leer lassen, um sofort nach Abschluss zu übermitteln. Einen oder mehrere ankreuzen, um stattdessen zu warten, bis jemand die Bestellung dorthin verschiebt. |
| **Rechnung buchen** | Bucht sie und vergibt eine Nummer. Bis dahin ist sie in bexio ein Entwurf und kann weder versendet noch bezahlt werden. |
| **Von bexio per E-Mail versenden lassen** | Versendet über das Zustellnetz von bexio, nicht über das von Craft. Setzt *Rechnung buchen* voraus. |

### E-Mail-Text

bexio setzt den Beleg dort ein, wo `[Network Link]` steht. **Ohne diesen Platzhalter erhält die
Kundschaft eine E-Mail ohne Rechnung**, deshalb weigert sich Bexy, einen E-Mail-Text ohne ihn zu
speichern. `{number}`, `{name}` und `{email}` werden ebenfalls ersetzt.

### Belegtitel

Platzhalter: `{number}`, `{reference}`, `{date}`, `{total}`, `{currency}`, `{name}`, `{email}`.

## bexio-Standardwerte

Werden über **Listen von bexio aktualisieren** gefüllt.

| Einstellung | Hinweise |
|---|---|
| **bexio-Benutzer** | Von bexio auf jedem Kontakt und Beleg verlangt. Standard ist, wer die Verbindung autorisiert hat. |
| **Standard-Ertragskonto** | Wohin eine Position gebucht wird, wenn ihre Steuerkategorie kein eigenes Konto hat. |
| **Standard-Umsatzsteuer** | Angeboten werden nur aktive Umsatzsteuersätze — bexio lehnt jede andere Art auf einem Beleg ab. |
| **Einheit**, **Bankkonto**, **Zahlungsart**, **Belegsprache** | Belegstandards. |
| **Briefpapier-ID** | Die `logopaper_id` von bexio. Leer bedeutet Firmenstandard. |
| **Zahlungsfrist** | Tage bis zur Fälligkeit. Standard 30. |

## Steuer

Dieser Abschnitt muss stimmen. Alles andere ist im Vergleich Kosmetik.

### Wie Preise übermittelt werden

`mwst_type` auf dem bexio-Beleg: 0 Preise inklusive Steuer, 1 Steuer wird aufgeschlagen, 2
steuerbefreit.

- **Aus Commerce** (Standard) liest es aus der Bestellung. Ein Steuersatz mit *im Preis enthalten*
  macht jeden Preis zum Bruttopreis.
- Die drei expliziten Optionen überschreiben das für jeden Beleg.

### Steuer- und Kontozuordnung

Eine Zeile pro Commerce-Steuerkategorie mit dem bexio-Steuersatz und dem Ertragskonto, auf die ihre
Positionen gebucht werden. Was fehlt, fällt auf die Standardwerte oben zurück.

Eine fehlende Steuerzuordnung ist kein Fehler — bexio bucht die Position mit dem Belegstandard, was
der falsche Satz sein kann, und Bexy vermerkt eine Warnung am Beleg. Prüfen Sie das Protokoll,
bevor Sie den Zahlen eines Quartals vertrauen.

Der Versand hat eigene Bezeichnung, eigenen Steuersatz und eigenes Konto, weil er selten dieselbe
Kategorie hat wie die Ware.

## Summen

bexio rechnet die Belegsumme selbst aus. Bexy rechnet dieselbe Zahl vor dem Senden aus und
vergleicht sie mit der von Commerce, damit eine Abweichung hier auffällt und nicht erst bei der
MWST-Abrechnung.

| Einstellung | Standard | Hinweise |
|---|---|---|
| **Abweichung mit einer Rundungsposition ausgleichen** | ein | Fügt eine steuerfreie Zeile für die Differenz ein. Ausgeschaltet wird die Differenz nur gemeldet. |
| **Toleranz** | 0.01 | Wie weit die beiden Summen auseinanderliegen dürfen. Inklusiv: Eine Differenz von genau der Toleranz ist keine Abweichung. |
| **Grösste auszugleichende Differenz** | 1.00 | Darüber hinaus gleicht Bexy nicht aus, sondern erklärt. 0 hebt die Grenze auf. |
| **Bezeichnung Rundung** | Rundung | Wie die Zeile auf dem Beleg heisst. |

Zwei Dinge zur Rundungsposition:

- **Sie ist absichtlich steuerfrei.** Eine besteuerte Rundungszeile verschiebt die Bruttosumme um
  die Differenz *plus Steuer* und verfehlt erneut.
- **Die Obergrenze ist der springende Punkt.** Ohne sie wird eine ganze falsch zugeordnete
  7.7%-Steuer stillschweigend von einer Zeile namens «Rundung» geschlossen, und der Beleg stimmt,
  während er falsch ist. Eine Differenz dieser Grössenordnung ist eine falsche Steuerzuordnung,
  keine Rundung.

## Kontakte

| Einstellung | Standard | Hinweise |
|---|---|---|
| **Kontakte anlegen** | ein | Bexy gleicht zuerst über die E-Mail-Adresse ab — die eigene Zuordnung, dann die Kontaktliste von bexio — und legt nur an, wenn beide nichts haben. |
| **Bestehende Kontakte aktualisieren** | aus | Überträgt die Adresse der Bestellung auf den bexio-Kontakt. Standardmässig aus: Der Kontaktdatensatz gehört der Buchhaltung, und eine im Shop eingetippte Lieferadresse soll ihn nicht überschreiben. |
| **Kontaktgruppen-IDs** | — | bexio-Kontaktgruppen-IDs für neu angelegte Kontakte, durch Komma getrennt. |

Bexy sendet `street_name` und `house_number` getrennt. bexio hat das kombinierte Feld `address` für
Schreibzugriffe am 9. Dezember 2025 abgekündigt.

## Artikel

| Einstellung | Standard | Hinweise |
|---|---|---|
| **Positionen über die SKU bexio-Artikeln zuordnen** | aus | Macht aus einer Zeile eine echte bexio-Artikelposition — nur so funktioniert die artikelbezogene Auswertung von bexio. Eine SKU ohne Treffer fällt auf eine freie Position zurück. |
| **Fehlende Artikel anlegen** | aus | Fügt die SKU beim ersten Verkauf der Artikelliste von bexio hinzu. |

## Zahlungen

| Einstellung | Standard | Hinweise |
|---|---|---|
| **Zahlungen an bexio verbuchen** | ein | Jede erfolgreiche Commerce-Belastung wird zu einer Zahlung auf die Rechnung, damit bexio bezahlte Bestellungen nicht offen stehen lässt. Nur Rechnungen. |
| **Bankkonto für Zahlungen** | — | Fällt auf das Bankkonto oben zurück. |
| **Rechnung bei vollständiger Rückerstattung stornieren** | aus | Nur wenn in bexio noch nichts darauf bezahlt wurde. Alles andere wird für eine Gutschrift markiert. |

bexio hat keinen Endpunkt zum Erstellen von Gutschriften, nur einen PDF-Abruf. Bexy täuscht dafür
nie eine Negativzahlung vor; eine Rückerstattung, die es nicht stornieren kann, wird für einen
Menschen markiert.

## Abgleich

| Einstellung | Standard | Hinweise |
|---|---|---|
| **Status von bexio zurückholen** | ein | Braucht `craft bexy/reconcile/run` zeitgesteuert. Ohne das erreicht eine in bexio als bezahlt markierte Rechnung Commerce nie. |
| **Statuszuordnung** | — | bexio-Status auf Commerce-Bestellstatus. |
| **Nur Belege prüfen aus den letzten** | 120 | Tage. Bezahlte und stornierte Belege fallen ohnehin heraus. 0 prüft alles. |

Bexy ändert den Commerce-Bestellstatus und sonst nichts. **Es erfindet nie eine
Commerce-Transaktion, damit eine Bestellung bezahlt aussieht**, denn das würde eine Zahlung in Ihre
Commerce-Auswertungen schreiben, die es nie gab.

## Protokoll

| Einstellung | Standard | Hinweise |
|---|---|---|
| **Anfragen protokollieren** | ein | |
| **Anfrage- und Antwortinhalte aufbewahren** | ein | Tokens und Secrets werden in jedem Fall unkenntlich gemacht. |
| **Protokolleinträge aufbewahren für** | 30 | Tage. 0 behält alles. Wird bei jedem Sync- oder Abgleich-Befehl bereinigt. |
