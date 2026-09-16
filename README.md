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

   `api/storage/kyc/` (pièces d'identité livreurs) et `api/storage/logs/` (journalisation, §7)
   sont créés automatiquement au premier usage — vérifiez simplement que l'utilisateur PHP-FPM a
   le droit d'écrire dans `api/` sur l'hébergement. Si `storage/logs/` n'est pas inscriptible,
   les logs basculent sur `stderr` plutôt que de faire échouer les requêtes.

   Sur une base **déjà en service**, appliquez aussi la migration de supervision :

   ```bash
   mysql -h <host> -u <user> -p <nom_base> < ../database/migrations/2026_09_16_transaction_monitoring.sql
   ```

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

9. **Cron de réconciliation** (panneau IONOS → tâches planifiées, ou `crontab -e` en SSH) :

   ```bash
   */10 * * * * php /chemin/absolu/vers/api/bin/reconcile.php >> /dev/null 2>&1
   ```

   Sans lui, un webhook CinetPay perdu laisse une commande réellement payée en `unpaid` (voir §7).

À part ce cron, pas de service à faire tourner en arrière-plan (pas de queue, pas de WebSocket
auto-hébergé) — l'hébergement mutualisé classique (PHP-FPM + Apache, sur requête) suffit tel quel.

## 7. Logs et supervision des transactions

### Journalisation

Deux fichiers JSON (une ligne par entrée) dans `api/storage/logs/`, hors de la racine web et
déjà ignorés par git :

| Fichier | Contenu | Rétention |
|---|---|---|
| `app-YYYY-MM-DD.log` | exploitation : exceptions non rattrapées, webhooks refusés, panne du temps réel, jetons rejetés | `LOG_MAX_FILES` (14 jours) |
| `transactions-YYYY-MM-DD.log` | piste d'audit financière : un événement par mouvement d'argent | `LOG_TRANSACTIONS_MAX_FILES` (400 jours) |

Chaque ligne porte un `request_id` qui relie toutes les traces d'une même requête (repris de
l'en-tête `X-Request-Id` si un reverse-proxy en pose un). Les détails d'erreur ne sont **jamais**
renvoyés au client : `APP_DEBUG=false` en production, la trace complète ne vit que dans le log.

```bash
# les mouvements d'argent du jour
cat api/storage/logs/transactions-$(date +%F).log | jq -r '"\(.message) \(.context)"'
# les virements en échec
grep payout.failed api/storage/logs/transactions-*.log
```

### Écran de supervision

`admin-transactions.html` (rôle admin) affiche en direct : encaissements du jour, paiements
échoués, commandes non encaissées depuis plus de 30 min, virements en attente et en échec, et
l'état de configuration de CinetPay / du temps réel / du push. Chaque commande donne accès à sa
piste d'audit complète (`order_events` + virements déclenchés).

La page se met à jour instantanément via le canal Pusher privé `private-admin` — sur lequel
`PaymentLedger` et `PayoutService` diffusent chaque paiement et chaque virement — avec un
rafraîchissement toutes les 20 s en filet de sécurité, comme le reste de l'app.

Endpoints correspondants (tous en `admin` sauf le dernier) :

```
GET /api/v1/admin/metrics                 compteurs du tableau de bord
GET /api/v1/admin/transactions?payment_status=paid|unpaid|failed
GET /api/v1/admin/payouts?statut=failed|pending|sent
GET /api/v1/admin/orders/{id}/events      piste d'audit d'une commande
GET /api/v1/health                        sonde publique (200 / 503) pour un monitor externe
```

### État de paiement

`orders.payment_status` (`unpaid` / `paid` / `failed`) et `orders.paid_at` répondent directement
à « cette commande est-elle payée ? ». Avant, la seule trace était une ligne dans `order_events`
que rien ne relisait. L'état est visible côté client (suivi de commande) et côté restaurateur
(pastille sur la carte du kanban, mise à jour en temps réel).

Sur une base déjà installée, appliquer la migration une seule fois — elle recalcule l'historique
depuis `order_events` :

```bash
mysql -u root saveurs < database/migrations/2026_09_16_transaction_monitoring.sql
```

### Réconciliation (cron)

Un webhook CinetPay peut se perdre (coupure réseau, déploiement en cours). `api/bin/reconcile.php`
redemande à CinetPay l'état réel des paiements restés `unpaid` et des virements restés `pending`,
puis régularise ce qui doit l'être :

```bash
*/10 * * * * php /chemin/vers/api/bin/reconcile.php >> /dev/null 2>&1
```

Le script laisse d'abord au webhook le temps de faire son travail (`RECONCILE_GRACE_MINUTES`) et
ignore les transactions trop anciennes (`RECONCILE_LOOKBACK_DAYS`). Un rattrapage est journalisé en
`warning` (`reconcile.missed_payment_webhook`) : plusieurs occurrences signalent un webhook mal
configuré côté CinetPay, pas une fatalité à absorber par le cron.

Toutes les transitions passent par `PaymentLedger`, qui conditionne chaque changement d'état à
l'état de départ : un webhook rejoué par CinetPay et le cron peuvent arriver en même temps sur la
même transaction sans produire de doublon ni de double comptabilisation.

## Ce qui est fait / pas fait

Fait : inscription/connexion (JWT), liste + fiche + menu restaurant, création de restaurant,
gestion du menu par le restaurateur, création de commande (prix recalculés serveur, idempotence),
transitions de statut par rôle, un livreur qui "prend" une commande prête (`/orders/{id}/claim`),
file d'attente temps réel du back-office (`/restaurant/orders/live`), enregistrement du compte
mobile money (restaurant + livreur), paiement CinetPay (page hébergée), webhook signé, split des
virements mobile money à la livraison, vérification KYC des livreurs (upload de pièce
d'identité, revue/approbation par un panel admin minimal), journalisation structurée + piste
d'audit financière, supervision admin des transactions en temps réel, réconciliation CinetPay
par cron (voir §7).

Pas fait (sprints suivants, voir le document d'architecture §9) : diffusion temps réel via
Soketi/WebSocket (le front doit recharger pour voir un changement de statut), dispatch
automatique par proximité (le livreur "prend" une commande manuellement, pas de géolocalisation
Redis), refresh token avec révocation réelle.
