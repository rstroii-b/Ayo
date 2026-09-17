<?php

declare(strict_types=1);

use Saveurs\Controllers\OrderController;

/**
 * La matrice des transitions de statut est la règle métier la plus sensible du projet : c'est
 * elle qui décide qui peut faire avancer une commande, et donc — via « delivered » — qui peut
 * déclencher les virements vers le commerçant et le livreur.
 *
 * Elle est lue ici par réflexion. Cela évite d'instancier le contrôleur (qui demanderait PDO,
 * PSR-7 et une base) tout en garantissant qu'on teste bien la constante réellement utilisée en
 * production, et non une copie recopiée dans le test qui divergerait au premier changement.
 */

/** @return array<string, array<string, list<string>>> */
function transitions(): array
{
    static $matrix = null;

    if ($matrix === null) {
        $reflection = new ReflectionClass(OrderController::class);
        $matrix = $reflection->getConstant('TRANSITIONS');
    }

    return $matrix;
}

function peut(string $role, string $from, string $to): bool
{
    return in_array($to, transitions()[$role][$from] ?? [], true);
}

describe('Transitions de statut — parcours nominal');

test('le restaurant accepte, prépare et signale la commande prête', function (): void {
    assertTrue(peut('restaurant', 'pending', 'accepted'), 'accepter une commande reçue');
    assertTrue(peut('restaurant', 'accepted', 'preparing'), 'lancer la préparation');
    assertTrue(peut('restaurant', 'preparing', 'ready_for_pickup'), 'signaler la commande prête');
});

test('le livreur récupère, part et livre', function (): void {
    assertTrue(peut('driver', 'ready_for_pickup', 'picked_up'));
    assertTrue(peut('driver', 'picked_up', 'delivering'));
    assertTrue(peut('driver', 'delivering', 'delivered'));
});

test('le client peut annuler tant que le commerce n\'a pas accepté', function (): void {
    assertTrue(peut('client', 'pending', 'cancelled'));
});

describe('Transitions de statut — ce qui doit rester interdit');

test('le client ne peut pas annuler une commande déjà acceptée', function (): void {
    // Sinon le commerce a déjà engagé des ingrédients, et le livreur peut être en route.
    assertTrue(!peut('client', 'accepted', 'cancelled'));
    assertTrue(!peut('client', 'preparing', 'cancelled'));
    assertTrue(!peut('client', 'delivering', 'cancelled'));
});

test('le client ne peut jamais déclarer sa commande livrée', function (): void {
    // « delivered » déclenche les virements : ce serait se faire livrer sans payer le livreur,
    // ou payer le commerce pour une commande jamais partie.
    foreach (array_keys(transitions()['client']) as $from) {
        assertTrue(!peut('client', $from, 'delivered'), "client : {$from} → delivered doit être refusé");
    }
});

test('le restaurant ne peut pas livrer à la place du livreur', function (): void {
    foreach (['pending', 'accepted', 'preparing'] as $from) {
        assertTrue(!peut('restaurant', $from, 'delivered'), "restaurant : {$from} → delivered doit être refusé");
    }
});

test('le livreur ne peut ni accepter ni annuler une commande', function (): void {
    assertTrue(!peut('driver', 'pending', 'accepted'), 'le livreur n\'accepte pas à la place du commerce');
    assertTrue(!peut('driver', 'delivering', 'cancelled'), 'une annulation en cours de course passe par un admin');
});

test('aucune étape ne peut être sautée', function (): void {
    assertTrue(!peut('restaurant', 'pending', 'ready_for_pickup'), 'prête sans préparation');
    assertTrue(!peut('driver', 'ready_for_pickup', 'delivered'), 'livrée sans être récupérée');
});

test('un statut terminal ne mène plus nulle part', function (): void {
    foreach (array_keys(transitions()) as $role) {
        foreach (['delivered', 'cancelled'] as $terminal) {
            assertTrue(
                (transitions()[$role][$terminal] ?? []) === [],
                "{$role} : aucune transition ne doit partir de {$terminal}"
            );
        }
    }
});

describe('Transitions de statut — arbitrage admin');

test('l\'admin peut annuler à tout stade non terminal', function (): void {
    foreach (['pending', 'accepted', 'preparing', 'ready_for_pickup', 'picked_up', 'delivering'] as $from) {
        assertTrue(peut('admin', $from, 'cancelled'), "admin : {$from} → cancelled doit être possible");
    }
});

test('l\'admin ne peut jamais forcer une livraison', function (): void {
    // Forcer « delivered » déclencherait les virements sans qu'aucune livraison ait eu lieu :
    // un arbitrage de litige annule, il ne paie pas.
    foreach (array_keys(transitions()['admin']) as $from) {
        assertTrue(!peut('admin', $from, 'delivered'), "admin : {$from} → delivered doit être refusé");
    }
});

test('l\'admin ne fait pas avancer une commande à la place des parties', function (): void {
    foreach (transitions()['admin'] as $from => $targets) {
        assertSame(['cancelled'], $targets, "admin depuis {$from} : seule l'annulation est permise");
    }
});
