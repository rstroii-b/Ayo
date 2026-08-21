import { apiFetch } from './api.js';
import { persistSession } from './auth.js';

/**
 * Connexion biométrique (Face ID, empreinte, Windows Hello…) via WebAuthn/passkeys.
 * Utilise les API JSON natives du navigateur (parseCreationOptionsFromJSON / credential.toJSON)
 * — plus de conversion manuelle base64⇄ArrayBuffer, le navigateur s'en charge.
 */
export function webauthnSupported() {
  return !!(
    window.PublicKeyCredential &&
    typeof PublicKeyCredential.parseCreationOptionsFromJSON === 'function' &&
    typeof PublicKeyCredential.parseRequestOptionsFromJSON === 'function'
  );
}

/** Propose l'activation d'une clé biométrique pour le compte connecté (page Compte). */
export async function registerPasskey(label) {
  const { publicKey } = await apiFetch('/webauthn/register/options', { method: 'POST', body: {} });
  const options = PublicKeyCredential.parseCreationOptionsFromJSON(publicKey);

  const credential = await navigator.credentials.create({ publicKey: options });

  await apiFetch('/webauthn/register/verify', {
    method: 'POST',
    body: { ...credential.toJSON(), label },
  });
}

export async function listPasskeys() {
  const { credentials } = await apiFetch('/webauthn/credentials');

  return credentials;
}

export async function deletePasskey(id) {
  await apiFetch(`/webauthn/credentials/${id}`, { method: 'DELETE' });
}

/** Connexion par clé biométrique — identifie d'abord le compte par email. */
export async function loginWithPasskey(email) {
  const { publicKey } = await apiFetch('/webauthn/login/options', { method: 'POST', body: { email } });
  const options = PublicKeyCredential.parseRequestOptionsFromJSON(publicKey);

  const credential = await navigator.credentials.get({ publicKey: options });
  const json = credential.toJSON();

  const { token } = await apiFetch('/webauthn/login/verify', {
    method: 'POST',
    body: {
      email,
      id: json.id,
      clientDataJSON: json.response.clientDataJSON,
      authenticatorData: json.response.authenticatorData,
      signature: json.response.signature,
    },
  });

  return persistSession(token);
}
