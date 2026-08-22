---
title: FAQ
slug: faq
order: 50
summary: Prix, périmètre, et les questions qui reviennent avant l’achat.
---

## Combien ça coûte ?

99 $, une fois. Une seule édition, pas de renouvellement, aucune fonction verrouillée. Les mises à
jour pour Craft 5 sont incluses.

## Que fait-il exactement ?

Les commandes Commerce finalisées deviennent des factures ou des commandes bexio. Les encaissements
Commerce sont comptabilisés comme paiements. Le statut de bexio revient dans Commerce. Les totaux
sont vérifiés avant tout envoi.

## Une panne de bexio casse-t-elle mon paiement en caisse ?

Non. L’envoi est toujours une tâche de file d’attente, et tout ce qui se trouve sur le parcours de
paiement échoue en mode ouvert. Un client peut payer pendant que bexio est indisponible ; la
commande se synchronise à son retour.

## Factures ou commandes ?

L’un ou l’autre. Les factures peuvent être finalisées, envoyées par e-mail et payées : c’est le
choix habituel. Les commandes bexio sont l’étape qui précède la facture et n’acceptent pas de
paiement.

## Pourquoi OAuth plutôt qu’un jeton ?

Les jetons d’accès personnels de bexio expirent 60 jours après leur création, sans prévenir. Une
boutique connectée avec l’un d’eux cesse de se synchroniser deux mois plus tard et rien ne le
signale. Les jetons de rafraîchissement OAuth pivotent et continuent de fonctionner. Bexy prend en
charge les deux ; c’est pourquoi OAuth est le choix par défaut.

## Peut-il envoyer la facture au client ?

Oui, via le réseau de distribution de bexio plutôt que celui de Craft. La facture doit d’abord être
finalisée. Votre corps d’e-mail doit contenir `[Network Link]`, sinon le client reçoit un message
sans facture — Bexy refuse d’en enregistrer un qui en est dépourvu.

## Va-t-il créer des documents en double ?

Non. Bexy inscrit `api_reference` sur chaque document et le recherche avant toute création, et la
table locale est unique par commande. Envoyez deux fois la même commande : le second envoi reprend
le document qui existe déjà.

## Et les remboursements et avoirs ?

Un remboursement intégral annule la facture là où bexio le permet, c’est-à-dire uniquement si rien
n’a encore été payé dessus. Tout le reste est signalé pour un avoir.

L’API bexio n’a pas d’endpoint de création d’avoir, seulement une lecture de PDF. Bexy ne simulera
pas un paiement négatif pour contourner cela, car vos livres affirmeraient quelque chose qui n’a
jamais eu lieu.

## Touche-t-il à mes transactions Commerce ?

Non. Le rapprochement déplace le **statut de commande** Commerce et rien d’autre. Bexy ne fabrique
jamais de transaction Commerce pour faire croire qu’une commande est payée.

## Comment fonctionne la TVA ?

Chaque catégorie de TVA Commerce est associée à un taux bexio et à un compte de produit.
`mwst_type` est lu sur la commande par défaut — un taux Commerce marqué *inclus dans le prix* rend
les prix TTC — et peut être forcé si vous préférez.

Seuls les **taux de TVA sur les ventes actifs** de bexio sont proposés, parce que bexio refuse tout
autre type sur un document.

## Et si les totaux divergent ?

Bexy calcule le total du document avant l’envoi et le compare à celui de Commerce. Un écart de
l’ordre du centime reçoit une position d’arrondi hors taxe. Un écart plus important est refusé et
expliqué, car une différence de cet ordre relève d’une correspondance de TVA erronée et la combler
masquerait le problème.

## Fonctionne-t-il avec plusieurs boutiques ou devises Commerce ?

Les devises oui, à condition que la devise existe dans votre société bexio ; sinon Bexy avertit et
bexio comptabilise dans la devise par défaut de l’entreprise. Bexy relie une installation Craft à
une société bexio.

## Puis-je voir ce qui sera envoyé avant l’envoi ?

```sh
php craft bexy/sync/preview 1234
```

Il utilise le même constructeur que l’envoi réel : l’aperçu est, octet pour octet, ce que bexio
reçoit.

## Dans quelles langues est le panneau de contrôle ?

Anglais, allemand, français et italien.

## Quelles versions de Craft et Commerce ?

Craft CMS 5.3+, Craft Commerce 5.0+, PHP 8.2+.

## Existe-t-il une version d’essai ?

Les plugins du Craft Plugin Store peuvent être essayés aussi longtemps que vous le souhaitez dans
un environnement de développement. Vous ne payez qu’au passage en production.

## Comment obtenir de l’aide ?

Écrivez à [justin@justinholt.com](mailto:justin@justinholt.com). Joignez les entrées pertinentes de
**Bexy → Journal** — les secrets y sont déjà masqués — et la sortie de `php craft bexy/doctor`.
