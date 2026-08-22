---
title: FAQ
slug: faq
order: 50
summary: Prezzo, ambito e le domande che emergono prima dell’acquisto.
---

## Quanto costa?

99 $, una volta sola. Un’unica edizione, nessun rinnovo, nessuna funzione bloccata. Gli
aggiornamenti per Craft 5 sono inclusi.

## Cosa fa esattamente?

Gli ordini Commerce completati diventano fatture o ordini bexio. Gli addebiti Commerce vengono
registrati come pagamenti. Lo stato di bexio torna in Commerce. I totali vengono verificati prima
che si invii qualsiasi cosa.

## Un guasto di bexio blocca il mio checkout?

No. L’invio è sempre un job in coda e tutto ciò che sta sul percorso di checkout fallisce in modo
aperto. Un cliente può pagare mentre bexio è offline; l’ordine si sincronizza quando torna.

## Fatture o ordini?

Entrambi. Le fatture possono essere emesse, inviate per e-mail e pagate: è la scelta abituale. Gli
ordini bexio sono la fase precedente alla fattura e non accettano pagamenti.

## Perché OAuth invece di un token?

I token di accesso personali di bexio scadono 60 giorni dopo la creazione, senza avvisare. Un
negozio collegato con uno di essi smette di sincronizzare due mesi dopo e nulla lo segnala. I
refresh token OAuth ruotano e continuano a funzionare. Bexy supporta entrambi; per questo OAuth è
il valore predefinito.

## Può inviare la fattura al cliente?

Sì, tramite la rete di consegna di bexio anziché quella di Craft. La fattura deve prima essere
emessa. Il testo dell’e-mail deve contenere `[Network Link]`, altrimenti il cliente riceve un
messaggio senza fattura — Bexy si rifiuta di salvarne uno che ne è privo.

## Creerà documenti doppi?

No. Bexy scrive `api_reference` su ogni documento e lo cerca prima di creare qualsiasi cosa, e la
tabella locale è univoca per ordine. Inviate due volte lo stesso ordine: il secondo invio riprende
il documento già esistente.

## E i rimborsi e le note di credito?

Un rimborso totale annulla la fattura dove bexio lo consente, cioè solo se non è ancora stato
pagato nulla. Tutto il resto viene segnalato per una nota di credito.

L’API di bexio non ha un endpoint per creare note di credito, solo una lettura del PDF. Bexy non
simula un pagamento negativo per aggirarlo, perché i vostri libri direbbero qualcosa che non è mai
accaduto.

## Tocca le mie transazioni Commerce?

No. La riconciliazione sposta lo **stato dell’ordine** Commerce e nient’altro. Bexy non inventa mai
una transazione Commerce per far sembrare pagato un ordine.

## Come funziona l’IVA?

Ogni categoria IVA di Commerce è mappata a un’aliquota bexio e a un conto ricavi. `mwst_type` viene
letto dall’ordine per impostazione predefinita — un’aliquota Commerce contrassegnata *inclusa nel
prezzo* rende lordi i prezzi — e può essere forzato se preferite.

Vengono offerte solo le **aliquote IVA sulle vendite attive** di bexio, perché bexio rifiuta
qualsiasi altro tipo su un documento.

## E se i totali non coincidono?

Bexy calcola il totale del documento prima di inviare e lo confronta con quello di Commerce. Uno
scarto di pochi centesimi riceve una posizione di arrotondamento esente. Uno maggiore viene
rifiutato e spiegato, perché una differenza di quell’ordine è una mappatura IVA errata e colmarla
nasconderebbe il problema.

## Funziona con più negozi o valute Commerce?

Le valute sì, purché la valuta esista nella vostra azienda bexio; in caso contrario Bexy avvisa e
bexio registra nella valuta predefinita dell’azienda. Bexy collega un’installazione Craft a
un’azienda bexio.

## Posso vedere cosa verrà inviato prima dell’invio?

```sh
php craft bexy/sync/preview 1234
```

Usa lo stesso builder dell’invio reale, quindi l’anteprima è byte per byte ciò che riceve bexio.

## In quali lingue è il pannello di controllo?

Inglese, tedesco, francese e italiano.

## Quali versioni di Craft e Commerce?

Craft CMS 5.3+, Craft Commerce 5.0+, PHP 8.2+.

## Esiste una versione di prova?

I plugin del Craft Plugin Store si possono provare quanto volete in un ambiente di sviluppo. Si
paga solo al passaggio in produzione.

## Come ottengo assistenza?

Scrivete a [justin@justinholt.com](mailto:justin@justinholt.com). Allegate le voci pertinenti di
**Bexy → Registro** — i secret sono già oscurati — e l’output di `php craft bexy/doctor`.
