if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => {
      // Installation impossible (hors-ligne, navigateur non compatible) — l'app continue de
      // fonctionner normalement en ligne, simplement sans mise en cache.
    });
  });
}
