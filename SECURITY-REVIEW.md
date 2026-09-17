# Ayo — revue de sécurité et de logique métier

Revue du dépôt à la date de la refonte. Chaque point indique ce qui a été constaté, l'impact
réel, et ce qui a été fait. Les points marqués **ouvert** demandent une décision ou un travail
qui dépasse cette passe : ils ne sont pas oubliés, ils sont à arbitrer.

Sévérité : **critique** (argent ou données personnelles en jeu), **élevée**, **moyenne**, **faible**.

---

## 1. Corrigé dans cette passe

### 1.1 — Fuite du secret de vérification des paiements · critique

`GET /orders/{id}` exécutait `SELECT o.*`. La table `orders` porte `cinetpay_notify_token` :
le jeton qui sert à vérifier l'authenticité des webhooks CinetPay. Il était donc renvoyé au
client, au restaurateur **et** au livreur de chaque commande. Avec ce jeton, forger une
confirmation de paiement pour une commande jamais payée devient possible.

`idempotency_key` fuyait par le même chemin.

**Corrigé** — liste de colonnes explicite (`OrderController::ORDER_COLUMNS`). Les deux champs
ne sortent plus de l'API. *Un `SELECT *` sur une table qui contient un secret est une fuite en
attente ; la liste explicite n'est pas du zèle.*

### 1.2 — Le total affiché n'était pas le total facturé · critique

`web/js/pages/panier.js` calculait lui-même les frais de livraison
(`DELIVERY_FEES = { standard: 1500, express: 3000 }`) et les remises
(`PROMO_CODES = { AYO10: 0.10, … }`), écrits en dur dans le JavaScript.

Le serveur, lui, calcule les frais depuis la distance et la zone (minimum 1 000 FCFA autour
d'Abidjan) et **ignorait totalement** `promo_code` et `delivery_mode`, pourtant envoyés par le
front et présents en base depuis la migration 001.

Conséquences : l'écran annonçait 15 FCFA de livraison là où 1 000 étaient facturés, et la ligne
« −10 % » n'existait que dans le navigateur — la commande partait au prix plein.

**Corrigé** — nouvel endpoint `POST /orders/quote` et service `PricingService`, partagés par
l'aperçu et la création : un seul calcul, donc aucun écart possible. Le panier affiche la
réponse du serveur et n'additionne plus rien. Les coupons sont validés côté serveur
(`CouponService`), consommés dans la transaction de commande, et `promo_code` / `discount_cents`
/ `delivery_mode` sont enfin persistés.

### 1.3 — Une commande pouvait être livrée sans avoir été payée · critique

Un paiement confirmé n'écrivait qu'une ligne dans `order_events`. Personne ne la relisait. Une
commande pouvait donc être acceptée, préparée, livrée, et **déclencher les virements** vers le
commerçant et le livreur sans qu'aucun encaissement n'ait abouti.

**Corrigé** — colonnes `orders.payment_status` / `paid_at` (migration 002), renseignées par le
webhook de façon idempotente. `PATCH /orders/{id}/status` refuse le passage en `accepted` d'une
commande non payée **lorsque l'encaissement en ligne est configuré** ; sur une instance sans
identifiants CinetPay (démo, recette), le contrôle est inactif — sinon toutes les commandes
resteraient figées en `pending`. La migration reprend l'historique pour ne rien bloquer au
déploiement.

### 1.4 — Clé d'idempotence non rattachée au compte · élevée

`SELECT … FROM orders WHERE idempotency_key = ?`, sans filtre sur le client. Présenter la clé
d'un autre suffisait à récupérer l'identifiant, le statut et le montant de sa commande.

**Corrigé** — filtre `AND client_id = ?`, et collision avec un autre compte traitée en 409
(la contrainte d'unicité est globale) plutôt qu'en 500.

*Bonus :* le front **ignorait la clé stable** que `panier.js` conservait en `sessionStorage`
précisément pour éviter les doublons — `apiFetch` en générait une neuve à chaque appel,
annulant la protection. La clé fournie par l'appelant est désormais respectée.

### 1.5 — Entrées non validées côté serveur · élevée

Le body JSON partait directement vers PDO et vers les calculs de prix :

- `items` vide → `IN ()` → erreur SQL → 500 ;
- `items` de 100 000 entrées → déni de service par construction de requête ;
- `delivery_address.lat` absente → `(float) null` = `0.0` → distance calculée depuis le golfe
  de Guinée → frais de livraison délirants ;
- `quantity: -5` → `max(1, …)` → silencieusement transformé en 1 ;
- `{"name": ["x"]}` → erreur PDO en 500 bavarde ;
- `note` sans limite de longueur → troncature ou erreur SQL.

**Corrigé** — `Support\Validator` (typé, borné, testé : `api/tests/ValidatorTest.php`) appliqué
à `OrderController`, `MenuController`, `RestaurantController`, `DriverController`,
`ConnectController`, `PaymentController`, `AuthController`. Les refus sont des 422 nommant le
champ fautif, exploitables par le front.

### 1.6 — Le commerçant pouvait fixer des valeurs aberrantes · élevée

`price_cents`, `vat_rate`, `price_delta_cents` et `stock_quantity` n'étaient bornés par rien.
Un taux de TVA à 900 gonflait la facture de toute commande contenant l'article ; un
`price_delta_cents` très négatif sur une variante revenait à s'accorder une remise permanente.

**Corrigé** — bornes explicites dans `MenuController`, et prix unitaire plancher à 0 dans le
moteur de prix : un cumul de variantes négatives ne peut plus rendre une ligne créditrice.

### 1.7 — Livreurs non vérifiés · élevée

Le rôle `driver` s'obtenait en remplissant le formulaire d'inscription. Rien n'imposait ensuite
un KYC validé : un compte créé en trente secondes pouvait passer en ligne, entrer dans la file
de dispatch, recevoir les notifications et **prendre une course** — donc obtenir l'adresse d'un
client. L'écran affichait « identité non vérifiée » ; l'API ne l'imposait pas.

**Corrigé** — KYC vérifié exigé pour `PATCH /driver/status` (passage en ligne),
`PATCH /orders/{id}/claim` et l'accès à la file des courses. Un nouveau document ou un refus
repasse le livreur hors ligne. Le dispatch ne notifie plus que des livreurs vérifiés.

### 1.8 — Adresses clients exposées à tous les livreurs · élevée

`GET /driver/orders/available` renvoyait `adresse_livraison` en clair pour **toutes** les
commandes prêtes. N'importe quel compte livreur consultait en continu l'adresse précise de tous
les clients de la ville, sans jamais livrer.

**Corrigé** — la liste publique des courses ne montre que la zone (« Cocody, Abidjan »).
L'adresse exacte n'apparaît qu'après acceptation, dans `GET /driver/orders/active`.
Le téléphone du livreur n'est communiqué au client que pendant la course.

### 1.9 — Aucune limite sur la connexion · élevée

`POST /auth/login` n'avait aucun plafond : un script pouvait tester des mots de passe aussi
vite que le serveur répondait.

**Corrigé** — `Support\RateLimiter` (table `auth_attempts`, migration 002) : 8 échecs par
identifiant et 30 par IP sur 15 minutes, réponse 429 avec `Retry-After`, compteur remis à zéro
au succès. Un haché factice de même coût bcrypt est comparé quand l'email n'existe pas, pour
que le temps de réponse ne révèle plus l'existence d'un compte.

### 1.10 — Session éternelle · élevée

`POST /auth/refresh` réémettait un jeton tant que le précédent était valide. Un jeton volé
restait donc exploitable indéfiniment : il suffisait de le rafraîchir toutes les 25 minutes.

**Corrigé** — claim `sid_iat` figeant l'ouverture de session, jamais repoussé lors d'un
rafraîchissement, borné par `JWT_SESSION_MAX_DAYS`. Le rôle est relu en base au
rafraîchissement : un compte rétrogradé ne conserve plus ses anciens droits.

### 1.11 — XSS par la gestion d'erreur · moyenne

Onze écrans faisaient `container.innerHTML = '<p>' + error.message + '</p>'`. Le message vient
de l'API, donc parfois d'une donnée saisie par un tiers (nom de commerce, libellé d'article,
motif de refus KYC). Le chemin le plus discret vers un XSS est précisément celui-là : personne
ne relit la branche d'erreur.

**Corrigé** — `web/js/ui.js` centralise les états (chargement / vide / erreur) et échappe tout.
Aucune interpolation non échappée ne subsiste.

### 1.12 — URL de photo non contrôlée · moyenne

`photo_url` était accepté tel quel et posé dans un `background-image` côté front.

**Corrigé** — `https://` exclusivement, validé côté serveur (`Validator::optionalHttpsUrl`)
**et** côté front (`safeImageUrl`, déjà présent). Deux barrières, car chacune peut être
contournée par un chemin que l'autre ne couvre pas.

### 1.13 — CORS ouvert par défaut · moyenne

`Access-Control-Allow-Origin: $_ENV['CORS_ORIGIN'] ?? '*'`. Sur une instance où la variable
n'est pas définie, l'API répondait à n'importe quelle page du web.

**Corrigé** — liste blanche explicite, `Vary: Origin`, plus aucun repli en `*`.

### 1.14 — Document d'identité servi en ligne · moyenne

`GET /admin/drivers/{id}/kyc-document` renvoyait le fichier sans `Content-Disposition`. Un PDF
peut embarquer du script, exécuté par le visualiseur intégré sur l'origine de l'API, avec une
session administrateur ouverte.

**Corrigé** — `attachment`, `X-Content-Type-Options: nosniff`, `Cache-Control: no-store`.

### 1.15 — Détails techniques renvoyés au client · moyenne

`JsonResponse::error(…, 502, 'Erreur CinetPay', $e->getMessage())` exposait l'identifiant
marchand, l'URL d'API et parfois le corps de la réponse du prestataire.

**Corrigé** — message générique au client, détail dans les logs serveur.

### 1.16 — Transitions de statut non atomiques · moyenne

`UPDATE orders SET status = ? WHERE id = ?`, sans vérifier l'état de départ. Deux requêtes
simultanées (double-tap, deux onglets) franchissaient deux fois la même étape — et donc
**déclenchaient deux fois les virements** sur `delivered`.

**Corrigé** — `AND status = ?` dans le `UPDATE`, second appel en 409.

### 1.17 — Autres points de cohérence · faible à moyenne

- Opérateur mobile money non validé : une valeur fantaisiste ne se découvrait qu'au moment du
  virement. → liste blanche (`OM_CI`, `MTN_CI`, `MOOV_CI`, `WAVE_CI`).
- `zone_id` accepté sans vérification : un commerçant pouvait se rattacher à une zone à fort
  multiplicateur de pointe. → existence vérifiée.
- `commission_pct` et `owner_id` renvoyés publiquement par `GET /restaurants/{id}`. → retirés
  du contrat public, conservés sur `GET /restaurant/mine`.
- Slug non unique : deux commerces homonymes se disputaient la même URL. → suffixe numérique.
- Jokers SQL `%` et `_` non échappés dans la recherche. → échappés (coût, pas injection).
- Livraison hors zone : aucune limite de distance. → refus au-delà de 40 km, avec message.

---

## 2. Ce qui allait déjà bien

À ne pas casser en refactorisant.

- **Injections SQL** : toutes les requêtes sont préparées et paramétrées. Aucune concaténation
  de valeur utilisateur. Les seuls noms de colonnes interpolés proviennent de listes blanches.
- **CSRF** : l'API est sans état, authentifiée par `Authorization: Bearer`, et **n'utilise
  aucun cookie** (vérifié : aucune occurrence de `cookie` dans `api/src` ni `web/js`). Le
  scénario CSRF classique — le navigateur joint automatiquement l'identifiant de session —
  n'existe donc pas ici : un site tiers ne peut pas faire émettre une requête authentifiée.
  Le risque adjacent réel était le CORS permissif (§1.13) et le vol de jeton par XSS (§1.11),
  tous deux traités. **Ajouter des jetons CSRF ici serait du bruit** ; cela redeviendrait
  nécessaire le jour où l'authentification passerait par cookie.
- **Stockage des pièces d'identité** (`KycStorage`) : hors du webroot, nom généré côté serveur,
  type MIME vérifié sur le contenu réel et non sur l'en-tête déclaré, `basename()` à la
  relecture, taille plafonnée. C'est le composant le mieux écrit du projet.
- **Mots de passe** : `password_hash` / `password_verify`, jamais de comparaison manuelle.
- **Secrets** : aucun secret dans le dépôt. `api/.env` est ignoré par Git, `.env.example` ne
  contient que des valeurs vides ou factices. Le front ne reçoit que la clé publique VAPID,
  qui est publique par nature.
- **Autorisation des canaux temps réel** (`RealtimeController`) : chaque canal privé est
  vérifié contre la même règle d'accès que l'endpoint REST correspondant.
- **WebAuthn** : défis à usage unique, expiration courte, compteur de signature mis à jour.
- **Stock des variantes** : décrément atomique avec `WHERE stock_quantity >= ?`, pas de survente.
- **Middleware d'erreur** : les traces ne sont affichées que si `APP_DEBUG=true`.

---

## 3. Ouvert — à arbitrer

| Point | Sévérité | Détail |
|---|---|---|
| Révocation de session | élevée | Il n'existe aucun moyen d'invalider un jeton avant son expiration. « Déconnecter mes autres appareils » est impossible. Demande une table de refresh tokens ou une liste de révocation. La durée absolue (§1.10) réduit la fenêtre, elle ne la ferme pas. |
| Confirmation de livraison | élevée | Le livreur déclare seul « livrée », ce qui déclenche les virements. Aucun code de remise, aucune vérification de proximité. Un livreur peut être payé sans livrer. Correctif classique : code à 4 chiffres communiqué au client. |
| Changement de compte mobile money | élevée | `PATCH /connect/mobile-money` ne redemande pas le mot de passe. Un compte compromis permet de rediriger tous les reversements à venir. Demander une ré-authentification. |
| Dépendances | moyenne | `composer.lock` est versionné mais `vendor/` absent de l'environnement de revue : **aucun audit réel n'a pu être exécuté**. À faire avant mise en ligne : `composer audit`, et `composer outdated --direct`. Ne pas considérer ce point comme traité. |
| Rôle `admin` | moyenne | Aucune route ne crée d'administrateur (correct), mais rien ne documente comment en créer un. Probablement à la main en base : à écrire noir sur blanc. |
| Journal d'audit admin | moyenne | Les décisions KYC ne laissent aucune trace horodatée et nominative. |
| En-tête CSP | moyenne | Absent de `web/.htaccess`. Avec du `innerHTML` partout, une CSP stricte est la meilleure défense en profondeur restante. Demande d'abord de sortir les styles en ligne restants. |
| Plusieurs commerces par compte | faible | `GET /restaurant/mine` et les statistiques prennent `ORDER BY id LIMIT 1`. Un propriétaire de deux commerces ne voit que le premier, sans avertissement. Trancher : un seul commerce par compte, ou un sélecteur. |
| Purge des positions livreurs | faible | `driver_locations` conserve la dernière position sans expiration. Donnée personnelle à purger (RGPD). |
| Rejeu de webhook | faible | Traité de façon idempotente (§1.3), mais aucune vérification d'horodatage : un webhook authentique très ancien serait accepté. |

---

## 4. Ordre recommandé avant mise en ligne

1. Appliquer les migrations `001` puis `002` **sur une copie de la base d'abord**.
2. Renseigner `CORS_ORIGIN` avec l'origine réelle du front — plus aucun repli en `*`.
3. Vérifier que `JWT_SECRET` n'est pas la valeur d'exemple.
4. `composer install && composer audit` (point resté ouvert, §3).
5. Lancer les deux suites de tests (`php api/tests/run.php`, `cd web && npm test`).
6. Dérouler les tests de fumée de `TESTING.md` §2, en particulier les points 5 et 9 : le total
   du panier doit être identique à celui de la page de paiement.
