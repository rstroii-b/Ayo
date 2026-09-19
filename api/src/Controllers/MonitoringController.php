<?php

declare(strict_types=1);

namespace Saveurs\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Saveurs\Support\CinetPayClient;
use Saveurs\Support\Database;
use Saveurs\Support\JsonResponse;
use Saveurs\Support\Log;

/**
 * Supervision des transactions — réservé au rôle admin (sauf health()).
 *
 * Jusqu'ici la plateforme écrivait tout (order_events, payouts) sans que rien ne le relise :
 * un virement en échec pouvait dormir en base indéfiniment. Ces endpoints alimentent
 * admin-transactions.html, qui se met à jour en direct via le canal Pusher private-admin.
 */
final class MonitoringController
{
    private const PAYMENT_STATUSES = ['unpaid', 'paid', 'failed'];
    private const PAYOUT_STATUSES = ['pending', 'sent', 'failed'];

    /** Au-delà de ce délai, une commande encore "unpaid" est considérée comme en souffrance. */
    private const STALE_PAYMENT_MINUTES = 30;

    /**
     * GET /health — sonde publique pour les monitors externes (UptimeRobot, IONOS…).
     * Volontairement avare en détails : un endpoint public n'a pas à décrire l'infrastructure.
     */
    public function health(Request $request, Response $response): Response
    {
        try {
            Database::connection()->query('SELECT 1');
            $databaseUp = true;
        } catch (\Throwable $e) {
            Log::app()->critical('health.database_unreachable', ['error' => $e->getMessage()]);
            $databaseUp = false;
        }

        return JsonResponse::ok(
            $response,
            ['status' => $databaseUp ? 'ok' : 'degraded', 'database' => $databaseUp],
            $databaseUp ? 200 : 503
        );
    }

    /** GET /admin/metrics — compteurs du tableau de bord transactions. */
    public function metrics(Request $request, Response $response): Response
    {
        $db = Database::connection();

        $payments = $db->query(
            "SELECT
                SUM(payment_status = 'paid' AND DATE(paid_at) = CURDATE())                      AS paid_today_count,
                COALESCE(SUM(CASE WHEN payment_status = 'paid' AND DATE(paid_at) = CURDATE()
                                  THEN total_cents ELSE 0 END), 0)                              AS paid_today_cents,
                SUM(payment_status = 'failed' AND DATE(created_at) = CURDATE())                 AS failed_today_count,
                SUM(payment_status = 'unpaid' AND status <> 'cancelled'
                    AND created_at < (NOW() - INTERVAL " . self::STALE_PAYMENT_MINUTES . " MINUTE)) AS stale_unpaid_count
             FROM orders"
        )->fetch();

        $payouts = $db->query(
            "SELECT
                SUM(statut = 'pending')                                                     AS pending_count,
                COALESCE(SUM(CASE WHEN statut = 'pending' THEN amount_cents ELSE 0 END), 0) AS pending_cents,
                SUM(statut = 'failed')                                                      AS failed_count,
                COALESCE(SUM(CASE WHEN statut = 'failed' THEN amount_cents ELSE 0 END), 0)  AS failed_cents
             FROM payouts"
        )->fetch();

        return JsonResponse::ok($response, [
            'payments' => [
                'paid_today_count' => (int) $payments['paid_today_count'],
                'paid_today_cents' => (int) $payments['paid_today_cents'],
                'failed_today_count' => (int) $payments['failed_today_count'],
                'stale_unpaid_count' => (int) $payments['stale_unpaid_count'],
                'stale_after_minutes' => self::STALE_PAYMENT_MINUTES,
            ],
            'payouts' => [
                'pending_count' => (int) $payouts['pending_count'],
                'pending_cents' => (int) $payouts['pending_cents'],
                'failed_count' => (int) $payouts['failed_count'],
                'failed_cents' => (int) $payouts['failed_cents'],
            ],
            // Un temps réel muet ou un CinetPay non configuré sont des pannes silencieuses :
            // l'admin doit pouvoir le constater sans ouvrir le .env du serveur.
            'fraud' => [
                'open_alerts' => (int) Database::connection()->query("SELECT COUNT(*) FROM fraud_alerts WHERE status = 'open'")->fetchColumn(),
                'high_open_alerts' => (int) Database::connection()->query("SELECT COUNT(*) FROM fraud_alerts WHERE status = 'open' AND severity = 'high'")->fetchColumn(),
            ],
            'services' => [
                'cinetpay_configured' => CinetPayClient::client() !== null,
                'realtime_configured' => ($_ENV['PUSHER_KEY'] ?? '') !== '' && ($_ENV['PUSHER_SECRET'] ?? '') !== '',
                'push_configured' => ($_ENV['VAPID_PUBLIC_KEY'] ?? '') !== '',
            ],
            'generated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** GET /admin/transactions?payment_status=paid&limit=50&offset=0 */
    public function transactions(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $status = $query['payment_status'] ?? null;

        if ($status !== null && !in_array($status, self::PAYMENT_STATUSES, true)) {
            return JsonResponse::error($response, 422, 'payment_status invalide');
        }

        [$limit, $offset] = $this->pagination($query);

        // LIMIT/OFFSET interpolés après cast entier : PDO en prepares natifs refuse une chaîne
        // à cet emplacement. Le filtre de statut, lui, reste un paramètre lié.
        $sql =
            "SELECT o.id, o.status, o.payment_status, o.total_cents, o.subtotal_cents, o.delivery_fee_cents,
                    o.created_at, o.paid_at, o.payment_intent_id,
                    u.first_name AS client_first_name, u.last_name AS client_last_name,
                    r.name AS restaurant_name,
                    (SELECT COUNT(*) FROM payouts p WHERE p.order_id = o.id AND p.statut = 'failed') AS failed_payouts_count
             FROM orders o
             JOIN users u ON u.id = o.client_id
             JOIN restaurants r ON r.id = o.restaurant_id"
            . ($status === null ? '' : ' WHERE o.payment_status = ?')
            . " ORDER BY o.created_at DESC LIMIT {$limit} OFFSET {$offset}";

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($status === null ? [] : [$status]);

        return JsonResponse::ok($response, [
            'transactions' => array_map($this->castTransaction(...), $stmt->fetchAll()),
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /** GET /admin/payouts?statut=failed&limit=50&offset=0 */
    public function payouts(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $statut = $query['statut'] ?? null;

        if ($statut !== null && !in_array($statut, self::PAYOUT_STATUSES, true)) {
            return JsonResponse::error($response, 422, 'statut invalide');
        }

        [$limit, $offset] = $this->pagination($query);

        $sql =
            'SELECT p.id, p.order_id, p.recipient_type, p.amount_cents, p.statut, p.failure_reason,
                    p.cinetpay_transfer_id, p.created_at,
                    r.name AS restaurant_name,
                    u.first_name AS driver_first_name, u.last_name AS driver_last_name
             FROM payouts p
             LEFT JOIN restaurants r ON r.id = p.restaurant_id
             LEFT JOIN users u ON u.id = p.driver_id'
            . ($statut === null ? '' : ' WHERE p.statut = ?')
            . " ORDER BY p.created_at DESC LIMIT {$limit} OFFSET {$offset}";

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($statut === null ? [] : [$statut]);

        $payouts = array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['order_id'] = (int) $row['order_id'];
            $row['amount_cents'] = (int) $row['amount_cents'];

            return $row;
        }, $stmt->fetchAll());

        return JsonResponse::ok($response, ['payouts' => $payouts, 'limit' => $limit, 'offset' => $offset]);
    }

    /**
     * GET /admin/orders/{id}/events — piste d'audit complète d'une commande : transitions de
     * statut, événements de paiement, et les virements déclenchés à la livraison.
     */
    /** GET /admin/fraud-alerts?status=open&limit=50 — alertes du moteur de détection de fraude. */
    public function fraudAlerts(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $status = $query['status'] ?? 'open';

        if (!in_array($status, ['open', 'reviewed', 'dismissed', 'all'], true)) {
            return JsonResponse::error($response, 422, 'status invalide');
        }

        [$limit, $offset] = $this->pagination($query);

        $sql =
            'SELECT fa.id, fa.type, fa.severity, fa.user_id, fa.order_id, fa.detail, fa.meta_json, fa.status, fa.created_at,
                    u.first_name, u.last_name, u.role
             FROM fraud_alerts fa
             LEFT JOIN users u ON u.id = fa.user_id'
            . ($status === 'all' ? '' : ' WHERE fa.status = ?')
            . " ORDER BY fa.created_at DESC LIMIT {$limit} OFFSET {$offset}";

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($status === 'all' ? [] : [$status]);

        $alerts = array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['user_id'] = $row['user_id'] === null ? null : (int) $row['user_id'];
            $row['order_id'] = $row['order_id'] === null ? null : (int) $row['order_id'];
            $row['meta'] = $row['meta_json'] === null ? null : json_decode($row['meta_json'], true);
            unset($row['meta_json']);

            return $row;
        }, $stmt->fetchAll());

        return JsonResponse::ok($response, ['alerts' => $alerts, 'limit' => $limit, 'offset' => $offset]);
    }

    /** PATCH /admin/fraud-alerts/{id} — body {status: reviewed|dismissed} */
    public function updateFraudAlert(Request $request, Response $response, array $routeArgs): Response
    {
        $body = (array) $request->getParsedBody();
        $status = $body['status'] ?? null;

        if (!in_array($status, ['reviewed', 'dismissed', 'open'], true)) {
            return JsonResponse::error($response, 422, 'status doit être reviewed, dismissed ou open');
        }

        $stmt = Database::connection()->prepare('UPDATE fraud_alerts SET status = ? WHERE id = ?');
        $stmt->execute([$status, (int) $routeArgs['id']]);

        if ($stmt->rowCount() === 0) {
            return JsonResponse::error($response, 404, 'Alerte introuvable');
        }

        return JsonResponse::ok($response, ['status' => $status]);
    }

    public function orderEvents(Request $request, Response $response, array $routeArgs): Response
    {
        $orderId = (int) $routeArgs['id'];
        $db = Database::connection();

        $order = $db->prepare(
            'SELECT o.id, o.status, o.payment_status, o.total_cents, o.subtotal_cents, o.delivery_fee_cents,
                    o.created_at, o.paid_at, o.delivered_at, o.payment_intent_id,
                    r.name AS restaurant_name, u.first_name AS client_first_name, u.last_name AS client_last_name
             FROM orders o
             JOIN restaurants r ON r.id = o.restaurant_id
             JOIN users u ON u.id = o.client_id
             WHERE o.id = ?'
        );
        $order->execute([$orderId]);
        $order = $order->fetch();

        if ($order === false) {
            return JsonResponse::error($response, 404, 'Commande introuvable');
        }

        $events = $db->prepare(
            'SELECT status, actor_type, created_at FROM order_events WHERE order_id = ? ORDER BY id ASC'
        );
        $events->execute([$orderId]);

        $payouts = $db->prepare(
            'SELECT id, recipient_type, amount_cents, statut, failure_reason, created_at
             FROM payouts WHERE order_id = ? ORDER BY id ASC'
        );
        $payouts->execute([$orderId]);

        return JsonResponse::ok($response, [
            'order' => $this->castTransaction($order),
            'events' => $events->fetchAll(),
            'payouts' => $payouts->fetchAll(),
        ]);
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array{int, int}
     */
    private function pagination(array $query): array
    {
        $limit = max(1, min(200, (int) ($query['limit'] ?? 50)));
        $offset = max(0, (int) ($query['offset'] ?? 0));

        return [$limit, $offset];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function castTransaction(array $row): array
    {
        foreach (['id', 'total_cents', 'subtotal_cents', 'delivery_fee_cents', 'failed_payouts_count'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }

        return $row;
    }
}
