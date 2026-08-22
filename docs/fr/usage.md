---
title: Utilisation
slug: usage
order: 30
summary: Le panneau de commande, l’écran Documents, le journal, et toutes les commandes console.
---

## Ce qui se passe quand une commande est finalisée

1. Commerce marque la commande comme finalisée.
2. Bexy met une tâche en file d’attente. **L’envoi ne s’exécute jamais en ligne**, de sorte qu’une
   panne de bexio ne peut pas empêcher un client de payer.
3. La tâche résout un contact, construit le document, vérifie le total face à celui de Commerce, et
   le crée dans bexio.
4. Si c’est configuré, la facture est finalisée et envoyée par e-mail.
5. Les encaissements Commerce réussis sont comptabilisés comme paiements.
6. `bexy/reconcile/run` récupère plus tard le statut de bexio et aligne la commande Commerce.

Chaque étape est écrite dans le journal de connexion, avec les deux corps, secrets masqués.

## Le panneau de commande

L’écran d’édition de commande de Commerce gagne un panneau **bexio** affichant le document, son
numéro, s’il a été finalisé et envoyé, le total de la commande face au total du document,
l’`api_reference`, les tentatives, et la date de la dernière synchronisation et du dernier
rapprochement.

De là, vous pouvez **envoyer**, **renvoyer**, **actualiser depuis bexio**, **voir le PDF**,
**annuler dans bexio**, et **oublier** l’enregistrement de la commande dans Bexy. Oublier ne
supprime que la ligne locale ; le document bexio reste intact.

## Documents

**Bexy → Documents** liste chaque commande connue de Bexy, filtrable par statut et cherchable par
numéro de commande ou numéro bexio. *À vérifier* est la vue à surveiller : un envoi en échec, un
écart de total, un remboursement qui réclame un avoir.

Ouvrir un document montre ce qui a été envoyé, ce qui est revenu, les paiements comptabilisés
dessus, et le dernier message de bexio.

## Journal

**Bexy → Journal** contient chaque requête HTTP faite par le plugin, avec l’action, l’endpoint, le
statut, et les corps de requête et de réponse. Filtrable par action et par niveau.

Les jetons, secrets et codes d’autorisation sont masqués à l’écriture : le journal est donc sûr à
lire et sûr à coller dans un e-mail de support.

## Commandes console

```sh
php craft bexy/doctor              # connexion, jetons, listes, correspondances, problèmes probables
```

### Synchroniser

```sh
php craft bexy/sync/preview 1234   # afficher exactement ce qui serait envoyé ; n’envoie rien
php craft bexy/sync/order 1234     # envoyer une commande
php craft bexy/sync/pending        # envoyer tout ce qui n’est pas encore dans bexio
php craft bexy/sync/status         # décompte par état
```

`sync/preview` passe par le même constructeur que l’envoi réel : un aperçu est, octet pour octet,
ce que bexio reçoit. À utiliser avant un premier envoi en production, et pour diagnostiquer un
écart de total.

### Rapprocher

```sh
php craft bexy/reconcile/run
```

À planifier — toutes les heures suffit largement :

```
0 * * * * cd /chemin/vers/craft && php craft bexy/reconcile/run >> /dev/null 2>&1
```

### Listes

```sh
php craft bexy/meta/taxes           # uniquement les taux de TVA sur les ventes actifs
php craft bexy/meta/accounts
php craft bexy/meta/users
php craft bexy/meta/currencies
php craft bexy/meta/units
php craft bexy/meta/languages
php craft bexy/meta/payment-types
php craft bexy/meta/bank-accounts
php craft bexy/meta/flush           # vider les listes en cache et les récupérer à nouveau
```

## Twig

```twig
{% set doc = craft.bexy.document(order) %}
{% if doc %}
    {{ doc.bexioNumber }} — {{ doc.status }}
{% endif %}
```

`craft.bexy.document()` renvoie `null` pour une commande que Bexy n’a jamais vue, et gère une
commande nulle sans broncher.

## Idempotence

Bexy inscrit `api_reference` sur chaque document et le recherche avant toute création. En plus de
cela, `bexy_documents` est unique par `orderId` et `bexy_payments` par `transactionId`.

Concrètement : envoyer deux fois la même commande reprend le document bexio existant au lieu d’en
créer un second, et le même encaissement Commerce n’est jamais comptabilisé comme deux paiements.
Si un document existait dans bexio avant que Bexy n’en ait connaissance, le chemin de reprise s’en
saisit et le dit.

## Remboursements

- Un **remboursement intégral** sans rien de payé sur la facture dans bexio annule la facture, si
  vous avez activé l’option.
- Tout le reste est **signalé pour un avoir**. L’API bexio n’a pas d’endpoint de création d’avoir :
  il faut donc un humain dans bexio.

Bexy ne comptabilise jamais un paiement négatif pour masquer l’écart.
