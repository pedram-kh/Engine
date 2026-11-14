/**
 * AH-121 C3 source-inspection gates for the native Sentry wiring (main app only):
 * the two DSNs never mix, the Capacitor SDK stays out of the web entry, the test-event
 * path is reachable only through its build flag, and the plugin pin and Package.swift
 * entry are guarded.
 */

import { readFileSync, readdirSync, statSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../../..')
const read = (rel: string): string => readFileSync(path.join(ROOT, rel), 'utf8')
const code = (text: string): string =>
  text.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '')

function walk(dir: string, out: string[] = []): string[] {
  for (const name of readdirSync(dir)) {
    const full = path.join(dir, name)
    if (statSync(full).isDirectory()) {
      walk(full, out)
    } else if (/\.(ts|vue)$/.test(name) && !/\.spec\.ts$/.test(name)) {
      out.push(full)
    }
  }
  return out
}
const SRC_FILES = walk(path.join(ROOT, 'src'))
const rel = (files: string[]): string[] => files.map((f) => path.relative(ROOT, f)).sort()

const INIT = code(read('src/core/observability/initSentry.ts'))
const NATIVE = code(read('src/core/observability/sentryNative.ts'))
const TEST_EVENT = code(read('src/core/observability/sentryTestEvent.ts'))
const PKG = JSON.parse(read('package.json')) as {
  scripts: Record<string, string>
  dependencies: Record<string, string>
}

describe('the two DSNs never mix', () => {
  it('VITE_SENTRY_DSN_MAIN is read in exactly one place: the web branch of initSentry', () => {
    const readers = SRC_FILES.filter((f) =>
      code(readFileSync(f, 'utf8')).includes('VITE_SENTRY_DSN_MAIN'),
    )
    expect(rel(readers)).toEqual(['src/core/observability/initSentry.ts'])
    const nativeBranch = INIT.slice(
      INIT.indexOf('if (isNativePlatform())'),
      INIT.indexOf('return startSentry('),
    )
    expect(nativeBranch).not.toContain('VITE_SENTRY_DSN_MAIN')
    expect(INIT.indexOf('VITE_SENTRY_DSN_MAIN')).toBeGreaterThan(
      INIT.indexOf('return startSentry('),
    )
  })

  it('VITE_SENTRY_DSN_APP is read in exactly one place: the native branch of initSentry', () => {
    const readers = SRC_FILES.filter((f) =>
      code(readFileSync(f, 'utf8')).includes('VITE_SENTRY_DSN_APP'),
    )
    expect(rel(readers)).toEqual(['src/core/observability/initSentry.ts'])
    expect(INIT.indexOf('VITE_SENTRY_DSN_APP')).toBeLessThan(INIT.indexOf('return startSentry('))
    expect(INIT.indexOf('VITE_SENTRY_DSN_APP')).toBeGreaterThan(
      INIT.indexOf('if (isNativePlatform())'),
    )
  })

  it('the native env files carry no MAIN DSN and an empty APP DSN (tracked file)', () => {
    const env = read('.env.native-development')
    expect(env).not.toMatch(/VITE_SENTRY_DSN_MAIN/)
    expect(env).toMatch(/^VITE_SENTRY_DSN_APP=$/m)
  })
})

describe('the Capacitor SDK stays out of the web entry', () => {
  it('@sentry/capacitor is imported only by sentryNative.ts and sentryTestEvent.ts', () => {
    const importers = SRC_FILES.filter((f) =>
      /from '@sentry\/capacitor'/.test(readFileSync(f, 'utf8')),
    )
    expect(rel(importers)).toEqual([
      'src/core/observability/sentryNative.ts',
      'src/core/observability/sentryTestEvent.ts',
    ])
  })

  it('initSentry reaches sentryNative only through a dynamic import, inside the native branch', () => {
    // no static import of any form (named, namespace or side-effect)
    expect(INIT).not.toMatch(/^\s*import\s[^(]*['"]\.\/sentryNative['"]/m)
    expect(INIT).toMatch(/import\('\.\/sentryNative'\)/)
    expect(INIT.indexOf("import('./sentryNative')")).toBeGreaterThan(
      INIT.indexOf('if (isNativePlatform())'),
    )
    expect(INIT.indexOf("import('./sentryNative')")).toBeLessThan(
      INIT.indexOf('return startSentry('),
    )
  })

  it('the early buffer starts before the native module is requested', () => {
    expect(INIT.indexOf('startEarlyErrorBuffer(app)')).toBeGreaterThan(-1)
    expect(INIT.indexOf('startEarlyErrorBuffer(app)')).toBeLessThan(
      INIT.indexOf("import('./sentryNative')"),
    )
  })
})

describe('native options', () => {
  it('turns off sessions, watchdog, app hang and failed-request capture, and keeps pii off', () => {
    expect(NATIVE).toMatch(/sendDefaultPii:\s*false/)
    expect(NATIVE).toMatch(/enableAutoSessionTracking:\s*false/)
    expect(NATIVE).toMatch(/enableWatchdogTerminationTracking:\s*false/)
    expect(NATIVE).toMatch(/enableAppHangTracking:\s*false/)
    expect(NATIVE).toMatch(/enableCaptureFailedRequests:\s*false/)
    expect(NATIVE).toMatch(/console:\s*false/)
    expect(NATIVE).toMatch(/dom:\s*false/)
    expect(NATIVE).toMatch(/attachProps:\s*false/)
    expect(NATIVE).toMatch(/beforeSend:\s*\(event, hint\) => scrubEvent\(event, hint\)/)
    expect(NATIVE).toMatch(/beforeBreadcrumb:\s*\(breadcrumb\) => scrubBreadcrumb\(breadcrumb\)/)
  })

  it('pins the plugin integration list literally', () => {
    const list = NATIVE.match(/NATIVE_ALLOWED_INTEGRATIONS = \[([\s\S]*?)\] as const/)?.[1] ?? ''
    expect(list.replace(/\s+/g, '')).toBe(
      "'RewriteFrames','Release','EventOrigin','SdkInfo','DeviceContext',",
    )
  })

  it('applies the user only after waiting for the client, and replays early errors after that', () => {
    expect(NATIVE.indexOf('await waitForClient()')).toBeGreaterThan(
      NATIVE.indexOf('SentryCapacitor.init('),
    )
    expect(NATIVE.indexOf('watch(')).toBeGreaterThan(NATIVE.indexOf('await waitForClient()'))
    expect(NATIVE.indexOf('drainEarlyErrors((error)')).toBeGreaterThan(NATIVE.indexOf('watch('))
  })

  it('signed out is the fixed string; no install-id or device value is ever used as a user', () => {
    expect(NATIVE).toMatch(/SIGNED_OUT_USER_ID = 'signed-out'/)
    for (const banned of [
      'installationID',
      'installId',
      'deviceId',
      'Device.getId',
      'getInfo().identifier',
    ]) {
      expect(NATIVE).not.toContain(banned)
      expect(TEST_EVENT).not.toContain(banned)
    }
  })

  it('the release is read from the bundle, never from the web build constant', () => {
    expect(NATIVE).not.toContain('__SENTRY_RELEASE__')
    expect(NATIVE).toMatch(/CapacitorApp\.getInfo\(\)/)
  })
})

describe('the test-event path is reachable only through its build flag', () => {
  it('sentryTestEvent is imported dynamically, each time under an import.meta.env flag check', () => {
    const importers = SRC_FILES.filter((f) => /sentryTestEvent/.test(code(readFileSync(f, 'utf8'))))
    expect(rel(importers)).toEqual(['src/core/observability/sentryNative.ts'])
    expect(NATIVE).not.toMatch(/from '\.\/sentryTestEvent'/)
    const imports = [...NATIVE.matchAll(/import\('\.\/sentryTestEvent'\)/g)]
    expect(imports).toHaveLength(2)
    for (const match of imports) {
      const before = NATIVE.slice(Math.max(0, (match.index ?? 0) - 160), match.index)
      expect(before).toMatch(/if \(import\.meta\.env\.VITE_SENTRY_TEST_EVENT/)
    }
  })

  it('the flag is read nowhere else in src', () => {
    const readers = SRC_FILES.filter((f) =>
      code(readFileSync(f, 'utf8')).includes('VITE_SENTRY_TEST_EVENT'),
    )
    expect(rel(readers)).toEqual(['src/core/observability/sentryNative.ts'])
  })

  it('no package script and no committed env file sets the flag', () => {
    expect(JSON.stringify(PKG.scripts)).not.toContain('VITE_SENTRY_TEST_EVENT')
    for (const file of ['.env', '.env.native-development', '.env.development', '.env.production']) {
      try {
        expect(read(file)).not.toContain('VITE_SENTRY_TEST_EVENT')
      } catch (error) {
        if ((error as NodeJS.ErrnoException).code !== 'ENOENT') {
          throw error
        }
      }
    }
  })
})

describe('the plugin pin and the sync check', () => {
  it('@sentry/capacitor is pinned exactly, next to the @sentry/vue it peers with', () => {
    expect(PKG.dependencies['@sentry/capacitor']).toBe('4.5.0')
    expect(PKG.dependencies['@sentry/vue']).toBe('10.76.2')
  })

  it('sync:native runs cap sync, then the .map check over both native folders, then the plugin check', () => {
    const script = PKG.scripts['sync:native'] ?? ''
    expect(script.indexOf('cap sync')).toBe(0)
    expect(
      script.indexOf(
        'check-no-sourcemaps.mjs ios/App/App/public android/app/src/main/assets/public',
      ),
    ).toBeGreaterThan(script.indexOf('cap sync'))
    expect(script.indexOf('check-native-plugins.mjs')).toBeGreaterThan(
      script.indexOf('check-no-sourcemaps.mjs'),
    )
  })
})

describe('the Catalyst patch of @sentry/capacitor (native options the plugin cannot pass)', () => {
  const rootPkg = JSON.parse(readFileSync(path.resolve(ROOT, '../../package.json'), 'utf8')) as {
    pnpm?: { patchedDependencies?: Record<string, string> }
  }
  const patchPath = rootPkg.pnpm?.patchedDependencies?.['@sentry/capacitor@4.5.0']

  it('is registered for exactly the pinned version', () => {
    expect(patchPath).toBe('patches/@sentry__capacitor@4.5.0.patch')
  })

  it('sets every native option off, as added lines in the plugin Swift source', () => {
    const patch = readFileSync(path.resolve(ROOT, '../..', patchPath ?? 'missing'), 'utf8')
    for (const line of [
      'options.enableAutoSessionTracking = false',
      'options.enableNetworkBreadcrumbs = false',
      'options.enableNetworkTracking = false',
      'options.enableCaptureFailedRequests = false',
      'options.enableAutoBreadcrumbTracking = false',
      'options.enableMetrics = false',
      'options.sessionReplay.sessionSampleRate = 0',
      'options.sessionReplay.onErrorSampleRate = 0',
    ]) {
      expect(patch).toContain(`+        ${line}`)
    }
  })
})
