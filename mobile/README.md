# Ayo — projet mobile (Capacitor)

Enveloppe native du front (`../web`) pour publication sur Google Play et l'App Store,
via [Capacitor](https://capacitorjs.com/). Le contenu web est **copié dans l'app** (pas
chargé depuis une URL distante) — nécessaire pour passer la review Apple, qui rejette les
apps qui ne sont qu'un site web dans une coquille.

## État actuel (vérifié dans cet environnement)

- **Android** : build local réussi (`BUILD SUCCESSFUL`, APK de 4,9 Mo généré). Icônes et
  écran de démarrage générés à partir du logo, toutes densités (mdpi → xxxhdpi), icône
  adaptative (Android 8+) avec fond de marque `#14181B`.
- **iOS** : projet Xcode généré (`ios/App/App.xcworkspace`), icône et écran de démarrage en
  place. **Compilation non testée** — nécessite Xcode, donc un Mac. Cet environnement est
  Windows, impossible d'aller plus loin ici sur cette partie.

## Prérequis

- Node.js + npm (déjà présents)
- Android : JDK 17+, Android SDK (`ANDROID_HOME`) — déjà configurés ici
- iOS : macOS + Xcode + CocoaPods — **à faire sur un Mac**

## Rebuild après une modif du front (`web/`)

```bash
cd mobile
npx cap sync          # recopie web/ dans android/ et ios/
```

## Android — build local

```bash
cd mobile/android
./gradlew assembleDebug              # APK de test (non signé release) — voir outputs/apk/debug/
./gradlew bundleRelease              # AAB pour Play Store — nécessite une clé de signature (voir ci-dessous)
```

### Signature pour la publication (Play Store)

Play Store exige un App Bundle (`.aab`) signé avec une clé de release (jamais celle de debug).

```bash
keytool -genkey -v -keystore ayo-release.keystore -alias ayo -keyalg RSA -keysize 2048 -validity 10000
```

Configurer ensuite `android/app/build.gradle` (bloc `signingConfigs`) avec le chemin du
keystore et les mots de passe — **ne jamais committer le keystore ni les mots de passe**.

## iOS — sur un Mac

```bash
cd mobile
npx cap sync ios
cd ios/App
pod install
open App.xcworkspace     # puis Product > Archive dans Xcode pour soumettre à l'App Store
```

## Icônes / écran de démarrage

Sources dans `resources/` (générées depuis `../web/assets/logo.png`, sans le texte
"food delivery", sur fond `#14181B`). Pour régénérer après un changement de logo, relancer
les scripts Python utilisés pour cette première génération (icônes Android : script dédié
par densité ; iOS : `resources/icon.png` en 1024×1024 copié directement dans
`ios/App/App/Assets.xcassets/AppIcon.appiconset/`).

## Avant de soumettre aux stores

- [ ] Compte Apple Developer (99$/an) et Google Play Console (25$ une fois)
- [ ] Captures d'écran par taille d'appareil exigée
- [ ] Description, mots-clés, catégorie
- [ ] Politique de confidentialité publique — déjà en place : `https://ayo.jobivoire.com/privacy.html`
- [ ] Compiler avec les vraies clés Stripe si passage en production réelle
- [ ] Notifications push natives (APNs/FCM) — le Web Push actuel fonctionne dans la WebView
      Capacitor, mais des notifications 100% natives (icône, badge sur l'icône d'app) demandent
      le plugin `@capacitor/push-notifications` — pas encore ajouté
