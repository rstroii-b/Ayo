# Saveurs — squelette de projet

Correspond aux sprints 1-2 de la feuille de route du document d'architecture :
auth, restaurants, menus, commandes.

## Prérequis (installés et vérifiés dans cet environnement)

PHP 8.5, Composer 2.10, MySQL 9.7 (mysql-lts) — installés via Scoop
(`scoop install php composer mysql-lts`). Extensions PHP activées dans
`php.ini` : `pdo_mysql`, `mysqli`, `mbstring`, `openssl`, `curl`, `fileinfo`, `zip`.

L'ensemble du parcours ci-dessous a été exécuté et testé de bout en bout dans cet
environnement (register → login → liste restaurants avec tri par distance → commande
avec prix recalculés serveur → idempotence vérifiée → transitions de statut → front
qui appelle vraiment l'API en CORS).

## 1. Base de données

```bash
mysqld --console                     # démarre le serveur (garder ouvert, ou l'installer comme service Windows)
mysql -u root -e "CREATE DATABASE saveurs CHARACTER SET utf8mb4"
mysql -u root saveurs < database/schema.sql
```

Mot de passe root laissé vide par l'installateur (`mysqld --install` pour l'enregistrer comme
service Windows ; `mysql_secure_installation` pour fixer un mot de passe avant toute mise en ligne).

## 2. API

```bash
cd api
composer install
cp .env.example .env      # renseigner JWT_SECRET, CORS_ORIGIN si besoin
php -S localhost:8000 -t public
```

Tester :
```bash
curl -X POST localhost:8000/api/v1/auth/register -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"secret123","first_name":"A","last_name":"B","role":"client"}'
```

Notes de compatibilité rencontrées pendant l'install : `firebase/php-jwt ^6.10` est bloqué par
une alerte de sécurité Composer (PKSA-y2cr-5h3j-g3ys) — le projet est fixé sur `^7.1`, dont l'API
`JWT::decode()/::encode()` est compatible avec le code existant.

## 3. Front (web/)

```bash
php -S localhost:5500 -t web
```

`CORS_ORIGIN` dans `api/.env` doit correspondre à l'origine du front (`http://localhost:5500`
par défaut). Ouvrir `http://localhost:5500` : la page d'accueil appelle `GET /api/v1/restaurants`
et affiche la liste.

## 4. Paiement — Stripe Connect

Le compte Stripe doit avoir **Connect activé** (Dashboard → Connect → Get started, gratuit,
quelques clics) avant d'utiliser les endpoints `/connect/*` et `/payments/*`.

```bash
# api/.env — remplacer les placeholders par vos vraies clés de test
STRIPE_SECRET_KEY=sk_test_...        # Dashboard → Developers → API keys
STRIPE_PUBLISHABLE_KEY=pk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...      # voir Stripe CLI ci-dessous
DISPATCH_COMMISSION_PCT=15           # commission plateforme sur les frais de livraison
```

Webhooks en local avec le [Stripe CLI](https://docs.stripe.com/stripe-cli) :
```bash
stripe listen --forward-to localhost:8000/api/v1/webhooks/stripe
# copie le "whsec_..." affiché dans STRIPE_WEBHOOK_SECRET
```

Parcours complet :
1. `POST /restaurants` (restaurateur) — créer sa fiche restaurant.
2. `POST /connect/onboard` (restaurateur ou livreur) — crée le compte Stripe Connect Express
   et renvoie `onboarding_url` : ouvrir ce lien dans un navigateur pour le KYC hébergé par Stripe
   (formulaire de test, aucune vraie pièce d'identité requise en mode test).
3. `GET /connect/status` — `payouts_enabled: true` une fois l'onboarding Stripe terminé.
4. `POST /payments/intent` (client, body `{"order_id": ...}`) — renvoie un `client_secret` à
   utiliser avec Stripe.js côté front (Payment Element) pour encaisser la carte.
5. Quand la commande passe `delivered` (`PATCH /orders/{id}/status`), le serveur déclenche
   automatiquement les deux virements (`PayoutService`) : restaurant = sous-total − commission,
   livreur = frais de livraison − commission dispatch. Chaque tentative est tracée dans `payouts`
   (`statut`, `stripe_transfer_id` ou `failure_reason`).

**Testé dans cet environnement** avec des clés placeholder (`sk_test_...` littéral) : tout le
pipeline — inscription livreur avec SIRET, création restaurant, cycle de commande complet
jusqu'à `delivered`, déclenchement des deux virements — fonctionne et échoue *proprement*
(`502` avec message Stripe explicite, lignes `payouts` en `statut: failed`) puisque les comptes
Connect n'existent pas encore. Remplacez les clés par les vraies pour aller jusqu'au bout.

## Ce qui est fait / pas fait

Fait : inscription/connexion (JWT), liste + fiche + menu restaurant, création de restaurant,
gestion du menu par le restaurateur, création de commande (prix recalculés serveur, idempotence),
transitions de statut par rôle, un livreur qui "prend" une commande prête (`/orders/{id}/claim`),
file d'attente temps réel du back-office (`/restaurant/orders/live`), onboarding Stripe Connect
Express (restaurant + livreur), PaymentIntent, webhook signé, split des virements à la livraison.

Pas fait (sprints suivants, voir le document d'architecture §9) : diffusion temps réel via
Soketi/WebSocket (le front doit recharger pour voir un changement de statut), dispatch
automatique par proximité (le livreur "prend" une commande manuellement, pas de géolocalisation
Redis), refresh token avec révocation réelle, page de retour d'onboarding Stripe côté front
(`onboarding.html` référencé dans `ConnectController` reste à créer), Payment Element côté
front pour saisir la carte.
