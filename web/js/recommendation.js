import { apiFetch } from './api.js';
import { isLoggedIn } from './auth.js';
import { escapeHtml, formatEuros } from './format.js';

/**
 * Carte "Pour toi ce soir" — suggère le plat le plus souvent recommandé au client d'après ses
 * commandes livrées (voir OrderController::recommendationForClient, jamais de plat inventé).
 */
export async function initRecommendationCard() {
  const slot = document.getElementById('recommendation-slot');
  if (!slot || !isLoggedIn()) return;

  try {
    const { recommendation } = await apiFetch('/recommendations/mine');
    if (!recommendation) return;

    slot.innerHTML = `
      <a class="reco-card" href="/restaurant.html?id=${recommendation.restaurant_id}">
        <div class="reco-photo"></div>
        <div class="reco-info">
          <span class="reco-eyebrow">Pour toi ce soir</span>
          <div class="reco-name">${escapeHtml(recommendation.name)}</div>
          <div class="reco-sub">${escapeHtml(recommendation.restaurant_name)} · ${formatEuros(recommendation.price_cents)}</div>
        </div>
        <span class="reco-cta">
          Revoir
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
        </span>
      </a>
    `;
  } catch {
    // Silencieux — pas de carte affichée en cas d'erreur réseau.
  }
}
