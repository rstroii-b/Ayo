import { escapeHtml } from './format.js';

/**
 * Briques d'interface partagées : toast, états de chargement / vide / erreur, bandeaux.
 *
 * Chaque écran fabriquait les siens à la main, avec trois conséquences :
 *   - des messages différents pour la même situation d'un écran à l'autre ;
 *   - un « Chargement… » en texte gris là où l'accueil affichait déjà des squelettes ;
 *   - surtout, des interpolations non échappées du type
 *     `content.innerHTML = '<p>' + error.message + '</p>'`. Le message vient de l'API, donc
 *     du serveur, donc parfois d'une donnée saisie par un tiers (nom de commerce, libellé
 *     d'article, motif de refus KYC). Injecter ça dans du HTML sans échapper, c'est ouvrir un
 *     XSS par le chemin le plus discret qui soit : la gestion d'erreur.
 *
 * Tout ce qui sort d'ici passe par escapeHtml.
 */

/* ------------------------------------------------------------------ Toast */

let toastTimer = null;

/**
 * Message bref confirmant une action. `role="status"` + `aria-live="polite"` : l'information
 * est aussi annoncée aux lecteurs d'écran, au lieu d'être réservée aux voyants.
 *
 * @param {string} message
 * @param {{tone?: 'success'|'error', duration?: number}} [options]
 */
export function showToast(message, { tone = 'success', duration = 2200 } = {}) {
  let toast = document.getElementById('ayo-toast');

  if (!toast) {
    toast = document.createElement('div');
    toast.id = 'ayo-toast';
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', 'polite');
    document.body.appendChild(toast);
  }

  toast.className = `toast toast--${tone === 'error' ? 'error' : 'success'}`;
  toast.textContent = message;

  // Reflow forcé : sans lui, deux toasts consécutifs ne rejouent pas la transition.
  void toast.offsetWidth;
  toast.classList.add('show');

  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => toast.classList.remove('show'), duration);
}

/* ------------------------------------------------- États d'un conteneur */

/**
 * Squelettes de chargement. Reprend ceux de l'accueil plutôt qu'un texte : l'utilisateur voit
 * la forme de ce qui arrive, et la page ne saute pas au moment du remplissage.
 *
 * @param {number} [rows]
 */
export function skeletonListHtml(rows = 3) {
  const card = `
    <div class="skeleton-rcard">
      <div class="skel skel-photo"></div>
      <div class="skel-lines">
        <div class="skel skel-line w60"></div>
        <div class="skel skel-line w40"></div>
        <div class="skel skel-line w30"></div>
      </div>
    </div>`;

  return Array.from({ length: rows }, () => card).join('');
}

export function renderLoading(container, rows = 3) {
  if (!container) return;

  container.setAttribute('aria-busy', 'true');
  container.innerHTML = skeletonListHtml(rows);
}

/**
 * État vide — une explication et, quand c'est possible, une action. Un écran vide sans issue
 * laisse l'utilisateur bloqué sans savoir si l'application a échoué ou s'il n'y a simplement
 * rien à voir.
 *
 * @param {Element} container
 * @param {{icon?: string, title: string, text?: string, action?: {label: string, href: string}}} options
 */
export function renderEmpty(container, { icon = '∅', title, text = '', action = null }) {
  if (!container) return;

  container.removeAttribute('aria-busy');
  container.innerHTML = `
    <div class="state state--empty">
      <div class="state-icon" aria-hidden="true">${escapeHtml(icon)}</div>
      <p class="state-title">${escapeHtml(title)}</p>
      ${text ? `<p class="state-text">${escapeHtml(text)}</p>` : ''}
      ${action ? `
        <div class="state-actions">
          <a class="btn btn-primary" href="${escapeHtml(action.href)}">${escapeHtml(action.label)}</a>
        </div>` : ''}
    </div>`;
}

/**
 * État d'erreur. Deux niveaux de message : une phrase compréhensible, et le détail technique
 * seulement s'il apporte quelque chose. Un bouton « Réessayer » est proposé dès qu'un
 * rechargement a une chance d'aboutir — c'est le cas de toutes les erreurs réseau.
 *
 * @param {Element} container
 * @param {Error & {status?: number, detail?: string, isNetwork?: boolean}} error
 * @param {{title?: string, onRetry?: () => void}} [options]
 */
export function renderError(container, error, { title = null, onRetry = null } = {}) {
  if (!container) return;

  const heading = title ?? defaultErrorTitle(error);
  const detail = errorDetail(error);
  const retryId = `retry-${Math.random().toString(36).slice(2, 9)}`;

  container.removeAttribute('aria-busy');
  container.innerHTML = `
    <div class="state state--error" role="alert">
      <div class="state-icon" aria-hidden="true">!</div>
      <p class="state-title">${escapeHtml(heading)}</p>
      ${detail ? `<p class="state-text">${escapeHtml(detail)}</p>` : ''}
      ${onRetry ? `
        <div class="state-actions">
          <button class="btn btn-ghost" type="button" id="${retryId}">Réessayer</button>
        </div>` : ''}
    </div>`;

  if (onRetry) {
    document.getElementById(retryId)?.addEventListener('click', onRetry);
  }
}

/**
 * Message d'erreur adapté à la situation. Un 500 n'appelle pas la même phrase qu'une coupure
 * réseau : dire « vérifie ta connexion » alors que le serveur est en panne envoie l'utilisateur
 * chercher un problème qui n'est pas chez lui.
 */
export function defaultErrorTitle(error) {
  if (error?.isNetwork) return 'Connexion interrompue';
  if (error?.status === 401) return 'Session expirée';
  if (error?.status === 403) return 'Accès refusé';
  if (error?.status === 404) return 'Introuvable';
  if (error?.status === 429) return 'Trop de tentatives';
  if (error?.status >= 500) return 'Le service est momentanément indisponible';

  return 'Une erreur est survenue';
}

/** Détail affichable. Les messages techniques bruts (« Erreur 500 ») n'apportent rien au client. */
export function errorDetail(error) {
  if (error?.isNetwork) {
    return 'Vérifie ta connexion internet, puis réessaie.';
  }

  if (error?.detail) return error.detail;
  if (typeof error?.message === 'string' && !/^Erreur \d+$/.test(error.message)) return error.message;

  return '';
}

/* ------------------------------------------------------------- Bandeaux */

/**
 * Bandeau contextuel dans un formulaire. Le texte passe par textContent : aucune interpolation
 * HTML n'est possible, même si le message vient du serveur.
 *
 * @param {Element} element
 * @param {string} message
 * @param {'error'|'success'|'info'} [tone]
 */
export function showAlert(element, message, tone = 'error') {
  if (!element) return;

  element.className = `alert alert--${tone}`;
  element.textContent = message;
  element.hidden = false;
}

export function hideAlert(element) {
  if (!element) return;

  element.hidden = true;
  element.textContent = '';
}

/* -------------------------------------------------------------- Boutons */

/**
 * Bascule un bouton en « action en cours ». `aria-busy` est porté par le bouton lui-même :
 * son état est ainsi annoncé, et le CSS peut afficher l'indicateur sans que le libellé
 * disparaisse (remplacer le texte fait perdre le contexte à qui utilise un lecteur d'écran).
 */
export function setBusy(button, busy, busyLabel = null) {
  if (!button) return;

  if (busy) {
    button.dataset.idleLabel ??= button.textContent;
    button.setAttribute('aria-busy', 'true');
    button.disabled = true;
    if (busyLabel) button.textContent = busyLabel;

    return;
  }

  button.removeAttribute('aria-busy');
  button.disabled = false;
  if (button.dataset.idleLabel) {
    button.textContent = button.dataset.idleLabel;
    delete button.dataset.idleLabel;
  }
}

/* --------------------------------------------------------------- Modale */

/**
 * Ouvre une modale accessible : défilement de la page bloqué, focus déplacé dedans, fermeture
 * à l'Échap, focus rendu à l'élément d'origine à la fermeture. Sans cela, la modale du menu
 * restait invisible au clavier — on tabulait dans la page derrière elle.
 *
 * @returns {() => void} la fonction de fermeture
 */
export function openModal(overlay, { onClose = null } = {}) {
  if (!overlay) return () => {};

  const previouslyFocused = document.activeElement;
  overlay.hidden = false;
  document.body.classList.add('modal-open');

  const focusable = () => [
    ...overlay.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'),
  ].filter((el) => !el.hasAttribute('disabled') && el.offsetParent !== null);

  focusable()[0]?.focus();

  function onKeydown(event) {
    if (event.key === 'Escape') {
      close();

      return;
    }

    if (event.key !== 'Tab') return;

    // Piège à focus : la tabulation tourne en boucle dans la modale au lieu d'en sortir
    // discrètement vers la page masquée derrière.
    const items = focusable();
    if (items.length === 0) return;

    const first = items[0];
    const last = items[items.length - 1];

    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function close() {
    overlay.hidden = true;
    document.body.classList.remove('modal-open');
    document.removeEventListener('keydown', onKeydown, true);
    if (previouslyFocused instanceof HTMLElement) previouslyFocused.focus();
    onClose?.();
  }

  document.addEventListener('keydown', onKeydown, true);

  return close;
}
