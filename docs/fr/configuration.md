---
title: Configuration
slug: configuration
order: 20
summary: Chaque réglage, son effet, et ceux qui comptent pour un décompte TVA correct.
---

Tout cela se trouve dans **Bexy → Réglages**. Rien ici n’est obligatoire, une installation neuve
s’enregistre donc toujours.

## Ce qui est créé

| Réglage | Notes |
|---|---|
| **Type de document** | *Facture* ou *Commande*. Les factures peuvent être finalisées, envoyées et payées. Les commandes sont l’étape antérieure et n’acceptent pas de paiement. |
| **Envoyer les commandes automatiquement** | Met un envoi en file d’attente dès qu’une commande est finalisée. L’envoi passe toujours par la file, si bien que bexio ne peut jamais bloquer un paiement en caisse. |
| **Uniquement lorsque la commande atteint** | Laissez toutes les cases décochées pour envoyer dès la finalisation. Cochez-en une ou plusieurs pour attendre que quelqu’un y déplace la commande. |
| **Finaliser la facture** | La comptabilise et lui attribue un numéro. Jusque-là, c’est un brouillon dans bexio : ni envoi ni paiement possible. |
| **Laisser bexio l’envoyer par e-mail** | L’envoi passe par le réseau de distribution de bexio, pas celui de Craft. Nécessite *Finaliser la facture*. |

### Corps de l’e-mail

bexio insère le document là où figure `[Network Link]`. **Sans cet espace réservé, le client reçoit
un e-mail sans facture**, c’est pourquoi Bexy refuse d’enregistrer un corps qui l’omet.
`{number}`, `{name}` et `{email}` sont également remplacés.

### Titre du document

Variables : `{number}`, `{reference}`, `{date}`, `{total}`, `{currency}`, `{name}`, `{email}`.

## Valeurs par défaut bexio

Renseignées par **Actualiser les listes depuis bexio**.

| Réglage | Notes |
|---|---|
| **Utilisateur bexio** | Exigé par bexio sur chaque contact et chaque document. Par défaut, celui qui a autorisé la connexion. |
| **Compte de produit par défaut** | Compte sur lequel une position est comptabilisée lorsque sa catégorie de TVA n’en a pas. |
| **Taux de TVA sur les ventes par défaut** | Seuls les taux actifs sont proposés — bexio refuse tout autre type sur un document. |
| **Unité**, **Compte bancaire**, **Type de paiement**, **Langue du document** | Valeurs par défaut du document. |
| **ID du papier à en-tête** | Le `logopaper_id` de bexio. Vide signifie la valeur par défaut de l’entreprise. |
| **Délai de paiement** | Jours avant échéance. 30 par défaut. |

## TVA

C’est la section à ne pas rater. Tout le reste est cosmétique en comparaison.

### Mode d’envoi des prix

`mwst_type` sur le document bexio : 0 prix TTC, 1 TVA en sus, 2 exonéré.

- **Depuis Commerce** (par défaut) lit l’information sur la commande. Un taux marqué *inclus dans
  le prix* rend tous les prix TTC.
- Les trois options explicites l’emportent pour chaque document.

### Correspondance TVA et comptes

Une ligne par catégorie de TVA Commerce, indiquant le taux bexio et le compte de produit auxquels
ses lignes sont imputées. Ce qui n’est pas renseigné retombe sur les valeurs par défaut ci-dessus.

Une correspondance manquante n’est pas une erreur : bexio comptabilise la position au taux par
défaut du document, qui peut être le mauvais, et Bexy enregistre un avertissement sur le document.
Consultez le journal avant de vous fier aux chiffres d’un trimestre.

La livraison a son propre libellé, son propre taux et son propre compte, car elle relève rarement
de la même catégorie que la marchandise.

## Totaux

bexio calcule lui-même le total du document. Bexy calcule le même montant avant l’envoi et le
compare à celui de Commerce, afin qu’un écart apparaisse ici plutôt qu’au moment du décompte TVA.

| Réglage | Défaut | Notes |
|---|---|---|
| **Combler un écart par une position d’arrondi** | activé | Ajoute une ligne hors taxe pour la différence. Désactivé, l’écart est seulement signalé. |
| **Tolérance** | 0.01 | Écart admis entre les deux totaux. Inclusif : un écart égal à la tolérance n’est pas une divergence. |
| **Écart maximal à combler** | 1.00 | Au-delà, Bexy refuse d’ajuster et explique. 0 supprime la limite. |
| **Libellé de l’arrondi** | Arrondi | Le nom de la ligne sur le document. |

Deux choses à savoir sur la position d’arrondi :

- **Elle est hors taxe volontairement.** Une ligne d’arrondi taxée déplace le total TTC de l’écart
  *plus la TVA*, et rate de nouveau la cible.
- **La limite haute est tout l’intérêt.** Sans elle, une TVA de 7,7 % entièrement mal associée est
  discrètement comblée par une ligne intitulée « Arrondi » et le document s’équilibre tout en étant
  faux. Une différence de cet ordre relève d’une correspondance de TVA erronée, pas d’un arrondi.

## Contacts

| Réglage | Défaut | Notes |
|---|---|---|
| **Créer les contacts** | activé | Bexy fait d’abord la correspondance par e-mail — sa propre table, puis la liste de contacts bexio — et ne crée un contact que si ni l’une ni l’autre n’en contient. |
| **Mettre à jour les contacts existants** | désactivé | Reporte l’adresse de la commande sur le contact bexio. Désactivé par défaut : la fiche contact appartient au comptable, et une adresse de livraison saisie dans la boutique ne doit pas l’écraser. |
| **ID des groupes de contacts** | — | ID des groupes de contacts bexio pour les contacts nouvellement créés, séparés par des virgules. |

Bexy envoie `street_name` et `house_number` séparément. bexio a déprécié le champ combiné
`address` en écriture le 9 décembre 2025.

## Articles

| Réglage | Défaut | Notes |
|---|---|---|
| **Faire correspondre les lignes aux articles bexio par SKU** | désactivé | Transforme une ligne en véritable position d’article bexio, ce qui est indispensable au reporting par article de bexio. Un SKU sans correspondance retombe sur une position libre. |
| **Créer les articles inexistants** | désactivé | Ajoute le SKU à la liste d’articles de bexio lors de la première vente. |

## Paiements

| Réglage | Défaut | Notes |
|---|---|---|
| **Comptabiliser les paiements dans bexio** | activé | Chaque encaissement Commerce réussi devient un paiement sur la facture, afin que bexio ne laisse pas les commandes payées en suspens. Factures uniquement. |
| **Compte bancaire des paiements** | — | Retombe sur le compte bancaire ci-dessus. |
| **Annuler la facture en cas de remboursement intégral** | désactivé | Uniquement si rien n’a été payé dessus dans bexio. Tout le reste est signalé pour un avoir. |

bexio n’a pas d’endpoint de création d’avoir, seulement une lecture de PDF. Bexy ne simule jamais
un paiement négatif pour contourner cela ; un remboursement qu’il ne peut pas annuler est signalé à
un humain.

## Rapprochement

| Réglage | Défaut | Notes |
|---|---|---|
| **Récupérer le statut depuis bexio** | activé | Nécessite `craft bexy/reconcile/run` de façon planifiée. Sans cela, une facture marquée payée dans bexio n’atteint jamais Commerce. |
| **Correspondance des statuts** | — | Statut bexio vers statut de commande Commerce. |
| **Ne vérifier que les documents des derniers** | 120 | Jours. Les documents payés et annulés sont de toute façon exclus. 0 vérifie tout. |

Bexy modifie le statut de commande Commerce et rien d’autre. **Il ne fabrique jamais de transaction
Commerce pour faire croire qu’une commande est payée**, car cela inscrirait dans vos rapports
Commerce un paiement qui n’a jamais eu lieu.

## Journal

| Réglage | Défaut | Notes |
|---|---|---|
| **Journaliser les requêtes** | activé | |
| **Conserver le corps des requêtes et des réponses** | activé | Les jetons et les secrets sont masqués dans tous les cas. |
| **Conserver les entrées du journal pendant** | 30 | Jours. 0 conserve tout. Purge à chaque commande de synchronisation ou de rapprochement. |
