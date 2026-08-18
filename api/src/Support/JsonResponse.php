<?php

declare(strict_types=1);

namespace Saveurs\Support;

use Psr\Http\Message\ResponseInterface as Response;

final class JsonResponse
{
    public static function ok(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    /** Erreur au format RFC 7807 (problem+json), voir §3 du document d'architecture. */
    public static function error(Response $response, int $status, string $title, string $detail = ''): Response
    {
        $body = ['type' => 'about:blank', 'title' => $title, 'status' => $status];
        if ($detail !== '') {
            $body['detail'] = $detail;
        }

        $response->getBody()->write(json_encode($body, JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/problem+json')->withStatus($status);
    }
}
