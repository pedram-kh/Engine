import type { CapacitorConfig } from '@capacitor/cli'

/**
 * The Catalyst creator app (AH-100): this SPA's `dist/`, built with
 * `--mode native-development` or `--mode native-production`, wrapped for
 * iOS and Android.
 *
 * Cleartext HTTP (the local API on http://localhost:8000) is allowed only
 * in Android DEBUG builds, by the debug source set's network security
 * config — never here, so a release build cannot inherit it. iOS reaches
 * localhost under ATS's local-networking exemption.
 */
const config: CapacitorConfig = {
  appId: 'com.catalystgrowth.platform',
  appName: 'Catalyst',
  webDir: 'dist',
  // AH-102: the Firebase messaging plugin's package identity collides under SwiftPM
  // unless it is symlinked (the plugin README; needs Capacitor CLI 8.4.0+).
  experimental: {
    ios: {
      spm: {
        packageOptions: {
          '@capacitor-firebase/messaging': { symlink: true },
        },
      },
    },
  },
  plugins: {
    // AH-102 (P-5): a push that arrives while the app is open shows no system
    // banner on iOS either — the in-app bell refresh is the foreground signal,
    // as on Android. (The plugin default would be alert, badge and sound.)
    FirebaseMessaging: {
      presentationOptions: [],
    },
    SystemBars: {
      insetsHandling: 'css',
      initialViewportFitValueHint: 'cover',
      style: 'DARK',
    },
  },
}

export default config
