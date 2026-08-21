/**
 * Position réelle de l'appareil (Geolocation API du navigateur) — remplace les coordonnées
 * fixes (Paris) utilisées jusqu'ici en démo. Indispensable pour toute ville hors Paris
 * (ex: Abidjan) : sans ça, chaque distance/frais de livraison serait calculé depuis Paris.
 */
export function getCurrentPosition({ fallback = null, timeout = 8000 } = {}) {
  return new Promise((resolve) => {
    if (!('geolocation' in navigator)) {
      resolve(fallback);

      return;
    }

    navigator.geolocation.getCurrentPosition(
      (position) => resolve({ lat: position.coords.latitude, lng: position.coords.longitude }),
      () => resolve(fallback),
      { enableHighAccuracy: false, maximumAge: 5 * 60 * 1000, timeout }
    );
  });
}
