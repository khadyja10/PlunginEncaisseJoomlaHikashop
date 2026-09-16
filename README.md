# Plugin de paiement Encaisse pour HikaShop

Plugin de paiement **Encaisse** pour **Joomla** et **HikaShop**.

Le plugin permet à un client de sélectionner un partenaire de paiement Encaisse
pendant le checkout HikaShop, puis de confirmer automatiquement la commande
après vérification du paiement auprès de l'API Encaisse.

## Fonctionnalités

- Affichage du moyen de paiement Encaisse dans le checkout HikaShop.
- Chargement des partenaires de paiement depuis l'API Encaisse.
- Sélection d'un partenaire de paiement par le client.
- Création d'une transaction Encaisse.
- Redirection du client vers la page de paiement Encaisse.
- Réception du webhook de paiement HikaShop.
- Vérification du statut réel de la transaction via l'API Encaisse.
- Confirmation automatique de la commande HikaShop.
- Vidage du panier après confirmation du paiement.
- Redirection vers le détail de la commande.
- Envoi de la notification de confirmation par HikaShop.

## Prérequis

- Joomla installé et fonctionnel.
- HikaShop installé et activé.
- PHP avec l'extension `cURL`.
- Un compte marchand Encaisse actif.
- Les identifiants Encaisse :
  - `Client ID`
  - `Client Secret`
  - `Company ID`

## Structure recommandée du dépôt

Le dépôt GitHub peut être organisé comme suit :

```text
encaisse-hikashop/
├── README.md
├── LICENSE
└── encaisse/
    ├── encaisse.php
    ├── encaisse.xml
    ├── assets/
    │   ├── css/
    │   ├── js/
    │   └── img/
    ├── helpers/
    └── language/
        ├── en-GB/
        └── fr-FR/
```

Le fichier `README.md` doit rester à la racine du dépôt GitHub. Il n'est pas
nécessaire de l'installer dans Joomla avec le plugin.

## Installation dans Joomla

### Depuis une archive ZIP

1. Télécharger ou générer l'archive du plugin.
2. Dans Joomla, ouvrir **Système > Installer > Extensions**.
3. Envoyer l'archive ZIP du plugin.
4. Ouvrir **Système > Plugins**.
5. Rechercher `HikaShop Encaisse payment plugin`.
6. Activer le plugin.
7. Ouvrir la méthode de paiement Encaisse dans HikaShop.
8. Renseigner les identifiants Encaisse.

L'archive destinée à Joomla doit contenir `encaisse.xml` à sa racine :

```text
plg_hikashoppayment_encaisse.zip
├── encaisse.xml
├── encaisse.php
├── assets/
├── helpers/
└── language/
```

Si le dépôt utilise le dossier `encaisse/`, créer l'archive depuis ce dossier
afin de ne pas ajouter un niveau de dossier inutile :

```bash
cd encaisse
zip -r ../plg_hikashoppayment_encaisse.zip .
```

## Configuration du plugin

Dans la configuration de la méthode de paiement Encaisse, renseigner :

| Paramètre | Description |
|---|---|
| `Client ID` | Identifiant de l'application Encaisse |
| `Client Secret` | Secret de l'application Encaisse |
| `Company ID` | Identifiant de l'entreprise ou du compte marchand |

Le plugin doit utiliser l'API de production :

```text
https://api.encaisse.net
```

Les identifiants Encaisse ne doivent jamais être ajoutés dans GitHub, dans le
README ou dans un fichier de configuration versionné.

## Configuration du webhook Encaisse

Dans la plateforme Encaisse, configurer l'URL de callback de production
suivante, en remplaçant le domaine par celui du site Joomla :

```text
https://www.exemple.com/index.php?option=com_hikashop&ctrl=checkout&task=notify&notif_payment=encaisse
```

Le webhook est reçu par le plugin HikaShop, puis le plugin :

1. récupère l'identifiant de transaction ;
2. vérifie le statut de la transaction auprès de l'API Encaisse ;
3. accepte uniquement un statut payé ou réussi ;
4. recherche la commande HikaShop correspondante ;
5. confirme la commande ;
6. vide le panier ;
7. permet l'affichage du détail de la commande.

Le site doit être accessible en HTTPS pour que les redirections et les
notifications de paiement fonctionnent correctement.

## Flux de paiement

```text
Client
  │
  ├── Sélectionne Encaisse et un partenaire
  │
  ├── HikaShop crée la transaction Encaisse
  │
  ├── Redirection vers la page de paiement Encaisse
  │
  ├── Encaisse appelle le webhook Joomla
  │
  ├── Le plugin vérifie le statut auprès de l'API
  │
  ├── La commande HikaShop passe à confirmed
  │
  └── Le panier est vidé et la commande est affichée
```

## Développement et tests

Avant toute mise en production, tester au minimum :

- l'affichage du moyen de paiement dans le checkout ;
- le chargement des partenaires Encaisse ;
- la sélection d'un partenaire ;
- la création d'une transaction ;
- la redirection vers Encaisse ;
- la réception du webhook ;
- la confirmation de la commande ;
- le vidage du panier ;
- l'envoi de l'e-mail HikaShop ;
- le comportement lorsqu'un paiement échoue ou reste en attente.

Ne pas utiliser de certificats SSL désactivés en production. Les options
suivantes ne doivent pas être présentes dans la version publiée :

```php
CURLOPT_SSL_VERIFYPEER => false,
CURLOPT_SSL_VERIFYHOST => false,
```

## Journaux

Le plugin peut écrire des informations de diagnostic dans les journaux Joomla.
Ces journaux peuvent contenir des données de transaction ou de callback.

- Ne jamais publier les journaux sur GitHub.
- Ne jamais publier de `Client Secret`.
- Protéger l'accès au dossier de logs.
- Désactiver ou réduire les logs de diagnostic après la mise en production.

## Compatibilité

Ce plugin est conçu pour fonctionner avec Joomla et HikaShop. La compatibilité
exacte dépend de la version de Joomla, de HikaShop, de PHP et de l'API
Encaisse utilisées sur le serveur.

## Contribution

Les contributions sont bienvenues :

1. créer une branche dédiée ;
2. effectuer la modification ;
3. tester le checkout et le webhook ;
4. ouvrir une Pull Request avec une description claire.

Ne pas inclure de secrets, de données clients ou de journaux réels dans une
Pull Request.

## Licence

À compléter selon la licence choisie par l'auteur du plugin.

Exemple :

```text
Copyright (c) 2026 TelEtCom
```
