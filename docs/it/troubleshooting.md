---
title: Risoluzione dei problemi
slug: troubleshooting
order: 40
summary: Cosa significa davvero ogni errore, e come si risolve.
---

Cominciate da qui:

```sh
php craft bexy/doctor
```

Poi **Bexy → Registro**, filtrato su *Errore*. Ci sono entrambi i corpi, con i secret oscurati.

## Connessione

### «bexio ha rifiutato le credenziali. Ricollegarsi oppure emettere un nuovo token di accesso.»

Un 401. Con un token di accesso personale significa quasi sempre che i 60 giorni sono scaduti:
bexio li fa scadere in silenzio. Emettetene uno nuovo, oppure passate a OAuth.

Con OAuth significa che il rinnovo è fallito. Ricollegatevi.

### Il collegamento OAuth muore dopo un’ora

Mancava `offline_access` negli scope, quindi bexio non ha mai emesso un refresh token.
Aggiungetelo all’app in bexio e ricollegatevi.

### «Lo stato di autorizzazione non corrispondeva. Avviare di nuovo il collegamento.»

Il callback non è tornato alla sessione che lo aveva avviato. Di solito una scheda vecchia, un
browser diverso, o un URL di reindirizzamento che non corrisponde esattamente a quello registrato
in bexio.

### «bexio non ha restituito alcun codice di autorizzazione.»

La schermata di consenso è stata annullata, oppure l’URL di reindirizzamento in bexio punta altrove
rispetto a quello che Bexy mostra nella schermata delle impostazioni.

### Il rinnovo ha funzionato una volta e poi si è fermato

bexio **ruota** i refresh token: ogni rinnovo ne restituisce uno nuovo e invalida il precedente. Se
due processi rinnovano contemporaneamente, uno resta con un token morto. Ricollegatevi. Bexy salva
il token ruotato ogni volta, quindi il problema si presenta solo se qualcosa al di fuori di Bexy
rinnova a sua volta.

## Documenti

### «Il totale del documento (…) non corrisponde al totale dell’ordine (…)»

La differenza rientra nella tolleranza ma la posizione di arrotondamento è disattivata, quindi Bexy
l’ha segnalata invece di colmarla. Attivate **Colmare una discrepanza con una posizione di
arrotondamento**, oppure risolvete la causa.

### «È troppo grande per essere un arrotondamento: verificare la mappatura IVA…»

Lo scarto ha superato la **Differenza massima da colmare**, quindi Bexy non ha corretto. È il
comportamento voluto. Una differenza di quell’ordine è una di queste:

- una categoria IVA di Commerce senza aliquota bexio mappata, così bexio ha applicato quella
  predefinita del documento
- un **Come vengono inviati i prezzi** sbagliato — prezzi lordi inviati come netti, o il contrario
- una rettifica di terze parti che non è quello che pensavate

`php craft bexy/sync/preview <orderId>` stampa le posizioni e il calcolo. La riga sbagliata è di
solito evidente.

Alzare il limite per far sparire il messaggio significa colmare un vero errore contabile con una
riga chiamata «Arrotondamento». Non fatelo.

### «Per questo ordine non è stato individuato alcun contatto bexio.»

bexio rifiuta un documento privo di contatto. O **Creare i contatti** è disattivato e non è stata
trovata alcuna corrispondenza, oppure la creazione è fallita: la voce di registro immediatamente
sopra dice perché. La causa abituale è nessun **utente bexio** selezionato nelle impostazioni di
Bexy; bexio richiede `user_id` *e* `owner_id` su ogni contatto.

### «Per … non è mappata alcuna aliquota IVA bexio»

Un avviso, non un errore. La posizione è stata registrata con l’aliquota predefinita del documento,
che potrebbe essere sbagliata. Aggiungete la mappatura in **Mappatura IVA e conti** e inviate di
nuovo.

### «bexio non conosce alcuna valuta chiamata …»

La valuta dell’ordine non è configurata nell’azienda bexio. Il documento è stato registrato nella
valuta predefinita dell’azienda, quindi i numeri sono sbagliati. Aggiungete la valuta in bexio.

### «bexio ha accettato il documento ma non ha restituito alcun ID.»

Raro. Di solito il documento esiste comunque: controllate in bexio e usate **Aggiornare da bexio**
sul pannello dell’ordine, oppure inviate di nuovo e lasciate che la ricerca su `api_reference` lo
riprenda.

### Lo stesso ordine compare due volte in bexio

Non dovrebbe. `bexy_documents` è univoca per `orderId` e Bexy cerca `api_reference` prima di creare.
Un duplicato significa che il secondo è stato creato fuori da Bexy. Annullatelo lì, poi
**Aggiornare da bexio**.

## Pagamenti

### Una fattura resta aperta in bexio anche se Commerce dice pagato

- **Registrare i pagamenti in bexio** è disattivato, oppure
- il tipo di documento è *Ordine* — su un ordine bexio non si possono registrare pagamenti, oppure
- la fattura non è mai stata emessa, quindi non c’è nulla su cui pagare.

### «Il pagamento di … non è stato registrato in bexio»

Di solito manca un **conto bancario dei pagamenti**, oppure la fattura è in uno stato in cui bexio
non accetta un pagamento. Il corpo della risposta nel registro indica il campo.

## Riconciliazione

### Gli stati non tornano mai indietro

`bexy/reconcile/run` non è in esecuzione. È un comando pianificato; nessuno lo chiama per voi.

Controllate anche **Verificare solo i documenti degli ultimi**: un documento più vecchio di quella
finestra viene saltato.

### «Impossibile portare l’ordine … allo stato …»

L’handle dello stato Commerce in **Mappatura degli stati** non esiste più, oppure un evento
Commerce ha rifiutato il cambiamento. Riselezionate lo stato.

## Rimborsi

### «… occorre creare una nota di credito in bexio.»

Previsto. L’API di bexio non può emettere una nota di credito, quindi un rimborso che non si può
gestire annullando la fattura viene segnalato a una persona. Emettete la nota di credito in bexio.

### «… non è stato possibile annullare la fattura bexio»

bexio non annulla una fattura su cui è stato registrato un pagamento. Emettete invece una nota di
credito.

## Limiti di frequenza

### «Il limite di frequenza di bexio è stato raggiunto ed è rimasto tale.»

bexio limita per azienda al minuto. Bexy rispetta l’header `RateLimit-Reset`, attendendo fino a 3
secondi in una richiesta web e fino a 60 in contesto console e coda. Se rinuncia comunque, c’è
qualcos’altro che sollecita la stessa azienda. Riprovate il job in coda.

## Non succede proprio nulla

- La coda di Craft è in esecuzione? L’invio è sempre un job in coda.
- **Inviare gli ordini automaticamente** è attivo?
- **Solo quando l’ordine raggiunge** punta a uno stato che l’ordine non raggiunge mai?
- `php craft bexy/sync/status` mostra i conteggi. `php craft bexy/sync/pending` invia l’arretrato.
