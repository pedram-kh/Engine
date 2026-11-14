/**
 * AH-121 C2 source-inspection gates (PROJECT-WORKFLOW §5.1) for the SPA's Sentry
 * wiring. Mirrored in both SPAs; the per-SPA names come from the app's own
 * package.json so the same file runs in each.
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
const CLIENT = code(read('src/core/observability/sentryClient.ts'))
const PKG = JSON.parse(read('package.json')) as {
  scripts: Record<string, string>
  dependencies: Record<string, string>
  devDependencies: Record<string, string>
}

describe('errors only: no replay, no tracing, no feedback widget', () => {
  for (const banned of [
    'replayIntegration',
    'replaysSessionSampleRate',
    'replaysOnErrorSampleRate',
    'feedbackIntegration',
    'browserTracingIntegration',
    'tracesSampleRate',
    'tracesSampler',
    'profilesSampleRate',
  ]) {
    it(`${banned} appears nowhere in src`, () => {
      const hits = SRC_FILES.filter((file) => code(readFileSync(file, 'utf8')).includes(banned))
      expect(hits.map((f) => path.relative(ROOT, f))).toEqual([])
    })
  }
})

describe('init options', () => {
  it('sets sendDefaultPii false, console and DOM breadcrumbs off, props not attached', () => {
    expect(CLIENT).toMatch(/sendDefaultPii:\s*false/)
    expect(CLIENT).toMatch(/console:\s*false/)
    expect(CLIENT).toMatch(/dom:\s*false/)
    expect(CLIENT).toMatch(/attachProps:\s*false/)
  })

  it('wires beforeSend and beforeBreadcrumb to the scrubber and the global handlers on', () => {
    expect(CLIENT).toMatch(/beforeSend:\s*\(event, hint\) => scrubEvent\(event, hint\)/)
    expect(CLIENT).toMatch(/beforeBreadcrumb:\s*\(breadcrumb\) => scrubBreadcrumb\(breadcrumb\)/)
    expect(CLIENT).toMatch(/onerror:\s*true,\s*onunhandledrejection:\s*true/)
    expect(CLIENT).toMatch(/router\.onError/)
    expect(CLIENT).toMatch(/chunk_load/)
  })

  it('Sentry.init is called in exactly one file, and only after the empty-DSN return', () => {
    const callers = SRC_FILES.filter((file) =>
      /Sentry\.init\(/.test(code(readFileSync(file, 'utf8'))),
    )
    expect(callers.map((f) => path.relative(ROOT, f))).toEqual([
      'src/core/observability/sentryClient.ts',
    ])
    expect(CLIENT.indexOf("if (dsn === '')")).toBeGreaterThan(-1)
    expect(CLIENT.indexOf("if (dsn === '')")).toBeLessThan(CLIENT.indexOf('Sentry.init('))
  })

  it('no other file sets a user: only applySentryUser in sentryClient.ts calls setUser', () => {
    const callers = SRC_FILES.filter(
      (file) =>
        /setUser\(/.test(code(readFileSync(file, 'utf8'))) &&
        /Sentry|@sentry/.test(readFileSync(file, 'utf8')),
    )
    expect(callers.map((f) => path.relative(ROOT, f))).toEqual([
      'src/core/observability/sentryClient.ts',
    ])
    expect(CLIENT).toMatch(/Sentry\.setUser\(\{ id: state\.id \}\)/)
  })
})

describe('main.ts', () => {
  it('calls initSentry after Pinia and before the router is installed', () => {
    const main = code(read('src/main.ts'))
    const pinia = main.indexOf('app.use(createPinia())')
    const init = main.indexOf('initSentry(app, router)')
    const router = main.indexOf('app.use(router)')
    expect(pinia).toBeGreaterThan(-1)
    expect(init).toBeGreaterThan(pinia)
    expect(router).toBeGreaterThan(init)
  })
})

describe('versions and build', () => {
  it('pins @sentry/vue to exactly 10.76.2 and @sentry/vite-plugin exactly (no caret)', () => {
    expect(PKG.dependencies['@sentry/vue']).toBe('10.76.2')
    expect(PKG.devDependencies['@sentry/vite-plugin']).toMatch(/^\d+\.\d+\.\d+$/)
  })

  it('every build script runs the .map check after vite build', () => {
    const builds = Object.entries(PKG.scripts).filter(
      ([name]) => name === 'build' || name.startsWith('build:'),
    )
    expect(builds.length).toBeGreaterThan(0)
    for (const [name, script] of builds) {
      expect(script, name).toMatch(/vite build.* && node scripts\/check-no-sourcemaps\.mjs$/)
    }
  })

  it('vite.config only emits source maps through sentryBuildSettings, and defines the release', () => {
    const config = code(read('vite.config.ts'))
    expect(config).toMatch(/sourcemap:\s*sentryBuild\.sourcemap/)
    expect(config).not.toMatch(/sourcemap:\s*(true|'inline'|'hidden')/)
    expect(config).toMatch(/__SENTRY_RELEASE__:\s*JSON\.stringify\(sentryRelease\.release\)/)
    const build = code(read('tools/sentryBuild.ts'))
    expect(build).toMatch(/filesToDeleteAfterUpload/)
    expect(build).toMatch(/sourcemap:\s*'hidden'/)
    expect(build).toMatch(/token === '' \|\| release === ''/)
  })
})
