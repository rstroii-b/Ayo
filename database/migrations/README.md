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
