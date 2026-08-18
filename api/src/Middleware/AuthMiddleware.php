<?php

declare(strict_types=1);

namespace Saveurs\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Http\Server\MiddlewareInterface;
use Saveurs\Support\JsonResponse;
use Saveurs\Support\Jwt;

/**
 * Vérifie le Bearer token et injecte user_id / user_role dans la requête.
 * Un rôle optionnel restreint l'accès (ex: 'restaurant_owner').
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly ?string $requiredRole = null
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $header = $request->getHeaderLine('Authorization');

        if (!str_starts_with($header, 'Bearer ')) {
            return JsonResponse::error($this->responseFactory->createResponse(), 401, 'Non authentifié');
        }

        $claims = Jwt::verify(substr($header, 7));

        if ($claims === null) {
            return JsonResponse::error($this->responseFactory->createResponse(), 401, 'Jeton invalide ou expiré');
        }

        if ($this->requiredRole !== null && $claims['role'] !== $this->requiredRole) {
            return JsonResponse::error($this->responseFactory->createResponse(), 403, 'Accès refusé pour ce rôle');
        }

        $request = $request
            ->withAttribute('user_id', $claims['sub'])
            ->withAttribute('user_role', $claims['role']);

        return $handler->handle($request);
    }
}
