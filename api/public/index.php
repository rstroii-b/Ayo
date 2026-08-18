<?php

declare(strict_types=1);

use Saveurs\Controllers\AuthController;
use Saveurs\Controllers\ConnectController;
use Saveurs\Controllers\MenuController;
use Saveurs\Controllers\OrderController;
use Saveurs\Controllers\PaymentController;
use Saveurs\Controllers\PushController;
use Saveurs\Controllers\RestaurantController;
use Saveurs\Middleware\AuthMiddleware;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->addErrorMiddleware(true, true, true);

// CORS pour le développement local (front et API sur des ports différents).
$app->add(function ($request, $handler) {
    $response = $handler->handle($request);

    return $response
        ->withHeader('Access-Control-Allow-Origin', $_ENV['CORS_ORIGIN'] ?? '*')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, Idempotency-Key')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PATCH, PUT, DELETE, OPTIONS');
});
$app->options('/{routes:.+}', fn ($request, $response) => $response);

$responseFactory = new ResponseFactory();
$auth = fn (?string $role = null) => new AuthMiddleware($responseFactory, $role);

$app->group('/api/v1', function ($group) use ($auth) {
    // Auth — publique
    $group->post('/auth/register', [AuthController::class, 'register']);
    $group->post('/auth/login', [AuthController::class, 'login']);
    $group->post('/auth/refresh', [AuthController::class, 'refresh']);
    $group->get('/auth/me', [AuthController::class, 'me'])->add($auth());

    // Restaurants & menus — lecture publique, création par le restaurateur
    $group->post('/restaurants', [RestaurantController::class, 'create'])->add($auth('restaurant_owner'));
    $group->patch('/restaurants/{id}', [RestaurantController::class, 'update'])->add($auth('restaurant_owner'));
    $group->get('/restaurant/mine', [RestaurantController::class, 'mine'])->add($auth('restaurant_owner'));
    $group->get('/restaurant/mine/menu', [RestaurantController::class, 'mineMenu'])->add($auth('restaurant_owner'));
    $group->get('/restaurants', [RestaurantController::class, 'index']);
    $group->get('/restaurants/{id}', [RestaurantController::class, 'show']);
    $group->get('/restaurants/{id}/menu', [RestaurantController::class, 'menu']);

    // Gestion du menu — restaurateur uniquement
    $group->post('/restaurants/{id}/menu/categories', [MenuController::class, 'createCategory'])
        ->add($auth('restaurant_owner'));
    $group->post('/restaurants/{id}/menu/items', [MenuController::class, 'create'])
        ->add($auth('restaurant_owner'));
    $group->patch('/restaurants/{id}/menu/items/{itemId}', [MenuController::class, 'update'])
        ->add($auth('restaurant_owner'));

    // Commandes — authentifié, rôle vérifié dans le contrôleur selon la partie prenante
    $group->post('/orders', [OrderController::class, 'create'])->add($auth('client'));
    $group->get('/orders/mine', [OrderController::class, 'mine'])->add($auth('client'));
    $group->get('/orders/{id}', [OrderController::class, 'show'])->add($auth());
    $group->patch('/orders/{id}/status', [OrderController::class, 'updateStatus'])->add($auth());
    $group->patch('/orders/{id}/claim', [OrderController::class, 'claim'])->add($auth('driver'));

    // Back-office restaurateur
    $group->get('/restaurant/orders/live', [OrderController::class, 'liveForRestaurant'])
        ->add($auth('restaurant_owner'));

    // App livreur
    $group->get('/driver/orders/available', [OrderController::class, 'availableForDriver'])->add($auth('driver'));
    $group->get('/driver/orders/active', [OrderController::class, 'activeForDriver'])->add($auth('driver'));

    // Paiement — Stripe Connect (voir §5)
    $group->post('/payments/intent', [PaymentController::class, 'createIntent'])->add($auth('client'));
    $group->post('/webhooks/stripe', [PaymentController::class, 'webhook']);

    // Onboarding Stripe Connect — restaurateur ou livreur
    $group->post('/connect/onboard', [ConnectController::class, 'onboard'])->add($auth());
    $group->get('/connect/status', [ConnectController::class, 'status'])->add($auth());

    // Notifications push
    $group->get('/push/vapid-key', [PushController::class, 'vapidKey']);
    $group->post('/push/subscribe', [PushController::class, 'subscribe'])->add($auth());
    $group->post('/push/unsubscribe', [PushController::class, 'unsubscribe'])->add($auth());
});

$app->run();
