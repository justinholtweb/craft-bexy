---
title: Configurazione
slug: configuration
order: 20
summary: Ogni impostazione, cosa fa, e quelle che contano per un rendiconto IVA corretto.
---

Tutto questo si trova in **Bexy → Impostazioni**. Nulla qui è obbligatorio, quindi un’installazione
nuova si salva sempre.

## Cosa viene creato

| Impostazione | Note |
|---|---|
| **Tipo di documento** | *Fattura* o *Ordine*. Le fatture possono essere emesse, inviate e pagate. Gli ordini sono la fase precedente e non accettano pagamenti. |
| **Inviare gli ordini automaticamente** | Mette un invio in coda quando un ordine viene completato. L’invio passa sempre dalla coda, così bexio non può mai bloccare un checkout. |
| **Solo quando l’ordine raggiunge** | Lasciate tutte le caselle deselezionate per inviare appena l’ordine è completato. Selezionatene una o più per attendere che qualcuno sposti l’ordine in quello stato. |
| **Emettere la fattura** | La registra e le assegna un numero. Fino ad allora è una bozza in bexio e non può essere inviata né pagata. |
| **Far inviare l’e-mail da bexio** | L’invio avviene tramite la rete di consegna di bexio, non quella di Craft. Richiede *Emettere la fattura*. |

### Testo dell’e-mail

bexio inserisce il documento dove compare `[Network Link]`. **Senza quel segnaposto il cliente
riceve un’e-mail senza fattura**, perciò Bexy si rifiuta di salvare un testo che lo omette. Anche
`{number}`, `{name}` e `{email}` vengono sostituiti.

### Titolo del documento

Segnaposto: `{number}`, `{reference}`, `{date}`, `{total}`, `{currency}`, `{name}`, `{email}`.

## Valori predefiniti bexio

Compilati da **Aggiornare gli elenchi da bexio**.

| Impostazione | Note |
|---|---|
| **Utente bexio** | Richiesto da bexio su ogni contatto e documento. Per impostazione predefinita, chi ha autorizzato il collegamento. |
| **Conto ricavi predefinito** | Dove viene registrata una posizione quando la sua categoria IVA non ha un conto proprio. |
| **Aliquota IVA sulle vendite predefinita** | Vengono offerte solo le aliquote attive — bexio rifiuta qualsiasi altro tipo su un documento. |
| **Unità**, **Conto bancario**, **Tipo di pagamento**, **Lingua del documento** | Valori predefiniti del documento. |
| **ID carta intestata** | Il `logopaper_id` di bexio. Vuoto significa valore predefinito dell’azienda. |
| **Termine di pagamento** | Giorni entro cui la fattura scade. Predefinito 30. |

## IVA

Questa è la sezione da non sbagliare. Tutto il resto è cosmetica al confronto.

### Come vengono inviati i prezzi

`mwst_type` sul documento bexio: 0 prezzi IVA inclusa, 1 IVA aggiunta, 2 esente.

- **Da Commerce** (predefinito) lo ricava dall’ordine. Un’aliquota contrassegnata *inclusa nel
  prezzo* rende lordo ogni prezzo.
- Le tre opzioni esplicite prevalgono per ogni documento.

### Mappatura IVA e conti

Una riga per categoria IVA di Commerce, con l’aliquota bexio e il conto ricavi su cui vengono
registrate le sue righe. Ciò che manca ricade sui valori predefiniti qui sopra.

Una mappatura mancante non è un errore: bexio registra la posizione con l’aliquota predefinita del
documento, che potrebbe essere sbagliata, e Bexy annota un avviso sul documento. Controllate il
registro prima di fidarvi dei numeri di un trimestre.

La spedizione ha etichetta, aliquota e conto propri, perché raramente rientra nella stessa
categoria della merce.

## Totali

bexio calcola da sé il totale del documento. Bexy calcola la stessa cifra prima di inviare e la
confronta con quella di Commerce, così una discrepanza emerge qui e non al momento del rendiconto
IVA.

| Impostazione | Predefinito | Note |
|---|---|---|
| **Colmare una discrepanza con una posizione di arrotondamento** | attivo | Aggiunge una riga esente per la differenza. Se disattivato, la differenza viene solo segnalata. |
| **Tolleranza** | 0.01 | Di quanto i due totali possono divergere. Inclusiva: uno scarto pari esattamente alla tolleranza non è una discrepanza. |
| **Differenza massima da colmare** | 1.00 | Oltre, Bexy non corregge e spiega. 0 elimina il limite. |
| **Etichetta di arrotondamento** | Arrotondamento | Come si chiama la riga sul documento. |

Due cose da sapere sulla posizione di arrotondamento:

- **È esente di proposito.** Una riga di arrotondamento tassata sposta il totale lordo della
  differenza *più l’IVA*, e sbaglia di nuovo.
- **Il limite superiore è il punto.** Senza, un’intera IVA al 7,7% mappata male viene colmata in
  silenzio da una riga chiamata «Arrotondamento» e il documento quadra pur essendo sbagliato. Una
  differenza di quell’ordine è una mappatura IVA errata, non un arrotondamento.

## Contatti

| Impostazione | Predefinito | Note |
|---|---|---|
| **Creare i contatti** | attivo | Bexy abbina prima tramite e-mail — la propria tabella, poi l’elenco contatti di bexio — e crea un contatto solo se nessuna delle due lo contiene. |
| **Aggiornare i contatti esistenti** | disattivo | Riporta l’indirizzo dell’ordine sul contatto bexio. Disattivato per impostazione predefinita: la scheda contatto appartiene al contabile e un indirizzo di consegna digitato nel negozio non deve sovrascriverla. |
| **ID dei gruppi di contatti** | — | ID dei gruppi di contatti bexio per i contatti appena creati, separati da virgole. |

Bexy invia `street_name` e `house_number` separatamente. bexio ha deprecato il campo combinato
`address` in scrittura il 9 dicembre 2025.

## Articoli

| Impostazione | Predefinito | Note |
|---|---|---|
| **Abbinare le righe agli articoli bexio tramite SKU** | disattivo | Trasforma una riga in una vera posizione articolo di bexio, il che è ciò che fa funzionare il reporting per articolo di bexio. Uno SKU senza corrispondenza ricade su una posizione libera. |
| **Creare gli articoli inesistenti** | disattivo | Aggiunge lo SKU all’elenco articoli di bexio alla prima vendita. |

## Pagamenti

| Impostazione | Predefinito | Note |
|---|---|---|
| **Registrare i pagamenti in bexio** | attivo | Ogni addebito Commerce riuscito diventa un pagamento sulla fattura, così bexio non lascia aperti gli ordini pagati. Solo fatture. |
| **Conto bancario dei pagamenti** | — | Ricade sul conto bancario qui sopra. |
| **Annullare la fattura in caso di rimborso totale** | disattivo | Solo se in bexio non è stato pagato nulla. Tutto il resto viene segnalato per una nota di credito. |

bexio non ha un endpoint per creare note di credito, solo una lettura del PDF. Bexy non simula mai
un pagamento negativo per aggirarlo; un rimborso che non può annullare viene segnalato a una
persona.

## Riconciliazione

| Impostazione | Predefinito | Note |
|---|---|---|
| **Recuperare lo stato da bexio** | attivo | Richiede `craft bexy/reconcile/run` pianificato. Altrimenti una fattura contrassegnata come pagata in bexio non raggiunge mai Commerce. |
| **Mappatura degli stati** | — | Stato bexio verso stato ordine Commerce. |
| **Verificare solo i documenti degli ultimi** | 120 | Giorni. I documenti pagati e annullati vengono comunque esclusi. 0 verifica tutto. |

Bexy cambia lo stato dell’ordine Commerce e nient’altro. **Non inventa mai una transazione Commerce
per far sembrare pagato un ordine**, perché ciò metterebbe nei vostri report Commerce un pagamento
mai avvenuto.

## Registro

| Impostazione | Predefinito | Note |
|---|---|---|
| **Registrare le richieste** | attivo | |
| **Conservare il corpo di richieste e risposte** | attivo | Token e secret vengono comunque oscurati. |
| **Conservare le voci del registro per** | 30 | Giorni. 0 conserva tutto. La pulizia avviene a ogni comando di sincronizzazione o riconciliazione. |
