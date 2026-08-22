---
title: Utilizzo
slug: usage
order: 30
summary: Il pannello dell’ordine, la schermata Documenti, il registro e tutti i comandi console.
---

## Cosa succede quando un ordine viene completato

1. Commerce contrassegna l’ordine come completato.
2. Bexy mette un job in coda. **L’invio non viene mai eseguito in linea**, così un guasto di bexio
   non può impedire a un cliente di pagare.
3. Il job individua un contatto, costruisce il documento, verifica il totale rispetto a quello di
   Commerce e lo crea in bexio.
4. Se configurato, la fattura viene emessa e inviata per e-mail.
5. Gli addebiti Commerce riusciti vengono registrati come pagamenti.
6. `bexy/reconcile/run` in seguito recupera lo stato da bexio e allinea l’ordine Commerce.

Ogni passaggio viene scritto nel registro di connessione, con entrambi i corpi e i secret oscurati.

## Il pannello dell’ordine

La schermata di modifica dell’ordine di Commerce guadagna un pannello **bexio** che mostra il
documento, il suo numero, se è stato emesso e inviato, il totale dell’ordine rispetto al totale del
documento, l’`api_reference`, i tentativi e quando è stato sincronizzato e riconciliato l’ultima
volta.

Da lì potete **inviare**, **inviare di nuovo**, **aggiornare da bexio**, **visualizzare il PDF**,
**annullare in bexio** e **dimenticare** la registrazione dell’ordine in Bexy. Dimenticare rimuove
solo la riga locale; il documento bexio resta intatto.

## Documenti

**Bexy → Documenti** elenca ogni ordine che Bexy conosce, filtrabile per stato e ricercabile per
numero d’ordine o numero bexio. *Richiede attenzione* è la vista da tenere d’occhio: un invio non
riuscito, una discrepanza nei totali, un rimborso che necessita di una nota di credito.

Aprendo un documento si vede cosa è stato inviato, cosa è tornato, i pagamenti registrati su di
esso e l’ultimo messaggio da bexio.

## Registro

**Bexy → Registro** contiene ogni richiesta HTTP fatta dal plugin, con azione, endpoint, stato e i
corpi di richiesta e risposta. Filtrabile per azione e per livello.

Token, secret e codici di autorizzazione vengono oscurati in scrittura, quindi il registro si può
leggere e incollare in un’e-mail di supporto senza rischi.

## Comandi console

```sh
php craft bexy/doctor              # collegamento, token, elenchi, mappature, problemi probabili
```

### Sincronizzare

```sh
php craft bexy/sync/preview 1234   # stampare esattamente ciò che verrebbe inviato; non invia nulla
php craft bexy/sync/order 1234     # inviare un ordine
php craft bexy/sync/pending        # inviare tutto ciò che non è ancora in bexio
php craft bexy/sync/status         # conteggi per stato
```

`sync/preview` passa dallo stesso builder dell’invio reale, quindi un’anteprima è byte per byte
ciò che riceve bexio. Da usare prima di un primo invio in produzione e per diagnosticare una
discrepanza nei totali.

### Riconciliare

```sh
php craft bexy/reconcile/run
```

Mettetelo in pianificazione — ogni ora è più che sufficiente:

```
0 * * * * cd /percorso/di/craft && php craft bexy/reconcile/run >> /dev/null 2>&1
```

### Elenchi

```sh
php craft bexy/meta/taxes           # solo aliquote IVA sulle vendite attive
php craft bexy/meta/accounts
php craft bexy/meta/users
php craft bexy/meta/currencies
php craft bexy/meta/units
php craft bexy/meta/languages
php craft bexy/meta/payment-types
php craft bexy/meta/bank-accounts
php craft bexy/meta/flush           # svuotare gli elenchi in cache e recuperarli di nuovo
```

## Twig

```twig
{% set doc = craft.bexy.document(order) %}
{% if doc %}
    {{ doc.bexioNumber }} — {{ doc.status }}
{% endif %}
```

`craft.bexy.document()` restituisce `null` per un ordine che Bexy non ha mai visto, e gestisce un
ordine nullo senza lamentarsi.

## Idempotenza

Bexy scrive `api_reference` su ogni documento e lo cerca prima di creare qualsiasi cosa. Inoltre
`bexy_documents` è univoca per `orderId` e `bexy_payments` per `transactionId`.

In pratica: inviare due volte lo stesso ordine riprende il documento bexio esistente invece di
crearne un secondo, e lo stesso addebito Commerce non viene mai registrato come due pagamenti. Se
un documento esisteva in bexio prima che Bexy ne sapesse nulla, il percorso di ripresa lo raccoglie
e lo dichiara.

## Rimborsi

- Un **rimborso totale** senza nulla pagato sulla fattura in bexio annulla la fattura, se avete
  attivato l’opzione.
- Tutto il resto viene **segnalato per una nota di credito**. L’API di bexio non ha un endpoint per
  crearne una, quindi serve una persona in bexio.

Bexy non registra mai un pagamento negativo per mascherare la differenza.
