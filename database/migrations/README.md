# Migrations Ayo

Les migrations de ce dossier sont **non destructives** : elles ajoutent les tables et colonnes nécessaires sans supprimer les comptes, restaurants ou commandes existants.

## Migration 001

Ajoute :

- les coupons validables côté serveur ;
- l'historique d'utilisation des coupons ;
- les avis clients vérifiables par commande livrée ;
- les restaurants favoris ;
- les colonnes de promotion et de mode de livraison sur les commandes ;
- les agrégats de note sur les restaurants.

Exécution :

```bash
mysql -u <user> -p <database> < database/migrations/001_marketplace_features.sql
```

La migration doit être exécutée avant d'activer les routes d'avis, favoris et coupons côté API.

## Migration 002

Ajoute :

- `orders.payment_status` / `orders.paid_at` : l'état de paiement devient une donnée de la
  commande, et non plus une simple ligne d'historique que personne ne relisait ;
- `auth_attempts` : journal des tentatives de connexion, socle du plafonnement anti-force brute ;
- deux index de lecture (file des courses disponibles, historique client).

La migration reprend l'existant : les commandes déjà livrées, ou portant un événement
`payment_succeeded`, sont marquées payées. Sans cette reprise, le contrôle « une commande
n'entre en cuisine qu'une fois payée » bloquerait les commandes en cours au moment du
déploiement.

Exécution :

```bash
mysql -u <user> -p <database> < database/migrations/002_security_and_pricing.sql
```

## Installation neuve

`database/schema.sql` décrit le schéma d'origine. Sur une base vierge, exécuter dans l'ordre :

```bash
mysql -u <user> -p <database> < database/schema.sql
mysql -u <user> -p <database> < database/migrations/001_marketplace_features.sql
mysql -u <user> -p <database> < database/migrations/002_security_and_pricing.sql
```

Les migrations ne sont pas ré-exécutables (`ALTER TABLE ... ADD COLUMN` et `CREATE INDEX`
échouent si la colonne ou l'index existe déjà) : c'est volontaire, une migration rejouée par
erreur doit échouer bruyamment plutôt que passer inaperçue.
