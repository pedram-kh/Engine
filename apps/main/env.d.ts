/// <reference types="vite/client" />

/** AH-121 C2: the Sentry release name, injected at build time (empty = none). */
declare const __SENTRY_RELEASE__: string

interface ImportMetaEnv {
  readonly VITE_API_BASE_URL: string
  readonly VITE_APP_NAME: string
  readonly VITE_SENTRY_DSN_MAIN?: string
  /** AH-121 C3: the catalyst-app DSN, read only inside the Capacitor shell. */
  readonly VITE_SENTRY_DSN_APP?: string
  /** AH-121 C3: test builds only (`1`, `crash`, `early`); never set for a submitted build. */
  readonly VITE_SENTRY_TEST_EVENT?: string
  readonly VITE_SUPPORT_EMAIL?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
