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
mysql -u root saveurs < database/migrations/001_marketplace_features.sql
mysql -u root saveurs < database/migrations/002_security_and_pricing.sql
```

Les deux migrations sont **obligatoires**, y compris sur une base neuve : `schema.sql` décrit
le schéma d'origine, les coupons, l'état de paiement et le journal anti-force brute vivent dans
les migrations. Voir `database/migrations/README.md`.

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

## 4. Paiement — CinetPay (mobile money)

Un compte [CinetPay](https://app.cinetpay.com) est nécessaire avant d'utiliser les endpoints
`/connect/*` et `/payments/*`.

```bash
# api/.env — remplacer les placeholders par vos vraies clés (menu Intégrations > Clé API)
CINETPAY_API_KEY=...
CINETPAY_API_PASSWORD=...
CINETPAY_ENV=sandbox                 # ou "prod" une fois prêt à encaisser réellement
CINETPAY_COUNTRY=CI
DISPATCH_COMMISSION_PCT=15           # commission plateforme sur les frais de livraison
```

Laisser `CINETPAY_API_KEY`/`CINETPAY_API_PASSWORD` vides désactive proprement le paiement
(`503` explicite) sans casser le reste de l'app.

Parcours complet :
1. `POST /restaurants` (restaurateur) — créer sa fiche restaurant.
2. `PATCH /connect/mobile-money` (restaurateur ou livreur, body `{"operator": "...", "phone_number": "..."}`)
   — enregistre le compte mobile money qui recevra les reversements.
3. `GET /connect/status` — `mobile_money_configured: true` une fois le compte renseigné.
4. `POST /payments/intent` (client, body `{"order_id": ...}`) — renvoie un `payment_url` CinetPay
   hébergé, vers lequel rediriger le client pour payer (Orange Money, MTN Money, Moov Money, Wave).
5. `POST /webhooks/cinetpay` (non authentifié, vérifié par `notify_token`) confirme le paiement
   côté serveur une fois le client revenu de la page CinetPay.
6. Quand la commande passe `delivered` (`PATCH /orders/{id}/status`), le serveur déclenche
   automatiquement les deux virements (`PayoutService`) : restaurant = sous-total − commission,
   livreur = frais de livraison − commission dispatch. Chaque tentative est tracée dans `payouts`
   (`statut`, `cinetpay_transfer_id` ou `failure_reason`).

## 5. Vérification d'identité (KYC) livreurs et panel admin

Chaque livreur envoie une pièce d'identité (JPEG/PNG/PDF, 8 Mo max) depuis `driver.html` —
`POST /driver/kyc-document`. Le fichier est stocké hors du webroot dans `api/storage/kyc/`
(créé automatiquement, jamais servi directement par le serveur web — voir `KycStorage`), sous
un nom généré côté serveur. Un admin le consulte et l'approuve/rejette depuis `admin.html`.

**Il n'y a pas d'inscription admin en self-service** (sécurité — `AuthController::register()`
reste volontairement limité à `client`/`restaurant_owner`/`driver`). Pour créer le premier
compte admin, insérez-le directement en base :

```bash
php -r "echo password_hash('votre-mot-de-passe', PASSWORD_BCRYPT), PHP_EOL;"
```

```sql
INSERT INTO users (email, password_hash, first_name, last_name, role)
VALUES ('admin@jobivoire.com', '<hash-collé-ci-dessus>', 'Admin', 'Ayo', 'admin');
```

Connectez-vous ensuite normalement sur `/login.html` — la redirection vers `/admin.html` est
automatique pour ce rôle.

## 6. Déploiement en production (IONOS Hébergement Web Plus)

Vérifié sur la fiche officielle de l'offre : PHP 8.2/8.3/8.4, bases MariaDB, accès SSH/SFTP,
support `.htaccess`, SSL inclus — compatible avec ce projet tel quel.

**Architecture recommandée : deux domaines/sous-domaines séparés.**

```text
ayo.jobivoire.com        → dossier web/            (front statique)
api-ayo.jobivoire.com     → dossier api/public/     (API — vendor/, src/, .env restent HORS de
                                                       la racine web, jamais accessibles par une URL)
```

Ne mettez jamais `api/` (avec `vendor/` et `.env`) directement sous la racine web du domaine
principal — n'importe qui pourrait alors télécharger `.env` (vos clés CinetPay/JWT) via une URL.
Le sous-domaine dédié, pointé précisément sur `api/public/`, évite ce problème par construction.

**Étapes :**

1. **Panneau IONOS** → activer/récupérer les identifiants SSH, créer le sous-domaine
   `api-ayo.jobivoire.com`, et pointer sa racine web sur le dossier où vous déploierez `api/public/`
   (le reste de `api/` — `src/`, `vendor/`, `.env` — doit exister sur le serveur mais **en dehors**
   de ce dossier public, par exemple un niveau au-dessus).
2. **Connexion SSH** puis clone du dépôt :

   ```bash
   git clone https://github.com/rstroii-b/Ayo.git
   cd Ayo/api
   ```

3. **Composer** (pas forcément préinstallé — l'installer en local à l'utilisateur) :

   ```bash
   curl -sS https://getcomposer.org/installer | php
   php composer.phar install --no-dev --optimize-autoloader
   ```

4. **Base de données** : créez une base MariaDB depuis le panneau IONOS (notez host/utilisateur/
   mot de passe/nom — le host n'est généralement pas `127.0.0.1` en mutualisé, vérifiez la valeur
   exacte affichée dans le panneau), puis importez le schéma :

   ```bash
   mysql -h <host-fourni-par-ionos> -u <user> -p <nom_base> < ../database/schema.sql
   ```

   `api/storage/kyc/` (pièces d'identité livreurs) est créé automatiquement au premier upload —
   vérifiez simplement que l'utilisateur PHP-FPM a le droit d'écrire dans `api/` sur l'hébergement.

5. **`api/.env`** (créer sur le serveur, ne jamais committer) : renseignez les vraies valeurs
   `DB_HOST`/`DB_USER`/`DB_PASS`/`DB_NAME`, un `JWT_SECRET` fort et unique (`openssl rand -hex 32`),
   `CORS_ORIGIN=https://ayo.jobivoire.com`, vos clés CinetPay, `FRONT_URL`/`API_URL`,
   `DISPATCH_COMMISSION_PCT`.
6. **`web/js/api.js`** : remplacez l'URL de l'API par votre vrai sous-domaine si différent, puis
   déployez le contenu de `web/` sur la racine du domaine principal (SFTP ou `git clone` +
   configuration du répertoire web dans le panneau IONOS).
7. **SSL** : vérifiez qu'IONOS a bien émis un certificat (Let's Encrypt, généralement automatique)
   sur les deux domaines — nécessaire pour les webhooks CinetPay et pour servir le front en HTTPS.
8. **Webhook CinetPay** : dans le tableau de bord CinetPay (menu Intégrations), renseignez
   `https://api-ayo.jobivoire.com/api/v1/webhooks/cinetpay` comme URL de notification si elle
   n'est pas déjà déduite automatiquement de `API_URL` (voir `PaymentController`/`PayoutService`,
   qui construisent cette URL depuis `.env`).

Pas de service à faire tourner en arrière-plan (pas de queue, pas de WebSocket dans ce squelette)
— l'hébergement mutualisé classique (PHP-FPM + Apache, sur requête) suffit tel quel.

## 7. Tests

Deux suites, sans dépendance à installer :

```bash
php api/tests/run.php        # prix, validation d'entrées, transitions de statut
cd web && npm test           # échappement HTML, panier, libellés de statut
```

Avant chaque commit, la vérification qui aurait évité la panne de la fiche commerce :

```bash
for f in $(find api/src api/public -name '*.php'); do php -l "$f"; done
for f in $(find web/js -name '*.js'); do node --check "$f"; done
```

Tests de fumée, feuille de route et points de vigilance : **`TESTING.md`**.
Revue de sécurité et arbitrages restants : **`SECURITY-REVIEW.md`**.

## 8. Architecture du front

Pas de build, pas de framework : modules ES natifs servis tels quels.

```
web/css/app.css          tokens (:root) + base du design « néon sombre » + écrans
web/css/components.css   couche composants : états, toasts, boutons, modales, a11y
web/css/backoffice.css   layout desktop du back-office uniquement
web/js/format.js         échappement HTML, URL d'image sûres, formatage monétaire
web/js/status.js         libellés de statut — source unique, partagée par tous les écrans
web/js/ui.js             toasts, états chargement/vide/erreur, modale accessible
web/js/api.js            client HTTP : jeton, idempotence, expiration, nouvel essai
web/js/pages/*.js        un module par écran, rien de partagé en double
```

Deux règles de contribution :

1. **Aucune valeur brute dans une page.** Couleurs, espacements, rayons et tailles de texte
   viennent du bloc `:root` d'`app.css`. Une couleur écrite en dur est un token manquant.
2. **Le navigateur n'invente ni prix ni note.** Tout montant affiché vient du serveur
   (`POST /orders/quote` pour le panier). Toute note vient des avis réels : un commerce sans
   avis affiche « Nouveau sur Ayo », jamais une valeur de repli.

## Ce qui est fait / pas fait

Fait : inscription/connexion (JWT), liste + fiche + menu restaurant, création de restaurant,
gestion du menu par le restaurateur, création de commande (prix recalculés serveur, idempotence),
transitions de statut par rôle, un livreur qui "prend" une commande prête (`/orders/{id}/claim`),
file d'attente temps réel du back-office (`/restaurant/orders/live`), enregistrement du compte
mobile money (restaurant + livreur), paiement CinetPay (page hébergée), webhook signé, split des
virements mobile money à la livraison, vérification KYC des livreurs (upload de pièce
d'identité, revue/approbation par un panel admin minimal).

Ajouté depuis : calcul de commande unifié côté serveur (`POST /orders/quote`), coupons
validés et consommés en base, état de paiement porté par la commande, KYC obligatoire pour
livrer, plafonnement des tentatives de connexion, durée de session absolue, design system
centralisé et couche de composants partagée, deux suites de tests exécutables.

Pas fait (sprints suivants, voir le document d'architecture §9) : diffusion temps réel via
Soketi/WebSocket, dispatch automatique par proximité avec géolocalisation Redis, refresh token
avec révocation réelle, confirmation de livraison par code. Les points restants sont listés,
avec leur sévérité, dans `SECURITY-REVIEW.md` §3.
