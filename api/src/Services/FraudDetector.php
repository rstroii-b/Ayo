<?php

declare(strict_types=1);

namespace Saveurs\Services;

use Saveurs\Support\Database;
use Saveurs\Support\Log;
use Saveurs\Support\Realtime;

/**
 * Détection de comportements frauduleux. Chaque contrôle est appelé au point métier concerné
 * (création de commande, position livreur, livraison, changement de mobile money). Deux effets :
 *   - lève une fraud_alert (remontée dans le panel admin, diffusée en temps réel) ;
 *   - pour les cas nets et graves, l'appelant refuse l'action (ex: numéro mobile money déjà utilisé).
 *
 * La détection ne doit JAMAIS faire tomber une requête légitime : toute erreur interne est avalée
 * et journalisée. Un contrôle qui échoue laisse simplement passer plutôt que de bloquer un vrai client.
 */
final class FraudDetector
{
    /** Vitesse de déplacement au-delà de laquelle une position GPS est jugée impossible (km/h). */
    private const MAX_HUMAN_SPEED_KMH = 150.0;

    /** Durée minimale plausible entre la prise en charge et la livraison (minutes). */
    private const MIN_DELIVERY_MINUTES = 3.0;

    /**
     * Journalise une action sensible avec son contexte réseau. Sert de matière première à la
     * détection multi-comptes (même IP, même appareil) et à l'investigation.
     *
     * @param array<string, mixed> $meta
     */
    public static function record(string $action, ?int $userId, ?string $ip, ?string $userAgent, array $meta = []): void
    {
        try {
            Database::connection()->prepare(
                'INSERT INTO security_events (user_id, action, ip, user_agent, meta_json) VALUES (?, ?, ?, ?, ?)'
            )->execute([$userId, $action, $ip, $userAgent, $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE)]);
        } catch (\Throwable $e) {
            Log::app()->warning('fraud.record_failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Le numéro mobile money de reversement est-il déjà rattaché à un AUTRE bénéficiaire ?
     * Un même portefeuille sur plusieurs comptes est le montage classique pour siphonner des
     * virements via des comptes jetables. Cas net et grave → l'appelant refuse (retourne true).
     */
    public static function isMobileMoneyReused(string $number, string $ownerKind, int $ownerId): bool
    {
        try {
            $db = Database::connection();

            $inDrivers = $db->prepare(
                'SELECT COUNT(*) FROM driver_profiles WHERE mobile_money_number = ?'
                . ($ownerKind === 'driver' ? ' AND user_id <> ?' : ' AND user_id IS NOT NULL')
            );
            $inDrivers->execute($ownerKind === 'driver' ? [$number, $ownerId] : [$number]);

            $inRestaurants = $db->prepare(
                'SELECT COUNT(*) FROM restaurants WHERE mobile_money_number = ?'
                . ($ownerKind === 'restaurant' ? ' AND id <> ?' : '')
            );
            $inRestaurants->execute($ownerKind === 'restaurant' ? [$number, $ownerId] : [$number]);

            $count = (int) $inDrivers->fetchColumn() + (int) $inRestaurants->fetchColumn();

            if ($count > 0) {
                self::raise('mobile_money_reuse', 'high', $ownerId, null,
                    "Numéro mobile money déjà utilisé par un autre bénéficiaire", ['number' => $number, 'owner_kind' => $ownerKind]);

                return true;
            }
        } catch (\Throwable $e) {
            Log::app()->warning('fraud.mm_check_failed', ['error' => $e->getMessage()]);
        }

        return false;
    }

    /**
     * Cadence de commandes anormale : beaucoup de commandes en très peu de temps depuis le même
     * client ou la même IP est un signal de carding / abus de promo. Ne bloque pas, alerte.
     */
    public static function onOrderCreated(int $orderId, int $clientId, ?string $ip, array $meta = []): void
    {
        try {
            $db = Database::connection();

            $recent = $db->prepare(
                "SELECT COUNT(*) FROM orders WHERE client_id = ? AND created_at > (NOW() - INTERVAL 10 MINUTE)"
            );
            $recent->execute([$clientId]);
            if ((int) $recent->fetchColumn() >= 5) {
                self::raise('order_velocity', 'medium', $clientId, $orderId,
                    'Plus de 5 commandes en 10 minutes pour ce client');
            }

            // Écart libellé/coordonnées (résidu H-2) : coordonnées posées sur le restaurant alors que
            // le libellé décrit un ailleurs. On ne peut pas géocoder hors-ligne, mais on signale le
            // motif « frais plancher pour une adresse qui semble lointaine » à l'analyste.
            if (isset($meta['distance_km'], $meta['label']) && (float) $meta['distance_km'] < 0.3
                && preg_match('/\d+\s*km|loin|bingerville|grand[- ]bassam|autre/i', (string) $meta['label']) === 1) {
                self::raise('address_mismatch', 'medium', $clientId, $orderId,
                    'Coordonnées quasi nulles mais libellé d\'adresse évoquant une distance', $meta);
            }
        } catch (\Throwable $e) {
            Log::app()->warning('fraud.order_check_failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Position GPS livreur incohérente : un saut impliquant une vitesse surhumaine trahit une
     * position falsifiée (mock GPS). On compare à la dernière position connue.
     */
    public static function onDriverLocation(int $driverId, float $lat, float $lng): void
    {
        try {
            $db = Database::connection();
            $prev = $db->prepare('SELECT lat, lng, updated_at FROM driver_locations WHERE driver_id = ?');
            $prev->execute([$driverId]);
            $last = $prev->fetch();

            if ($last === false) {
                return;
            }

            $km = self::haversineKm((float) $last['lat'], (float) $last['lng'], $lat, $lng);
            $seconds = max(1, time() - strtotime((string) $last['updated_at']));
            $speedKmh = $km / ($seconds / 3600);

            if ($km > 1 && $speedKmh > self::MAX_HUMAN_SPEED_KMH) {
                self::raise('gps_teleport', 'high', $driverId, null,
                    sprintf('Saut GPS de %.1f km en %ds (%.0f km/h)', $km, $seconds, $speedKmh),
                    ['from' => [$last['lat'], $last['lng']], 'to' => [$lat, $lng]]);
            }
        } catch (\Throwable $e) {
            Log::app()->warning('fraud.gps_check_failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Livraison impossible : passage picked_up → delivered en un temps trop court pour une vraie
     * course. Signal de livreur qui valide sans livrer pour déclencher son virement.
     */
    public static function onDelivered(int $orderId, ?int $driverId): void
    {
        try {
            $db = Database::connection();
            $stmt = $db->prepare('SELECT picked_up_at, delivered_at FROM orders WHERE id = ?');
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();

            if ($order === false || $order['picked_up_at'] === null || $order['delivered_at'] === null) {
                return;
            }

            $minutes = (strtotime((string) $order['delivered_at']) - strtotime((string) $order['picked_up_at'])) / 60;
            if ($minutes >= 0 && $minutes < self::MIN_DELIVERY_MINUTES) {
                self::raise('impossible_delivery', 'high', $driverId, $orderId,
                    sprintf('Livraison marquée %.1f min après la prise en charge', $minutes));
            }
        } catch (\Throwable $e) {
            Log::app()->warning('fraud.delivery_check_failed', ['error' => $e->getMessage()]);
        }
    }

    /** Enregistre une alerte et la diffuse au canal de supervision admin. */
    private static function raise(string $type, string $severity, ?int $userId, ?int $orderId, string $detail, array $meta = []): void
    {
        Database::connection()->prepare(
            'INSERT INTO fraud_alerts (type, severity, user_id, order_id, detail, meta_json) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$type, $severity, $userId, $orderId, $detail, $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE)]);

        Log::transactions()->warning('fraud.alert', [
            'type' => $type, 'severity' => $severity, 'user_id' => $userId, 'order_id' => $orderId, 'detail' => $detail,
        ]);
        Realtime::notifyAdmins('fraud', ['type' => $type, 'severity' => $severity, 'detail' => $detail]);
    }

    private static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
