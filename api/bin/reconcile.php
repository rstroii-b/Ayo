<?php

/**
 * Réconciliation des transactions CinetPay — à lancer par cron (toutes les 10 minutes) :
 *
 *   * / 10 * * * * php /chemin/vers/api/bin/reconcile.php >> /dev/null 2>&1
 *
 * Pourquoi : un webhook peut se perdre (coupure réseau, API momentanément indisponible,
 * déploiement en cours). Sans rattrapage, une commande réellement payée restait "unpaid"
 * indéfiniment, et un virement réellement parti restait "pending" pour toujours. Ce script
 * redemande l'état réel à CinetPay et fait basculer ce qui doit l'être.
 *
 * Il ne réécrit jamais un état déjà tranché : tout passe par PaymentLedger, qui conditionne
 * chaque transition à l'état de départ. Lancer le script deux fois de suite ne produit donc
 * aucun doublon, même si un webhook arrive au même moment.
 */

declare(strict_types=1);

namespace Saveurs\Bin;

use Saveurs\Services\PaymentLedger;
use Saveurs\Support\CinetPayClient;
use Saveurs\Support\Database;
use Saveurs\Support\Log;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);

    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

\Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();

/** Délai laissé au webhook avant de doubler CinetPay par un appel direct. */
$graceMinutes = max(1, (int) ($_ENV['RECONCILE_GRACE_MINUTES'] ?? 10));
/** Au-delà, la transaction est considérée comme définitivement perdue — inutile d'interroger CinetPay. */
$lookbackDays = max(1, (int) ($_ENV['RECONCILE_LOOKBACK_DAYS'] ?? 7));
$batchSize = max(1, min(500, (int) ($_ENV['RECONCILE_BATCH_SIZE'] ?? 200)));

$client = CinetPayClient::client();

if ($client === null) {
    Log::app()->error('reconcile.cinetpay_not_configured');
    fwrite(STDERR, "CinetPay n'est pas configuré — réconciliation impossible." . PHP_EOL);

    exit(1);
}

$db = Database::connection();
$report = ['payments_checked' => 0, 'payments_settled' => 0, 'payouts_checked' => 0, 'payouts_settled' => 0, 'errors' => 0];

// ---------------------------------------------------------------
// Paiements clients restés "unpaid" alors qu'un lien CinetPay existe
// ---------------------------------------------------------------
$orders = $db->prepare(
    "SELECT id, payment_intent_id
     FROM orders
     WHERE payment_status = 'unpaid'
       AND payment_intent_id IS NOT NULL
       AND created_at < (NOW() - INTERVAL ? MINUTE)
       AND created_at > (NOW() - INTERVAL ? DAY)
     ORDER BY created_at ASC
     LIMIT {$batchSize}"
);
$orders->execute([$graceMinutes, $lookbackDays]);

foreach ($orders->fetchAll() as $order) {
    ++$report['payments_checked'];

    try {
        $status = $client->payments()->find($order['payment_intent_id']);
    } catch (\Throwable $e) {
        ++$report['errors'];
        Log::app()->error('reconcile.payment_lookup_failed', [
            'order_id' => (int) $order['id'],
            'payment_intent_id' => $order['payment_intent_id'],
            'error' => $e->getMessage(),
        ]);

        continue;
    }

    if ($status->isSuccessful()) {
        // Le webhook ne nous est jamais parvenu : c'est ce passage qui encaisse la commande.
        if (PaymentLedger::markPaid((int) $order['id'], 'reconcile')) {
            ++$report['payments_settled'];
            Log::app()->warning('reconcile.missed_payment_webhook', [
                'order_id' => (int) $order['id'],
                'payment_intent_id' => $order['payment_intent_id'],
            ]);
        }
    } elseif ($status->isFinal()) {
        if (PaymentLedger::markPaymentFailed((int) $order['id'], 'reconcile', $status->status)) {
            ++$report['payments_settled'];
        }
    }
}

// ---------------------------------------------------------------
// Virements sortants restés "pending"
// ---------------------------------------------------------------
$payouts = $db->prepare(
    "SELECT id, cinetpay_transfer_id
     FROM payouts
     WHERE statut = 'pending'
       AND cinetpay_transfer_id IS NOT NULL
       AND created_at < (NOW() - INTERVAL ? MINUTE)
       AND created_at > (NOW() - INTERVAL ? DAY)
     ORDER BY created_at ASC
     LIMIT {$batchSize}"
);
$payouts->execute([$graceMinutes, $lookbackDays]);

foreach ($payouts->fetchAll() as $payout) {
    ++$report['payouts_checked'];

    try {
        $transfer = $client->transfers()->find($payout['cinetpay_transfer_id']);
    } catch (\Throwable $e) {
        ++$report['errors'];
        Log::app()->error('reconcile.transfer_lookup_failed', [
            'payout_id' => (int) $payout['id'],
            'cinetpay_transfer_id' => $payout['cinetpay_transfer_id'],
            'error' => $e->getMessage(),
        ]);

        continue;
    }

    if (!$transfer->isFinal()) {
        continue;
    }

    $settled = PaymentLedger::settlePayout(
        (int) $payout['id'],
        $transfer->isSuccessful(),
        $transfer->isSuccessful() ? null : "CinetPay: {$transfer->status}",
        'reconcile'
    );

    if ($settled) {
        ++$report['payouts_settled'];
    }
}

Log::app()->info('reconcile.completed', $report);

fwrite(
    STDOUT,
    sprintf(
        "[%s] paiements vérifiés=%d régularisés=%d | virements vérifiés=%d régularisés=%d | erreurs=%d%s",
        date('Y-m-d H:i:s'),
        $report['payments_checked'],
        $report['payments_settled'],
        $report['payouts_checked'],
        $report['payouts_settled'],
        $report['errors'],
        PHP_EOL
    )
);

exit($report['errors'] > 0 ? 1 : 0);
