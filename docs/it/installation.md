---
title: Installazione
slug: installation
order: 10
summary: Requisiti, installazione e collegamento di Bexy alla vostra azienda bexio.
---

## Requisiti

- Craft CMS 5.3 o successivo
- Craft Commerce 5.0 o successivo
- PHP 8.2 o successivo
- Un piano bexio che includa l’accesso API

## Installazione

```sh
composer require justinholtweb/craft-bexy
php craft plugin/install bexy
```

Oppure cercate **Bexy** nel Craft Plugin Store e installatelo da lì.

Bexy ha un’unica edizione a pagamento, 99 $ una volta sola. Non c’è una versione gratuita e nulla è
bloccato: il plugin che installate è il plugin intero.

## Nulla viene inviato prima del collegamento

Installare Bexy non tocca né bexio né i vostri ordini. Finché non avete collegato un’azienda bexio
e salvato le impostazioni, ogni ordine completato si comporta esattamente come prima.

## Collegarsi a bexio

Bexy parla con bexio in due modi. **Usate OAuth, salvo motivi contrari.**

### OAuth 2.0 (consigliato)

I token di accesso personali di bexio scadono 60 giorni dopo la creazione, senza avvisare. I
refresh token OAuth ruotano e mantengono vivo il collegamento a tempo indeterminato: per questo
sono l’impostazione predefinita.

1. Accedete a [developer.bexio.com](https://developer.bexio.com) e create un’app.
2. Copiate l’**URL di reindirizzamento** mostrato in **Bexy → Impostazioni → Connessione** nel
   campo *Allowed redirect URL* dell’app in bexio. Deve corrispondere esattamente.
3. Richiedete questi scope. L’accesso in lettura è implicito in quello in scrittura: chiedere
   `contact_show` accanto a `contact_edit` allunga solo la schermata di consenso:

   ```
   openid profile offline_access
   contact_edit kb_invoice_edit kb_order_edit article_edit
   accounting monitoring_show
   ```

4. Incollate **ID client** e **secret client** in Bexy e salvate. Il secret va in una variabile
   d’ambiente, non nella configurazione di progetto: quella viene versionata.
5. Cliccate su **Collegarsi a bexio** e approvate la schermata di consenso.

`offline_access` è quello che si dimentica. Senza, bexio non emette alcun refresh token e il
collegamento muore con il primo token di accesso.

### Token di accesso personale

Un solo incolla, funziona subito, e smette di funzionare 60 giorni dopo senza preavviso.
Ragionevole per un’azienda di prova; non qualcosa su cui far girare un negozio.

Impostate **Autenticazione** su *Token di accesso personale*, incollate il token, salvate.

## Controllare

```sh
php craft bexy/doctor
```

`doctor` riferisce sul collegamento, sulla vita residua del token, sugli elenchi bexio che riesce a
leggere, sulla vostra mappatura IVA e su tutto ciò che secondo lui fallirà prima che lo faccia un
ordine. Eseguitelo dopo ogni modifica alle impostazioni.

## Compilare i valori predefiniti bexio

Una volta collegati, cliccate su **Aggiornare gli elenchi da bexio** nella schermata delle
impostazioni. I menu a tendina di utente, conto, aliquota, unità, conto bancario, tipo di pagamento
e lingua si popolano dalla vostra azienda.

Come minimo, impostate:

- **Utente bexio** — bexio ne richiede uno su ogni contatto e ogni documento che registra
- **Conto ricavi predefinito**
- **Aliquota IVA sulle vendite predefinita** — qui compaiono solo le *aliquote IVA sulle vendite
  attive*, perché bexio rifiuta qualsiasi altro tipo su un documento

Poi mappate le categorie IVA di Commerce in **Mappatura IVA e conti**. Vedi
[Configurazione](configuration).

## Il vostro primo ordine

1. Completate un ordine di prova in Commerce.
2. Apritelo. Il pannello **bexio** nella schermata di modifica mostra cosa è successo.
3. Oppure inviatene uno a mano:

   ```sh
   php craft bexy/sync/preview 1234   # esattamente ciò che verrebbe inviato; non invia nulla
   php craft bexy/sync/order 1234     # inviare davvero
   ```

`sync/preview` costruisce il corpo del documento attraverso lo stesso percorso di codice
dell’invio reale, quindi ciò che stampa è byte per byte quello che riceverebbe bexio.

---

*Bexy è un plugin indipendente. Non è affiliato a bexio AG, né approvato o sponsorizzato da essa.
«bexio» e il logo bexio sono marchi di bexio AG.*
