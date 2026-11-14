/**
 * AH-121 C2: build-time Sentry helpers (release name, source-map upload settings).
 *
 * MIRRORED BYTE-FOR-BYTE between `apps/main` and `apps/admin` (parity-tested).
 * The surface name is a parameter, never hard-coded here.
 *
 * Release (sentry-plan.md B4): named by CONTENT, not by commit, because the
 * Bitbucket mirror rewrites commit SHAs but keeps tree hashes:
 *   `catalyst-<surface>@<first 12 of sha1(tree(HEAD:apps/<surface>) + tree(HEAD:packages))>`
 * `SENTRY_RELEASE` overrides. A dirty tree appends `-dirty`. With neither git nor
 * the override there is NO release and a loud warning (never an invented name).
 * The runtime `release` and the source-map upload both read this one value, so
 * they cannot diverge.
 *
 * Source maps (B2): emitted `hidden` and uploaded ONLY when `SENTRY_AUTH_TOKEN`
 * is present and a release exists; the plugin deletes the `.map` files after
 * upload, and `scripts/check-no-sourcemaps.mjs` fails the build if any remain.
 */

import { createHash } from 'node:crypto'
import { execFileSync } from 'node:child_process'

import { sentryVitePlugin } from '@sentry/vite-plugin'
import type { PluginOption } from 'vite'

export type GitRunner = (args: readonly string[]) => string

export interface ReleaseResolution {
  /** Empty string means "no release". */
  readonly release: string
  /** Set when there is no release: print it loudly. */
  readonly warning: string | null
}

export function defaultGit(cwd: string): GitRunner {
  return (args) =>
    execFileSync('git', [...args], {
      cwd,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'ignore'],
    }).trim()
}

export function resolveRelease(
  surface: string,
  env: Readonly<Record<string, string | undefined>>,
  git: GitRunner,
): ReleaseResolution {
  const override = (env.SENTRY_RELEASE ?? '').trim()
  if (override !== '') {
    return { release: override, warning: null }
  }
  try {
    const app = git(['rev-parse', `HEAD:apps/${surface}`])
    const packages = git(['rev-parse', 'HEAD:packages'])
    if (!/^[0-9a-f]{40,64}$/.test(app) || !/^[0-9a-f]{40,64}$/.test(packages)) {
      throw new Error('unexpected tree hash')
    }
    const hash = createHash('sha1')
      .update(app + packages)
      .digest('hex')
      .slice(0, 12)
    // `:/` anchors the pathspec at the repo root, whatever directory the build runs in.
    const dirty = git(['status', '--porcelain', '--', `:/apps/${surface}`, ':/packages']) !== ''
    return { release: `catalyst-${surface}@${hash}${dirty ? '-dirty' : ''}`, warning: null }
  } catch {
    return {
      release: '',
      warning:
        `[sentry] WARNING: no release for catalyst-${surface}. git is not available here and SENTRY_RELEASE is not set. ` +
        'Events will arrive without a release and source maps cannot be matched. Set SENTRY_RELEASE or build from a git checkout.',
    }
  }
}

export interface SentryBuildSettings {
  /** Vite `build.sourcemap`. */
  readonly sourcemap: 'hidden' | false
  readonly plugins: PluginOption[]
}

export function sentryBuildSettings(
  release: string,
  env: Readonly<Record<string, string | undefined>>,
  distGlob: string,
): SentryBuildSettings {
  const token = (env.SENTRY_AUTH_TOKEN ?? '').trim()
  if (token === '' || release === '') {
    return { sourcemap: false, plugins: [] }
  }
  return {
    sourcemap: 'hidden',
    plugins: [
      sentryVitePlugin({
        authToken: token,
        org: env.SENTRY_ORG,
        project: env.SENTRY_PROJECT,
        url: env.SENTRY_URL,
        release: { name: release },
        sourcemaps: { filesToDeleteAfterUpload: [distGlob] },
        telemetry: false,
      }),
    ],
  }
}
