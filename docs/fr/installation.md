---
title: Installation
slug: installation
order: 10
summary: Prérequis, installation, et connexion de Bexy à votre société bexio.
---

## Prérequis

- Craft CMS 5.3 ou plus récent
- Craft Commerce 5.0 ou plus récent
- PHP 8.2 ou plus récent
- Une formule bexio incluant l’accès API

## Installation

```sh
composer require justinholtweb/craft-bexy
php craft plugin/install bexy
```

Ou cherchez **Bexy** dans le Craft Plugin Store et installez-le depuis là.

Bexy est une édition payante unique, 99 $ une fois. Il n’y a pas de version gratuite et rien n’est
verrouillé : le plugin que vous installez est le plugin entier.

## Rien n’est envoyé avant la connexion

Installer Bexy ne touche ni à bexio ni à vos commandes. Tant que vous n’avez pas connecté une
société bexio et enregistré les réglages, chaque commande finalisée se comporte exactement comme
avant.

## Se connecter à bexio

Bexy parle à bexio de deux façons. **Utilisez OAuth sauf raison contraire.**

### OAuth 2.0 (recommandé)

Les jetons d’accès personnels de bexio expirent 60 jours après leur création, sans prévenir. Les
jetons de rafraîchissement OAuth pivotent et maintiennent la connexion indéfiniment : c’est
pourquoi c’est le choix par défaut.

1. Connectez-vous sur [developer.bexio.com](https://developer.bexio.com) et créez une application.
2. Copiez l’**URL de redirection** affichée dans **Bexy → Réglages → Connexion** dans le champ
   *Allowed redirect URL* de l’application, côté bexio. Elle doit correspondre exactement.
3. Demandez ces scopes. L’accès en lecture est impliqué par l’accès en écriture : demander
   `contact_show` en plus de `contact_edit` ne fait qu’allonger l’écran de consentement :

   ```
   openid profile offline_access
   contact_edit kb_invoice_edit kb_order_edit article_edit
   accounting monitoring_show
   ```

4. Collez l’**identifiant client** et le **secret client** dans Bexy et enregistrez. Stockez le
   secret dans une variable d’environnement plutôt que dans la configuration de projet : celle-ci
   est versionnée.
5. Cliquez sur **Se connecter à bexio** et approuvez l’écran de consentement.

`offline_access` est celui qu’on oublie. Sans lui, bexio n’émet aucun jeton de rafraîchissement et
la connexion meurt avec le premier jeton d’accès.

### Jeton d’accès personnel

Un seul collage, fonctionne immédiatement, et cesse de fonctionner 60 jours plus tard sans
avertissement. Raisonnable pour une société d’essai ; pas de quoi faire tourner une boutique.

Passez **Authentification** sur *Jeton d’accès personnel*, collez le jeton, enregistrez.

## Vérifier

```sh
php craft bexy/doctor
```

`doctor` rend compte de la connexion, de la durée de vie restante du jeton, des listes bexio qu’il
peut lire, de votre correspondance de TVA et de tout ce qui va, selon lui, échouer avant qu’une
commande ne le fasse. À exécuter après chaque changement de réglage.

## Renseigner les valeurs par défaut bexio

Une fois connecté, cliquez sur **Actualiser les listes depuis bexio** dans l’écran des réglages.
Les listes déroulantes utilisateur, compte, TVA, unité, compte bancaire, type de paiement et langue
se remplissent depuis votre société.

Au minimum, renseignez :

- **Utilisateur bexio** — bexio en exige un sur chaque contact et chaque document qu’il enregistre
- **Compte de produit par défaut**
- **Taux de TVA sur les ventes par défaut** — seuls les *taux de TVA sur les ventes actifs*
  apparaissent ici, parce que bexio refuse tout autre type sur un document

Associez ensuite vos catégories de TVA Commerce dans **Correspondance TVA et comptes**. Voir
[Configuration](configuration).

## Votre première commande

1. Finalisez une commande de test dans Commerce.
2. Ouvrez-la. Le panneau **bexio** sur son écran d’édition montre ce qui s’est passé.
3. Ou envoyez-en une à la main :

   ```sh
   php craft bexy/sync/preview 1234   # exactement ce qui serait envoyé ; n’envoie rien
   php craft bexy/sync/order 1234     # envoyer pour de vrai
   ```

`sync/preview` construit le corps du document par le même chemin de code que l’envoi réel : ce
qu’il affiche est, octet pour octet, ce que bexio recevrait.

---

*Bexy est un plugin indépendant. Il n’est ni affilié à bexio AG, ni approuvé ni sponsorisé par
elle. « bexio » et le logo bexio sont des marques de bexio AG.*
