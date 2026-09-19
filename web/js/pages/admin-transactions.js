import { apiFetch } from '../api.js';
import { requireLogin, logout } from '../auth.js';
import { formatMoney, escapeHtml } from '../format.js';
import { realtimeClient } from '../realtime.js';

const PAYMENT_LABEL = { paid: 'Payé', unpaid: 'Non encaissé', failed: 'Échec' };
const PAYMENT_PILL = { paid: 'ok', unpaid: 'warn', failed: 'bad' };
const PAYOUT_LABEL = { sent: 'Envoyé', pending: 'En attente', failed: 'Échec' };
const PAYOUT_PILL = { sent: 'ok', pending: 'warn', failed: 'bad' };

if (requireLogin('/admin-transactions.html')) {
  init();
}

document.getElementById('logout-btn').addEventListener('click', () => {
  logout();
  window.location.href = '/login.html';
});

function init() {
  document.getElementById('payment-filter').addEventListener('change', loadTransactions);
  document.getElementById('payout-filter').addEventListener('change', loadPayouts);
  document.getElementById('fraud-filter').addEventListener('change', loadFraud);
  document.getElementById('audit-close').addEventListener('click', closeAudit);

  refreshAll();

  // Le rafraîchissement périodique est armé en premier et sans condition : si le script Pusher
  // n'a pas pu être chargé (CDN injoignable, réseau filtré), `realtimeClient()` jette et tout ce
  // qui suit est sauté. Un écran de supervision qui se fige en silence serait exactement la
  // panne qu'il est censé rendre visible.
  setInterval(refreshAll, 20000);

  // Temps réel : PaymentLedger et PayoutService diffusent chaque mouvement d'argent sur le
  // canal private-admin.
  try {
    const channel = realtimeClient().subscribe('private-admin');
    channel.bind('payment', refreshAll);
    channel.bind('payout', refreshAll);
    channel.bind('fraud', refreshAll);
    channel.bind('pusher:subscription_succeeded', () => setLivePill(true));
    channel.bind('pusher:subscription_error', () => setLivePill(false));
  } catch {
    setLivePill(false);
  }
}

function setLivePill(connected) {
  const pill = document.getElementById('live-pill');
  pill.textContent = connected ? 'Temps réel actif' : 'Temps réel indisponible — rafraîchissement 20 s';
  pill.className = `pill ${connected ? 'ok' : 'warn'}`;
}

function refreshAll() {
  loadMetrics();
  loadFraud();
  loadPayouts();
  loadTransactions();
}

function statCard(label, value, sub, glow = false) {
  return `
    <div class="statcard">
      <p class="statlabel">${escapeHtml(label)}</p>
      <p class="statvalue${glow ? ' glow' : ''}">${escapeHtml(value)}</p>
      <p class="statsub" style="margin-bottom:0;">${escapeHtml(sub)}</p>
    </div>
  `;
}

async function loadMetrics() {
  const el = document.getElementById('metrics');

  try {
    const m = await apiFetch('/admin/metrics');
    const services = [
      ['Paiement CinetPay', m.services.cinetpay_configured],
      ['Temps réel', m.services.realtime_configured],
      ['Notifications push', m.services.push_configured],
    ];

    el.innerHTML = `
      <div class="statgrid">
        ${statCard('Encaissé aujourd\'hui', formatMoney(m.payments.paid_today_cents), `${m.payments.paid_today_count} paiement(s)`, true)}
        ${statCard('Paiements échoués', String(m.payments.failed_today_count), "aujourd'hui")}
        ${statCard('En souffrance', String(m.payments.stale_unpaid_count), `non encaissées depuis +${m.payments.stale_after_minutes} min`)}
        ${statCard('Virements échoués', String(m.payouts.failed_count), formatMoney(m.payouts.failed_cents))}
        ${statCard('Virements en attente', String(m.payouts.pending_count), formatMoney(m.payouts.pending_cents))}
        ${statCard('Alertes fraude', String(m.fraud?.open_alerts ?? 0), `${m.fraud?.high_open_alerts ?? 0} critique(s)`)}
      </div>
      <p class="statsub" style="margin-top:14px;">
        ${services.map(([name, ok]) => `<span class="pill ${ok ? 'ok' : 'bad'}" style="padding:4px 10px;font-size:11px;margin-right:8px;">${escapeHtml(name)} ${ok ? 'configuré' : 'non configuré'}</span>`).join('')}
        <span style="margin-left:4px;">Dernière mesure : ${escapeHtml(m.generated_at)}</span>
      </p>
    `;
  } catch (error) {
    el.innerHTML = `<p class="state-msg">${escapeHtml(error.message)}</p>`;
  }
}

const FRAUD_SEVERITY = { high: 'bad', medium: 'warn', low: 'ok' };
const FRAUD_TYPE_LABEL = {
  mobile_money_reuse: 'N° mobile money réutilisé',
  gps_teleport: 'Saut GPS impossible',
  impossible_delivery: 'Livraison trop rapide',
  order_velocity: 'Cadence de commandes anormale',
  address_mismatch: 'Adresse incohérente',
};

async function loadFraud() {
  const el = document.getElementById('fraud-list');
  const status = document.getElementById('fraud-filter').value;

  try {
    const { alerts } = await apiFetch(`/admin/fraud-alerts?status=${status}`);

    if (alerts.length === 0) {
      el.innerHTML = '<p class="state-msg">Aucune alerte dans cette catégorie.</p>';

      return;
    }

    el.innerHTML = `
      <div class="mtable">
        <div class="mrow head">
          <div class="mname">Alerte</div>
          <div class="mtoggle">Gravité</div>
          <div class="mtoggle" style="width:200px;justify-content:flex-end;">Action</div>
        </div>
        ${alerts.map(fraudRow).join('')}
      </div>
    `;
  } catch (error) {
    el.innerHTML = `<p class="state-msg">${escapeHtml(error.message)}</p>`;
  }
}

function fraudRow(alert) {
  const who = alert.first_name ? `${alert.first_name} ${alert.last_name ?? ''} (${alert.role})` : '—';

  return `
    <div class="mrow" data-alert-id="${alert.id}">
      <div class="mname">${escapeHtml(FRAUD_TYPE_LABEL[alert.type] ?? alert.type)}
        <div class="d">${escapeHtml(alert.detail)} — ${escapeHtml(who)}${alert.order_id ? ` — commande #SV-${alert.order_id}` : ''} — ${escapeHtml(alert.created_at)}</div>
      </div>
      <div class="mtoggle"><span class="pill ${FRAUD_SEVERITY[alert.severity] ?? 'warn'}" style="padding:4px 10px;font-size:11px;">${escapeHtml(alert.severity)}</span></div>
      <div class="mtoggle" style="width:200px;justify-content:flex-end;gap:6px;">
        ${alert.status === 'open' ? `
          <button class="btn btn-ghost" data-fraud-action="reviewed" data-id="${alert.id}" style="padding:6px 10px;font-size:11.5px;">Vu</button>
          <button class="btn btn-ghost" data-fraud-action="dismissed" data-id="${alert.id}" style="padding:6px 10px;font-size:11.5px;">Écarter</button>
        ` : `<span class="state-msg" style="font-size:11.5px;">${escapeHtml(alert.status)}</span>`}
      </div>
    </div>
  `;
}

document.getElementById('fraud-list').addEventListener('click', async (event) => {
  const btn = event.target.closest('button[data-fraud-action]');
  if (!btn) return;
  btn.disabled = true;
  try {
    await apiFetch(`/admin/fraud-alerts/${btn.dataset.id}`, { method: 'PATCH', body: { status: btn.dataset.fraudAction } });
    loadFraud();
    loadMetrics();
  } catch (error) {
    btn.disabled = false;
    alert(error.message);
  }
});

async function loadPayouts() {
  const el = document.getElementById('payouts-list');
  const statut = document.getElementById('payout-filter').value;

  try {
    const { payouts } = await apiFetch(`/admin/payouts${statut ? `?statut=${statut}` : ''}`);

    if (payouts.length === 0) {
      el.innerHTML = '<p class="state-msg">Aucun virement dans cette catégorie.</p>';

      return;
    }

    el.innerHTML = `
      <div class="mtable">
        <div class="mrow head">
          <div class="mname">Bénéficiaire</div>
          <div class="mprice">Montant</div>
          <div class="mtoggle">Statut</div>
          <div class="mtoggle" style="width:150px;">Date</div>
        </div>
        ${payouts.map(payoutRow).join('')}
      </div>
    `;
  } catch (error) {
    el.innerHTML = `<p class="state-msg">${escapeHtml(error.message)}</p>`;
  }
}

function payoutRow(payout) {
  const name = payout.recipient_type === 'restaurant'
    ? (payout.restaurant_name ?? 'Restaurant')
    : `${payout.driver_first_name ?? 'Livreur'} ${payout.driver_last_name ?? ''}`.trim();

  return `
    <div class="mrow">
      <div class="mname">${escapeHtml(name)}
        <div class="d">Commande #SV-${payout.order_id} — ${payout.recipient_type === 'restaurant' ? 'restaurant' : 'livreur'}${payout.failure_reason ? ` — ${escapeHtml(payout.failure_reason)}` : ''}</div>
      </div>
      <div class="mprice">${formatMoney(payout.amount_cents)}</div>
      <div class="mtoggle"><span class="pill ${PAYOUT_PILL[payout.statut]}" style="padding:4px 10px;font-size:11px;">${PAYOUT_LABEL[payout.statut]}</span></div>
      <div class="mtoggle" style="width:150px;font-weight:400;font-size:11.5px;">${escapeHtml(payout.created_at)}</div>
    </div>
  `;
}

async function loadTransactions() {
  const el = document.getElementById('transactions-list');
  const status = document.getElementById('payment-filter').value;

  try {
    const { transactions } = await apiFetch(`/admin/transactions${status ? `?payment_status=${status}` : ''}`);

    if (transactions.length === 0) {
      el.innerHTML = '<p class="state-msg">Aucune commande dans cette catégorie.</p>';

      return;
    }

    el.innerHTML = `
      <div class="mtable">
        <div class="mrow head">
          <div class="mname">Commande</div>
          <div class="mprice">Montant</div>
          <div class="mtoggle">Paiement</div>
          <div class="mtoggle" style="width:110px;justify-content:flex-end;">Audit</div>
        </div>
        ${transactions.map(transactionRow).join('')}
      </div>
    `;
  } catch (error) {
    el.innerHTML = `<p class="state-msg">${escapeHtml(error.message)}</p>`;
  }
}

function transactionRow(tx) {
  const client = `${tx.client_first_name ?? ''} ${tx.client_last_name ?? ''}`.trim();

  return `
    <div class="mrow">
      <div class="mname">#SV-${tx.id} — ${escapeHtml(tx.restaurant_name)}
        <div class="d">${escapeHtml(client)} — ${escapeHtml(tx.created_at)} — livraison : ${escapeHtml(tx.status)}${tx.failed_payouts_count > 0 ? ` — ${tx.failed_payouts_count} virement(s) en échec` : ''}</div>
      </div>
      <div class="mprice">${formatMoney(tx.total_cents)}</div>
      <div class="mtoggle"><span class="pill ${PAYMENT_PILL[tx.payment_status]}" style="padding:4px 10px;font-size:11px;">${PAYMENT_LABEL[tx.payment_status]}</span></div>
      <div class="mtoggle" style="width:110px;justify-content:flex-end;">
        <button class="btn btn-ghost" data-audit-id="${tx.id}" type="button" style="padding:6px 12px;font-size:12px;">Historique</button>
      </div>
    </div>
  `;
}

document.getElementById('transactions-list').addEventListener('click', async (event) => {
  const btn = event.target.closest('button[data-audit-id]');
  if (!btn) return;

  const body = document.getElementById('audit-body');
  body.innerHTML = '<p class="state-msg">Chargement…</p>';
  document.getElementById('audit-overlay').hidden = false;

  try {
    const { order, events, payouts } = await apiFetch(`/admin/orders/${btn.dataset.auditId}/events`);

    body.innerHTML = `
      <h2 class="sectitle">Commande #SV-${order.id}</h2>
      <p class="statsub">${escapeHtml(order.restaurant_name)} — ${formatMoney(order.total_cents)} —
        <span class="pill ${PAYMENT_PILL[order.payment_status]}" style="padding:3px 9px;font-size:11px;">${PAYMENT_LABEL[order.payment_status]}</span>
      </p>
      <h3 class="sectitle" style="font-size:13px;">Événements</h3>
      <div class="mtable">
        ${events.map((e) => `
          <div class="mrow">
            <div class="mname" style="font-size:12.5px;">${escapeHtml(e.status)}<div class="d">par ${escapeHtml(e.actor_type)}</div></div>
            <div class="mtoggle" style="width:150px;font-weight:400;font-size:11.5px;">${escapeHtml(e.created_at)}</div>
          </div>`).join('') || '<div class="mrow"><div class="mname">Aucun événement.</div></div>'}
      </div>
      <h3 class="sectitle" style="font-size:13px;margin-top:16px;">Virements</h3>
      <div class="mtable">
        ${payouts.map((p) => `
          <div class="mrow">
            <div class="mname" style="font-size:12.5px;">${escapeHtml(p.recipient_type)}<div class="d">${escapeHtml(p.failure_reason ?? '')}</div></div>
            <div class="mprice">${formatMoney(p.amount_cents)}</div>
            <div class="mtoggle"><span class="pill ${PAYOUT_PILL[p.statut]}" style="padding:4px 10px;font-size:11px;">${PAYOUT_LABEL[p.statut]}</span></div>
          </div>`).join('') || '<div class="mrow"><div class="mname">Aucun virement déclenché.</div></div>'}
      </div>
    `;
  } catch (error) {
    body.innerHTML = `<p class="state-msg">${escapeHtml(error.message)}</p>`;
  }
});

function closeAudit() {
  document.getElementById('audit-overlay').hidden = true;
}
