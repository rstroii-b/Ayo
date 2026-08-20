if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    // updateViaCache:'none' — sinon le fichier sw.js lui-même reste soumis au cache HTTP du
    // navigateur (comportement par défaut 'imports'), et un déploiement peut rester invisible
    // pour un visiteur revenant sur le site.
    navigator.serviceWorker.register('/sw.js', { updateViaCache: 'none' }).catch(() => {
      // Installation impossible (hors-ligne, navigateur non compatible) — l'app continue de
      // fonctionner normalement en ligne, simplement sans mise en cache.
    });
  });
}
