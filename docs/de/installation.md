---
title: Installation
slug: installation
order: 10
summary: Voraussetzungen, Installation und die Verbindung zu Ihrer bexio-Firma.
---

## Voraussetzungen

- Craft CMS 5.3 oder neuer
- Craft Commerce 5.0 oder neuer
- PHP 8.2 oder neuer
- Ein bexio-Abo, das API-Zugriff enthält

## Installation

```sh
composer require justinholtweb/craft-bexy
php craft plugin/install bexy
```

Oder **Bexy** im Craft Plugin Store suchen und von dort installieren.

Bexy hat eine einzige kostenpflichtige Edition, 99 $ einmalig. Es gibt keine kostenlose Stufe und
nichts ist gesperrt — das installierte Plugin ist das ganze Plugin.

## Vor dem Verbinden wird nichts gesendet

Die Installation von Bexy rührt weder bexio noch Ihre Bestellungen an. Solange keine bexio-Firma
verbunden und die Einstellungen gespeichert sind, verhält sich jede abgeschlossene Bestellung
genau wie vorher.

## Mit bexio verbinden

Bexy spricht auf zwei Wegen mit bexio. **Nehmen Sie OAuth, sofern nichts dagegen spricht.**

### OAuth 2.0 (empfohlen)

Die persönlichen Zugriffstokens von bexio verfallen 60 Tage nach der Erstellung, stillschweigend.
OAuth-Refresh-Tokens rotieren und halten die Verbindung dauerhaft am Leben — deshalb ist das der
Standard.

1. Bei [developer.bexio.com](https://developer.bexio.com) anmelden und eine App anlegen.
2. Die **Redirect-URL** aus **Bexy → Einstellungen → Verbindung** in bexio in das Feld
   *Allowed redirect URL* der App kopieren. Sie muss exakt übereinstimmen.
3. Diese Scopes anfordern. Lesezugriff ist im Schreibzugriff enthalten; `contact_show` neben
   `contact_edit` zu verlangen macht nur die Einwilligungsseite länger:

   ```
   openid profile offline_access
   contact_edit kb_invoice_edit kb_order_edit article_edit
   accounting monitoring_show
   ```

4. **Client-ID** und **Client-Secret** in Bexy einfügen und speichern. Das Secret gehört in eine
   Umgebungsvariable und nicht in die Projektkonfiguration — die wird eingecheckt.
5. Auf **Mit bexio verbinden** klicken und die Einwilligung erteilen.

`offline_access` wird am häufigsten vergessen. Ohne diesen Scope stellt bexio keinen Refresh-Token
aus, und die Verbindung stirbt mit dem ersten Access Token.

### Persönlicher Zugriffstoken

Einmal einfügen, funktioniert sofort — und hört 60 Tage später ohne Vorwarnung auf. Für eine
Testfirma vertretbar; nichts, worauf man einen Shop stellt.

**Authentifizierung** auf *Persönlicher Zugriffstoken* umstellen, Token einfügen, speichern.

## Kontrollieren

```sh
php craft bexy/doctor
```

`doctor` meldet die Verbindung, die Restlaufzeit des Tokens, die lesbaren bexio-Listen, Ihre
Steuerzuordnung und alles, was seiner Einschätzung nach scheitern wird, bevor es eine Bestellung
tut. Nach jeder Einstellungsänderung ausführen.

## bexio-Standardwerte ausfüllen

Nach dem Verbinden im Einstellungsbildschirm auf **Listen von bexio aktualisieren** klicken. Die
Dropdowns für Benutzer, Konto, Steuer, Einheit, Bankkonto, Zahlungsart und Sprache füllen sich aus
Ihrer Firma.

Mindestens setzen:

- **bexio-Benutzer** — bexio verlangt einen auf jedem Kontakt und jedem Beleg
- **Standard-Ertragskonto**
- **Standard-Umsatzsteuer** — hier erscheinen nur *aktive Umsatzsteuersätze*, weil bexio jede
  andere Art auf einem Beleg ablehnt

Danach die Commerce-Steuerkategorien unter **Steuer- und Kontozuordnung** zuordnen. Siehe
[Konfiguration](configuration).

## Ihre erste Bestellung

1. Eine Testbestellung in Commerce abschliessen.
2. Sie öffnen. Das Panel **bexio** auf der Bearbeitungsansicht der Bestellung zeigt, was passiert ist.
3. Oder von Hand übermitteln:

   ```sh
   php craft bexy/sync/preview 1234   # genau das, was gesendet würde; sendet nichts
   php craft bexy/sync/order 1234     # tatsächlich senden
   ```

`sync/preview` baut den Belegkörper über denselben Codepfad wie die echte Übermittlung — was es
ausgibt, ist Byte für Byte das, was bexio bekäme.

---

*Bexy ist ein unabhängiges Plugin. Es steht in keiner Verbindung zur bexio AG und wird von ihr
weder unterstützt noch gesponsert. «bexio» und das bexio-Logo sind Marken der bexio AG.*
