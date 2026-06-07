/**
 * Deploy-environment cue for the persistent admin env banner
 * (Sprint 13, D-2; `docs/09-ADMIN-PANEL.md` § 5.2).
 *
 * The banner is a DISPLAY cue answering "which environment is this
 * bundle pointed at" — a build-time fact, NOT an authorization control
 * (Q4). It is therefore driven by the `VITE_DEPLOY_ENV` build var rather
 * than an `/admin/me` round-trip: coupling a cosmetic banner to an API
 * call would buy no security and add a cold-load dependency.
 *
 * Resolution:
 *   - `VITE_DEPLOY_ENV` ∈ {local, staging, production} drives the banner.
 *   - Any unset / unrecognised value falls back to `local` — the safest
 *     default (the most prominent "you are NOT in production" framing is
 *     wrong-in-the-harmless-direction; a missing var never silently
 *     paints a prod-looking banner on a dev bundle).
 *
 * The colour mapping mirrors the spec: local = gray, staging = blue,
 * production = red ("PRODUCTION — ALL ACTIONS LOGGED").
 */

export type DeployEnv = 'local' | 'staging' | 'production'

export interface DeployEnvBanner {
  env: DeployEnv
  /** Vuetify theme colour token for the banner background. */
  color: 'grey' | 'blue' | 'red'
  /** i18n key for the banner label. */
  labelKey: string
}

const KNOWN_ENVS: ReadonlyArray<DeployEnv> = ['local', 'staging', 'production']

/**
 * Pure resolver — exported so the unit test can exercise the mapping
 * matrix (including the unknown-value fallback) without a Vite build.
 */
export function resolveDeployEnv(raw: string | undefined): DeployEnv {
  if (typeof raw === 'string' && (KNOWN_ENVS as ReadonlyArray<string>).includes(raw)) {
    return raw as DeployEnv
  }
  return 'local'
}

const BANNER_BY_ENV: Record<DeployEnv, DeployEnvBanner> = {
  local: { env: 'local', color: 'grey', labelKey: 'app.env.local' },
  staging: { env: 'staging', color: 'blue', labelKey: 'app.env.staging' },
  production: { env: 'production', color: 'red', labelKey: 'app.env.production' },
}

/**
 * Build the banner descriptor for a given raw env string. Exported so
 * the composable + the unit test share one mapping.
 */
export function deployEnvBanner(raw: string | undefined): DeployEnvBanner {
  return BANNER_BY_ENV[resolveDeployEnv(raw)]
}

/**
 * Composable wrapper read by `AdminLayout`. Reads the build var once at
 * call time; the value never changes within a running bundle.
 */
export function useDeployEnv(): DeployEnvBanner {
  return deployEnvBanner(import.meta.env.VITE_DEPLOY_ENV as string | undefined)
}
