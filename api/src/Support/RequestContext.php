<?php

declare(strict_types=1);

namespace Saveurs\Support;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Extrait le contexte réseau d'une requête pour la détection de fraude et l'audit.
 * L'IP réelle est lue derrière un éventuel reverse-proxy (IONOS) via X-Forwarded-For, dont on ne
 * garde que la première entrée — la seule que le proxy de confiance ait ajoutée.
 */
final class RequestContext
{
    public static function ip(Request $request): ?string
    {
        $server = $request->getServerParams();

        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }

        $remote = $server['REMOTE_ADDR'] ?? null;

        return is_string($remote) && filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : null;
    }

    public static function userAgent(Request $request): ?string
    {
        $ua = $request->getHeaderLine('User-Agent');

        return $ua === '' ? null : mb_substr($ua, 0, 255);
    }
}
