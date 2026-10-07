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
  plugins: {
    SystemBars: {
      insetsHandling: 'css',
      initialViewportFitValueHint: 'cover',
      style: 'DARK',
    },
  },
}

export default config
