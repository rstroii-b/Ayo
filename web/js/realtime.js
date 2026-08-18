import { getToken } from './api.js';

const API_BASE_URL = ['localhost', '127.0.0.1'].includes(window.location.hostname)
  ? 'http://localhost:8000/api/v1'
  : 'https://api-ayo.jobivoire.com/api/v1';

let pusher = null;

/** Connexion Pusher partagée — les canaux privés s'authentifient avec le JWT de la session. */
export function realtimeClient() {
  if (pusher) return pusher;

  pusher = new Pusher('cd9a8b301fd688c698eb', {
    cluster: 'eu',
    authEndpoint: `${API_BASE_URL}/realtime/auth`,
    auth: { headers: { Authorization: `Bearer ${getToken()}` } },
  });

  return pusher;
}
