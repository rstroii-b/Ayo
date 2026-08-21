-- Données de démonstration — 6 commerces à Abidjan (3 restaurants + mode + meubles + épicerie),
-- avec photos (web/assets/shops, dishes, products), descriptions et ingrédients pour les plats.
-- Idempotent : repart d'un état propre (supprime les anciennes données de démo/test avant de réinsérer).
-- À exécuter après schema.sql : mysql -u root saveurs --default-character-set=utf8mb4 < database/seed_demo.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM order_events;
DELETE FROM order_items;
DELETE FROM payouts;
DELETE FROM orders;
DELETE FROM item_options;
DELETE FROM menu_items;
DELETE FROM menu_categories;
DELETE FROM restaurants;
DELETE FROM driver_locations;
DELETE FROM driver_profiles;
DELETE FROM push_subscriptions;
DELETE FROM webauthn_credentials;
DELETE FROM webauthn_challenges;
DELETE FROM users;

ALTER TABLE users AUTO_INCREMENT = 1;
ALTER TABLE restaurants AUTO_INCREMENT = 1;
ALTER TABLE menu_categories AUTO_INCREMENT = 1;
ALTER TABLE menu_items AUTO_INCREMENT = 1;

SET FOREIGN_KEY_CHECKS = 1;

-- Mot de passe pour tous les comptes de démo : Demo1234!
-- (hash bcrypt généré via php -r "echo password_hash('Demo1234!', PASSWORD_BCRYPT);")
SET @pwd = '$2y$12$oFis2OrLy2LG.L28I5nXXef43uUMRXxOLcI.95.w/MwAAvA.Qj/..';

-- ---------------------------------------------------------------
-- Comptes
-- ---------------------------------------------------------------

INSERT INTO users (id, email, phone, password_hash, first_name, last_name, role) VALUES
(1, 'aicha@example.com',    '+2250701020304', @pwd, 'Aïcha',  'Diallo',   'client'),
(2, 'moussa@example.com',   '+2250701020305', @pwd, 'Moussa', 'Koné',     'driver'),
(3, 'chezayo@example.com',  '+2250701020306', @pwd, 'Ama',    'Yao',      'restaurant_owner'),
(4, 'babibouffe@example.com','+2250701020307', @pwd, 'Fatou',  'Traoré',   'restaurant_owner'),
(5, 'maquis@example.com',   '+2250701020308', @pwd, 'Ibrahim','Coulibaly','restaurant_owner'),
(6, 'ayomode@example.com',  '+2250701020309', @pwd, 'Nadège', 'Kouassi',  'restaurant_owner'),
(7, 'ayomeubles@example.com','+2250701020310', @pwd, 'Serge',  'Aka',      'restaurant_owner'),
(8, 'ayomarche@example.com','+2250701020311', @pwd, 'Mariam', 'Bamba',    'restaurant_owner');

INSERT INTO driver_profiles (user_id, statut_juridique, mobile_money_operator, mobile_money_number, vehicule_type, zone_id, is_online, kyc_status)
VALUES (2, 'auto_entrepreneur', 'WAVE_CI', '+2250701020305', 'scooter', 2, 1, 'verified');

-- ---------------------------------------------------------------
-- Commerces (zone_id 2 = Abidjan / XOF)
-- ---------------------------------------------------------------

INSERT INTO restaurants
  (id, owner_id, zone_id, name, slug, siret, adresse, lat, lng, cuisine_origine, photo_url, business_type, delivery_mode, commission_pct)
VALUES
(1, 3, 2, 'Chez Ayo',        'chez-ayo',        '11100000000001', 'Rue des Jardins, Cocody, Abidjan',    5.3600, -3.9800, 'Côte d''Ivoire', '/assets/shops/banner-chez-ayo.jpg',        'food',      'instant',   20.00),
(2, 4, 2, 'Babi Bouffe',     'babi-bouffe',     '11100000000002', 'Boulevard Giscard d''Estaing, Marcory, Abidjan', 5.2926, -3.9836, 'Côte d''Ivoire', '/assets/shops/banner-babi-bouffe.jpg',     'food',      'instant',   20.00),
(3, 5, 2, 'Maquis d''Abidjan','maquis-abidjan', '11100000000003', 'Rue Princesse, Yopougon, Abidjan',    5.3450, -4.0850, 'Sénégal',       '/assets/shops/banner-maquis-abidjan.jpg',  'food',      'instant',   20.00),
(4, 6, 2, 'Ayo Mode',        'ayo-mode',        '11100000000004', 'Avenue Chardy, Plateau, Abidjan',     5.3197, -4.0242, NULL,            '/assets/shops/banner-fashion.jpg',         'fashion',   'instant',   15.00),
(5, 7, 2, 'Ayo Meubles',     'ayo-meubles',     '11100000000005', 'Boulevard Latrille, Cocody, Abidjan', 5.3690, -3.9950, NULL,            '/assets/shops/banner-furniture.jpg',       'furniture', 'scheduled', 12.00),
(6, 8, 2, 'Ayo Marché',      'ayo-marche',      '11100000000006', 'Rue 12, Treichville, Abidjan',        5.2896, -4.0074, NULL,            '/assets/shops/banner-grocery.jpg',         'grocery',   'instant',   12.00);

-- ---------------------------------------------------------------
-- Catégories
-- ---------------------------------------------------------------

INSERT INTO menu_categories (id, restaurant_id, name, sort_order) VALUES
(1, 1, 'Plats',        0),
(2, 1, 'Boissons',     1),
(3, 2, 'Plats',        0),
(4, 3, 'Plats',        0),
(5, 4, 'Vêtements',    0),
(6, 4, 'Chaussures & accessoires', 1),
(7, 5, 'Salon',        0),
(8, 5, 'Luminaires',   1),
(9, 6, 'Épicerie',     0);

-- ---------------------------------------------------------------
-- Articles — restaurants (description + ingrédients)
-- ---------------------------------------------------------------

INSERT INTO menu_items (restaurant_id, category_id, name, description, ingredients, price_cents, vat_rate, photo_url) VALUES
(1, 1, 'Alloco poulet',      'Bananes plantains frites et poulet braisé, servis avec une sauce tomate maison légèrement épicée.', 'Banane plantain, Poulet, Tomate, Oignon, Piment, Ail, Huile de palme', 200000, 10.00, '/assets/dishes/alloco.jpg'),
(1, 1, 'Attiéké poisson',    'Semoule de manioc fermentée accompagnée de poisson grillé et de légumes frais.', 'Attiéké (manioc), Poisson (bar ou dorade), Tomate, Oignon, Piment, Citron', 250000, 10.00, '/assets/dishes/attieke.jpg'),
(1, 2, 'Bissap',             'Boisson rafraîchissante à base de fleurs d''hibiscus, sucrée et parfumée à la menthe.', 'Fleurs d''hibiscus, Sucre, Menthe, Eau', 50000, 10.00, NULL),

(2, 3, 'Thiéboudienne',      'Riz au poisson façon sénégalaise, mijoté avec légumes et sauce tomate — le plat signature de Babi Bouffe.', 'Riz brisé, Poisson, Tomate, Carotte, Chou, Manioc, Aubergine, Ail, Piment', 250000, 10.00, '/assets/dishes/thieboudienne.jpg'),
(2, 3, 'Pastels au thon',    'Beignets croustillants farcis au thon épicé, parfaits en accompagnement ou en snack.', 'Farine de blé, Thon, Oignon, Persil, Piment, Huile de friture', 100000, 10.00, '/assets/dishes/pastels.jpg'),

(3, 4, 'Yassa poulet',       'Poulet mariné au citron et oignons confits, mijoté à la sénégalaise, servi avec du riz blanc.', 'Poulet, Citron, Oignon, Moutarde, Ail, Piment, Riz blanc', 250000, 10.00, '/assets/dishes/yassa-poulet.jpg'),
(3, 4, 'Mafé',                'Ragoût de bœuf à la pâte d''arachide, mijoté longuement avec légumes racines.', 'Bœuf, Pâte d''arachide, Tomate, Oignon, Carotte, Chou, Piment', 250000, 10.00, '/assets/dishes/mafe.jpg');

-- ---------------------------------------------------------------
-- Articles — mode, meubles, épicerie (description, sans ingrédients)
-- ---------------------------------------------------------------

INSERT INTO menu_items (restaurant_id, category_id, name, description, price_cents, vat_rate, photo_url) VALUES
(4, 5, 'Robe wax',           'Robe imprimée en tissu wax authentique, coupe cintrée, doublure intérieure.', 1500000, 18.00, '/assets/products/dress.jpg'),
(4, 5, 'T-shirt imprimé',    'T-shirt 100% coton avec motif graphique, coupe unisexe.', 600000, 18.00, '/assets/products/tshirt.jpg'),
(4, 6, 'Sneakers',           'Baskets urbaines confortables, semelle amortissante, plusieurs coloris.', 2500000, 18.00, '/assets/products/sneakers.jpg'),
(4, 6, 'Sac à main',         'Sac en cuir synthétique, compartiment principal et poche zippée.', 1200000, 18.00, '/assets/products/bag.jpg'),

(5, 7, 'Fauteuil',           'Fauteuil confortable en tissu, structure bois massif, idéal salon ou bureau.', 4500000, 18.00, '/assets/products/chair.jpg'),
(5, 7, 'Table basse',        'Table basse en bois avec plateau en verre trempé, finition moderne.', 3000000, 18.00, '/assets/products/table.jpg'),
(5, 8, 'Lampe de salon',     'Lampadaire au design épuré, lumière chaude, idéal pour un coin lecture.', 1200000, 18.00, '/assets/products/lamp.jpg'),

(6, 9, 'Riz parfumé (5kg)',  'Sac de riz parfumé de qualité supérieure, cuisson rapide.', 450000, 18.00, '/assets/products/rice.jpg'),
(6, 9, 'Pain complet',       'Pain frais du jour, cuit sur place chaque matin.', 50000, 18.00, '/assets/products/bread.jpg'),
(6, 9, 'Lait (1L)',          'Lait entier UHT, conditionné en brique d''un litre.', 80000, 18.00, '/assets/products/milk.jpg'),
(6, 9, 'Légumes frais',      'Panier de légumes de saison, sélectionnés localement.', 200000, 18.00, '/assets/products/vegetables.jpg');
