<?php

declare(strict_types=1);

use Saveurs\Controllers\AuthController;
use Saveurs\Controllers\ConnectController;
use Saveurs\Controllers\DriverController;
use Saveurs\Controllers\MenuController;
use Saveurs\Controllers\OrderController;
use Saveurs\Controllers\PaymentController;
use Saveurs\Controllers\PushController;
use Saveurs\Controllers\RealtimeController;
use Saveurs\Controllers\RestaurantController;
use Saveurs\Controllers\WebAuthnController;
use Saveurs\Middleware\AuthMiddleware;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$app = AppFactory::create();
$app->addBodyParsingMiddleware();

// Les traces d'erreur (chemins serveur, requêtes SQL, structure interne) ne doivent jamais
// être renvoyées au client — seul un APP_DEBUG=true explicite (dev local) les affiche.
$debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
$app->addErrorMiddleware($debug, true, true);

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
    $group->post('/restaurants/{id}/menu/items/{itemId}/options', [MenuController::class, 'createOption'])
        ->add($auth('restaurant_owner'));
    $group->patch('/restaurants/{id}/menu/items/{itemId}/options/{optionId}', [MenuController::class, 'updateOption'])
        ->add($auth('restaurant_owner'));
    $group->delete('/restaurants/{id}/menu/items/{itemId}/options/{optionId}', [MenuController::class, 'deleteOption'])
        ->add($auth('restaurant_owner'));

    // Commandes — authentifié, rôle vérifié dans le contrôleur selon la partie prenante
    $group->post('/orders', [OrderController::class, 'create'])->add($auth('client'));
    $group->get('/orders/mine', [OrderController::class, 'mine'])->add($auth('client'));
    $group->get('/recommendations/mine', [OrderController::class, 'recommendationForClient'])->add($auth('client'));
    $group->get('/orders/{id}', [OrderController::class, 'show'])->add($auth());
    $group->patch('/orders/{id}/status', [OrderController::class, 'updateStatus'])->add($auth());
    $group->patch('/orders/{id}/claim', [OrderController::class, 'claim'])->add($auth('driver'));

    // Back-office restaurateur
    $group->get('/restaurant/orders/live', [OrderController::class, 'liveForRestaurant'])
        ->add($auth('restaurant_owner'));
    $group->get('/restaurant/orders/history', [OrderController::class, 'historyForRestaurant'])
        ->add($auth('restaurant_owner'));
    $group->get('/restaurant/stats', [OrderController::class, 'statsForRestaurant'])
        ->add($auth('restaurant_owner'));

    // App livreur
    $group->get('/driver/orders/available', [OrderController::class, 'availableForDriver'])->add($auth('driver'));
    $group->get('/driver/orders/active', [OrderController::class, 'activeForDriver'])->add($auth('driver'));
    $group->patch('/driver/status', [DriverController::class, 'updateStatus'])->add($auth('driver'));
    $group->post('/driver/location', [DriverController::class, 'updateLocation'])->add($auth('driver'));

    // Paiement — Stripe Connect (voir §5)
    $group->post('/payments/intent', [PaymentController::class, 'createIntent'])->add($auth('client'));
    $group->post('/webhooks/stripe', [PaymentController::class, 'webhook']);
    $group->post('/webhooks/cinetpay', [PaymentController::class, 'cinetpayWebhook']);

    // Onboarding Stripe Connect — restaurateur ou livreur
    $group->post('/connect/onboard', [ConnectController::class, 'onboard'])->add($auth());
    $group->get('/connect/status', [ConnectController::class, 'status'])->add($auth());
    $group->patch('/connect/mobile-money', [ConnectController::class, 'updateMobileMoney'])->add($auth());

    // Notifications push
    $group->get('/push/vapid-key', [PushController::class, 'vapidKey']);
    $group->post('/push/subscribe', [PushController::class, 'subscribe'])->add($auth());
    $group->post('/push/unsubscribe', [PushController::class, 'unsubscribe'])->add($auth());

    // Temps réel — autorisation des canaux privés Pusher
    $group->post('/realtime/auth', [RealtimeController::class, 'auth'])->add($auth());

    // Connexion biométrique (WebAuthn / passkeys)
    $group->post('/webauthn/register/options', [WebAuthnController::class, 'registerOptions'])->add($auth());
    $group->post('/webauthn/register/verify', [WebAuthnController::class, 'registerVerify'])->add($auth());
    $group->get('/webauthn/credentials', [WebAuthnController::class, 'list'])->add($auth());
    $group->delete('/webauthn/credentials/{id}', [WebAuthnController::class, 'delete'])->add($auth());
    $group->post('/webauthn/login/options', [WebAuthnController::class, 'loginOptions']);
    $group->post('/webauthn/login/verify', [WebAuthnController::class, 'loginVerify']);
});

$app->run();
