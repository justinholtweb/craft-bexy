---
title: Dépannage
slug: troubleshooting
order: 40
summary: Ce que chaque échec signifie vraiment, et comment le corriger.
---

Commencez ici :

```sh
php craft bexy/doctor
```

Puis **Bexy → Journal**, filtré sur *Erreur*. Les deux corps y sont, secrets masqués.

## Connexion

### « bexio a refusé les identifiants. Reconnectez-vous, ou émettez un nouveau jeton d’accès. »

Un 401. Avec un jeton d’accès personnel, cela signifie presque toujours que les 60 jours sont
écoulés : bexio les fait expirer sans prévenir. Émettez-en un nouveau, ou passez à OAuth.

Avec OAuth, cela signifie que le rafraîchissement a échoué. Reconnectez-vous.

### La connexion OAuth meurt au bout d’une heure

`offline_access` manquait dans les scopes : bexio n’a donc jamais émis de jeton de rafraîchissement.
Ajoutez-le à l’application dans bexio et reconnectez-vous.

### « L’état d’autorisation ne correspondait pas. Relancez la connexion. »

Le callback n’est pas revenu dans la session qui l’avait lancé. Généralement un onglet périmé, un
autre navigateur, ou une URL de redirection qui ne correspond pas exactement à celle enregistrée
dans bexio.

### « bexio n’a renvoyé aucun code d’autorisation. »

L’écran de consentement a été annulé, ou l’URL de redirection dans bexio pointe ailleurs que celle
affichée par Bexy dans l’écran des réglages.

### Le rafraîchissement a marché une fois puis s’est arrêté

bexio **fait pivoter** les jetons de rafraîchissement : chaque renouvellement en renvoie un nouveau
et invalide l’ancien. Si deux processus renouvellent en même temps, l’un se retrouve avec un jeton
mort. Reconnectez-vous. Bexy stocke le jeton pivoté à chaque fois, donc cela ne mord que si quelque
chose en dehors de Bexy renouvelle aussi.

## Documents

### « Le total du document (…) ne correspond pas au total de la commande (…) »

L’écart est dans la tolérance mais la position d’arrondi est désactivée : Bexy l’a donc signalé
plutôt que comblé. Activez **Combler un écart par une position d’arrondi**, ou corrigez la cause.

### « C’est trop important pour un arrondi : vérifiez la correspondance de TVA… »

L’écart a dépassé l’**Écart maximal à combler**, Bexy a donc refusé d’ajuster. C’est le
comportement voulu. Une différence de cet ordre est l’une de celles-ci :

- une catégorie de TVA Commerce sans taux bexio associé, si bien que bexio a appliqué le taux par
  défaut du document
- un mauvais **Mode d’envoi des prix** — des prix TTC envoyés comme HT, ou l’inverse
- un ajustement tiers qui n’est pas ce que vous pensiez

`php craft bexy/sync/preview <orderId>` affiche les positions et le calcul. La ligne fautive est
généralement évidente.

Relever la limite pour faire disparaître le message revient à combler une vraie erreur comptable
avec une ligne intitulée « Arrondi ». À éviter.

### « Aucun contact bexio n’a pu être déterminé pour cette commande. »

bexio refuse un document sans contact. Soit **Créer les contacts** est désactivé et aucune
correspondance n’a été trouvée, soit la création a échoué — l’entrée de journal juste au-dessus dit
pourquoi. La cause habituelle est l’absence d’**utilisateur bexio** dans les réglages de Bexy :
bexio exige `user_id` *et* `owner_id` sur chaque contact.

### « Aucun taux de TVA bexio n’est associé à … »

Un avertissement, pas un échec. La position a été comptabilisée au taux par défaut du document, qui
peut être le mauvais. Ajoutez la correspondance dans **Correspondance TVA et comptes** et renvoyez.

### « bexio ne connaît aucune devise nommée … »

La devise de la commande n’est pas configurée dans la société bexio. Le document a été comptabilisé
dans la devise par défaut de l’entreprise, donc les chiffres sont faux. Ajoutez la devise dans
bexio.

### « bexio a accepté le document mais n’a renvoyé aucun ID. »

Rare. Le document existe généralement quand même — vérifiez dans bexio et utilisez **Actualiser
depuis bexio** sur le panneau de commande, ou renvoyez et laissez la recherche `api_reference` le
reprendre.

### La même commande apparaît deux fois dans bexio

Cela ne devrait pas arriver. `bexy_documents` est unique par `orderId` et Bexy cherche
`api_reference` avant de créer. Un doublon signifie que le second a été créé en dehors de Bexy.
Annulez-le là-bas, puis **Actualiser depuis bexio**.

## Paiements

### Une facture reste ouverte dans bexio alors que Commerce dit payée

- **Comptabiliser les paiements dans bexio** est désactivé, ou
- le type de document est *Commande* — on ne peut pas comptabiliser de paiement sur une commande
  bexio, ou
- la facture n’a jamais été finalisée, il n’y a donc rien à payer.

### « Le paiement de … n’a pas pu être comptabilisé dans bexio »

Généralement un **compte bancaire des paiements** manquant, ou une facture dans un état où bexio
refuse un paiement. Le corps de la réponse dans le journal nomme le champ.

## Rapprochement

### Les statuts ne reviennent jamais

`bexy/reconcile/run` ne tourne pas. C’est une commande planifiée ; personne ne l’appelle pour vous.

Vérifiez aussi **Ne vérifier que les documents des derniers** : un document plus ancien que cette
fenêtre est ignoré.

### « Impossible de faire passer la commande … au statut … »

Le handle de statut Commerce dans **Correspondance des statuts** n’existe plus, ou un événement
Commerce a refusé le changement. Re-sélectionnez le statut.

## Remboursements

### « … il faut donc en créer un dans bexio. »

Attendu. L’API bexio ne permet pas d’émettre un avoir : un remboursement qui ne peut pas être traité
en annulant la facture est signalé à un humain. Créez l’avoir dans bexio.

### « … la facture bexio n’a pas pu être annulée »

bexio n’annule pas une facture sur laquelle un paiement a été enregistré. Créez un avoir à la place.

## Limites de débit

### « La limite de débit de bexio a été atteinte et l’est restée. »

bexio limite par société et par minute. Bexy respecte l’en-tête `RateLimit-Reset`, attendant jusqu’à
3 secondes dans une requête web et jusqu’à 60 en contexte console et file d’attente. S’il abandonne
malgré tout, c’est que quelque chose d’autre sollicite la même société. Relancez la tâche.

## Il ne se passe rien du tout

- La file d’attente de Craft tourne-t-elle ? L’envoi est toujours une tâche de file.
- **Envoyer les commandes automatiquement** est-il activé ?
- **Uniquement lorsque la commande atteint** pointe-t-il vers un statut que la commande n’atteint
  jamais ?
- `php craft bexy/sync/status` donne les décomptes. `php craft bexy/sync/pending` envoie l’arriéré.
