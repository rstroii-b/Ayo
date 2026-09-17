# Ayo — tests et vérifications

Ce document décrit ce qui est **automatisé aujourd'hui**, ce qui doit être **vérifié à la main
avant une mise en ligne**, et la **feuille de route** des tests restant à écrire.

---

## 1. Ce qui tourne aujourd'hui

Deux suites, aucune dépendance à installer, aucune base de données requise.

### Tests PHP (logique métier serveur)

```bash
php api/tests/run.php              # toute la suite
php api/tests/run.php Pricing      # filtre par nom de fichier
```

| Fichier | Couvre | Pourquoi c'est là |
|---|---|---|
| `api/tests/PricingServiceTest.php` | frais de livraison, distance, TVA par ligne, remises, total | une erreur ici coûte de l'argent réel, au client ou au commerçant |
| `api/tests/ValidatorTest.php` | types, bornes, listes, URL, codes promo | fige le refus des entrées mal typées qui produisaient des 500 |
| `api/tests/OrderTransitionsTest.php` | matrice des transitions de statut | `delivered` déclenche les virements : qui peut l'atteindre est une règle d'argent |

Le lanceur (`api/tests/run.php`) est volontairement minimal : il tourne sur la machine
d'hébergement même sans `composer install`, c'est-à-dire là où on en a le plus besoin, juste
avant un déploiement. Le jour où la suite devra couvrir les contrôleurs (donc PDO et PSR-7),
PHPUnit reprendra la main — les fichiers de test gardent la même structure.

### Tests JavaScript (logique front)

```bash
cd web && npm test                 # node --test, intégré à Node ≥ 18
```

| Fichier | Couvre |
|---|---|
| `web/tests/format.test.js` | `escapeHtml`, `safeImageUrl`, `formatMoney` — les deux barrières anti-XSS du front |
| `web/tests/cart.test.js` | règles du panier, variantes, changement de commerce, `localStorage` corrompu |
| `web/tests/status.test.js` | libellés de statut, correspondance avec l'ENUM SQL |

`web/package.json` ne sert qu'à indiquer à Node que les `.js` sont des modules ES. **Aucune
dépendance n'est installée, et le front reste sans build.**

### Vérifications de syntaxe

À lancer avant tout commit — elles auraient attrapé la panne décrite au §4 :

```bash
for f in $(find api/src api/public -name '*.php'); do php -l "$f"; done
for f in $(find web/js -name '*.js'); do node --check "$f"; done
```

---

## 2. Tests de fumée (5 minutes, avant chaque mise en ligne)

À faire sur un téléphone réel, pas seulement dans le simulateur du navigateur.

| # | Parcours | Attendu |
|---|---|---|
| 1 | Ouvrir l'accueil sans être connecté | la liste des commerces s'affiche, les squelettes disparaissent |
| 2 | Chercher « poulet », puis effacer | les résultats suivent la frappe, aucun résultat périmé ne réapparaît |
| 3 | Ouvrir une fiche commerce | le menu s'affiche, les prix sont lisibles, **la note affichée correspond aux vrais avis** |
| 4 | Ajouter un article avec variante | le panneau de variantes s'ouvre, le bouton affiche le prix, un toast confirme |
| 5 | Ouvrir le panier | **le total affiché est complet : articles + livraison + TVA** |
| 6 | Passer de Standard à Express | le total change, sans jamais rester figé sur l'ancien montant |
| 7 | Saisir un code promo invalide | message explicite, total inchangé |
| 8 | Saisir un code promo valide | la ligne de remise apparaît, le total baisse |
| 9 | Valider la commande | redirection vers le paiement, panier vidé |
| 10 | Suivre la commande | statut, étapes et montants cohérents avec l'écran précédent |
| 11 | Back-office : accepter une commande | elle change de colonne, le client reçoit la notification |
| 12 | Application livreur sans KYC validé | le passage en ligne est refusé **avec un message**, pas en silence |

**Point de vigilance n°5 et n°9** : c'est exactement là que l'écart entre montant affiché et
montant débité se produisait. Si le total du panier et celui de la page de paiement diffèrent,
ne pas mettre en ligne.

---

## 3. Feuille de route des tests à écrire

Classés par valeur décroissante, pas par facilité.

### 3.1 Tests d'API (priorité haute)

Nécessitent une base de test et un jeu de données (`database/seed_demo.sql`). Outil suggéré :
PHPUnit + un client HTTP, ou une collection Hurl/Bruno versionnée dans `api/tests/http/`.

| Endpoint | Cas à couvrir |
|---|---|
| `POST /orders/quote` | total identique à celui de `POST /orders` pour le même panier — **le test le plus important de la suite** |
| `POST /orders` | prix repris en base et non ceux envoyés ; quantité hors bornes → 422 ; article d'un autre commerce → 422 ; variante d'un autre article → 422 |
| `POST /orders` | même `Idempotency-Key` deux fois → une seule commande ; clé d'un autre compte → 409, jamais la commande de l'autre |
| `POST /orders` | coupon expiré / déjà utilisé / sous le minimum → 422 avec le motif |
| `POST /orders` | coupon à usage unique consommé deux fois en parallèle → une seule réussite |
| `PATCH /orders/{id}/status` | chaque transition interdite → 409 ; double appel simultané → une seule réussite |
| `PATCH /orders/{id}/claim` | livreur sans KYC validé → 403 |
| `GET /orders/{id}` | un tiers → 404 ; la réponse **ne contient ni `cinetpay_notify_token` ni `idempotency_key`** |
| `GET /driver/orders/available` | la réponse ne contient pas l'adresse exacte du client |
| `POST /auth/login` | 8 échecs → 429 avec `Retry-After` ; un succès remet le compteur à zéro |
| `PATCH /restaurants/{id}/menu/items/{itemId}` | prix négatif, TVA à 900, `photo_url` en `javascript:` → 422 |
| `POST /webhooks/cinetpay` | notification rejouée → un seul événement, un seul passage à `paid` |

### 3.2 Tests de bout en bout (priorité moyenne)

Playwright, sur Chromium mobile émulé **et** un appareil réel.

1. Parcours client complet : accueil → fiche → panier → checkout → suivi.
2. Parcours restaurateur : réception de commande → acceptation → préparation → prête.
3. Parcours livreur : mise en ligne → prise de course → livraison.
4. Panier vidé au changement de commerce, avec confirmation visible.
5. Session expirée en plein checkout → redirection vers la connexion, panier conservé.

### 3.3 Tests unitaires supplémentaires (priorité moyenne)

- `CouponService::evaluate` — chaque motif de refus (nécessite une base, ou une injection de PDO).
- `PayoutService` — répartition commerçant / livreur, commissions, arrondis.
- `KycStorage` — refus d'un fichier trop gros, d'un type non autorisé, d'un nom piégé.
- `web/js/api.js` — nouvel essai sur coupure réseau, respect de la clé d'idempotence, expiration.

### 3.4 Accessibilité et responsive (priorité moyenne)

- axe-core sur les six écrans principaux, zéro violation bloquante.
- Navigation entière au clavier : la modale produit doit piéger le focus et se fermer à Échap.
- Contraste vérifié sur le thème sombre (`--ink-dim` sur `--surface` est le couple limite).
- Cibles tactiles ≥ 44 px : boutons `+`/`−` du panier, bouton d'ajout, fermeture de modale.
- Rendu à 320 px de large (petit Android) sans débordement horizontal.
- `prefers-reduced-motion` activé : aucune animation résiduelle.

### 3.5 Charge et robustesse (priorité basse, avant montée en charge)

- 50 commandes simultanées sur la même variante à stock limité → jamais de survente.
- Coupure réseau pendant le checkout → aucune commande en double.
- Base indisponible → 500 propre, aucune trace d'exception renvoyée au client.

---

## 4. Ce qu'un contrôle de syntaxe aurait évité

`web/js/pages/restaurant.js` contenait, depuis le commit `0652c95`, une expression régulière
écrite sur deux lignes :

```js
const items = ingredients.split(/[,
]/)
```

Un retour à la ligne réel dans une expression régulière est une **erreur de syntaxe
JavaScript**. Le module entier ne se chargeait donc pas : la fiche commerce restait bloquée sur
« Chargement… », menu compris, sans message d'erreur — l'exception se produit au parsing, avant
qu'aucun code ne s'exécute. La page était hors service sur la branche depuis ce commit.

Le correctif est d'une ligne (`/[,\n]/`). La leçon ne l'est pas : **`node --check` sur chaque
fichier modifié, avant chaque commit.** Une seule commande aurait suffi.
