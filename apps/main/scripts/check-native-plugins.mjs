#!/usr/bin/env node
/**
 * AH-121 C3: fails if `cap sync` left the Sentry plugin out of the native projects, or
 * if the pin moved. Run it right AFTER `cap sync` (the `sync:native` script does).
 *
 *   node scripts/check-native-plugins.mjs
 *
 * Why: the plugin's entry in `ios/App/CapApp-SPM/Package.swift` is a path that contains a
 * pnpm peer-set hash (`@sentry+capacitor@4.5.0_..._<hash>`). The hash changes when a peer
 * (capacitor core, @sentry/vue, pinia, typescript) changes; the next `cap sync` rewrites it,
 * but a stale or hand-edited file points at a folder that no longer exists and the app builds
 * WITHOUT crash reporting and without any error. So this checks that the entry exists, that
 * the path it names exists, that the Android settings include the plugin, and that the
 * version is pinned exactly. Recovery steps: docs/runbooks/native-sentry.md.
 */

import { existsSync, readFileSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

export const PLUGIN = '@sentry/capacitor'
export const PINNED_VERSION = '4.5.0'

/**
 * The lines of the Catalyst patch (`patches/@sentry__capacitor@4.5.0.patch`) that must be in the
 * plugin's Swift source the iOS app is built from. The plugin cannot pass these options through
 * from JS, so without the patch sentry-cocoa's defaults come back: native HTTP breadcrumbs,
 * lifecycle/device breadcrumbs, session tracking, metrics.
 */
export const REQUIRED_NATIVE_OPTION_LINES = [
  'options.enableAutoSessionTracking = false',
  'options.enableNetworkBreadcrumbs = false',
  'options.enableNetworkTracking = false',
  'options.enableCaptureFailedRequests = false',
  'options.enableAutoBreadcrumbTracking = false',
  'options.enableMetrics = false',
  'options.sessionReplay.sessionSampleRate = 0',
  'options.sessionReplay.onErrorSampleRate = 0',
]

/** Returns a list of problems (empty = fine). `root` is the `apps/main` folder. */
export function checkNativePlugins(root) {
  const problems = []

  const pkg = JSON.parse(readFileSync(path.join(root, 'package.json'), 'utf8'))
  const declared = pkg.dependencies?.[PLUGIN]
  if (declared !== PINNED_VERSION) {
    problems.push(
      `package.json: ${PLUGIN} must be pinned exactly to ${PINNED_VERSION} (found ${declared ?? 'nothing'}).`,
    )
  }

  const swiftDir = path.join(root, 'ios/App/CapApp-SPM')
  const swiftFile = path.join(swiftDir, 'Package.swift')
  if (!existsSync(swiftFile)) {
    problems.push('ios/App/CapApp-SPM/Package.swift is missing: run `cap sync ios`.')
  } else {
    const swift = readFileSync(swiftFile, 'utf8')
    const entry = swift.match(/\.package\(name:\s*"SentryCapacitor",\s*path:\s*"([^"]+)"\)/)
    if (entry === null) {
      problems.push('Package.swift has no SentryCapacitor package entry: run `cap sync ios`.')
    } else if (!existsSync(path.resolve(swiftDir, entry[1], 'Package.swift'))) {
      problems.push(
        `Package.swift points at ${entry[1]}, which does not exist (stale pnpm peer-set hash): run \`cap sync ios\`.`,
      )
    } else {
      const source = path.resolve(
        swiftDir,
        entry[1],
        'ios/Sources/SentryCapacitorPlugin/SentryCapacitorPlugin.swift',
      )
      const text = existsSync(source) ? readFileSync(source, 'utf8') : ''
      const missing = REQUIRED_NATIVE_OPTION_LINES.filter((line) => !text.includes(line))
      if (missing.length > 0) {
        problems.push(
          `the plugin Swift source the app builds from lacks the Catalyst patch (${missing.length} option line(s) missing, e.g. "${missing[0]}"): the native SDK would keep network, lifecycle and device breadcrumbs. Check patchedDependencies, run \`pnpm install\`, then \`cap sync ios\`.`,
        )
      }
    }
    if (!/\.product\(name:\s*"SentryCapacitor",\s*package:\s*"SentryCapacitor"\)/.test(swift)) {
      problems.push('Package.swift does not link the SentryCapacitor product: run `cap sync ios`.')
    }
  }

  const settingsFile = path.join(root, 'android/capacitor.settings.gradle')
  if (!existsSync(settingsFile)) {
    problems.push('android/capacitor.settings.gradle is missing: run `cap sync android`.')
  } else {
    const settings = readFileSync(settingsFile, 'utf8')
    const entry = settings.match(
      /project\(':sentry-capacitor'\)\.projectDir\s*=\s*new File\('([^']+)'\)/,
    )
    if (entry === null) {
      problems.push(
        'capacitor.settings.gradle has no :sentry-capacitor project: run `cap sync android`.',
      )
    } else if (!existsSync(path.resolve(root, 'android', entry[1]))) {
      problems.push(
        `capacitor.settings.gradle points at ${entry[1]}, which does not exist: run \`cap sync android\`.`,
      )
    }
  }

  const gradleFile = path.join(root, 'android/app/capacitor.build.gradle')
  if (
    !existsSync(gradleFile) ||
    !readFileSync(gradleFile, 'utf8').includes("implementation project(':sentry-capacitor')")
  ) {
    problems.push("android/app/capacitor.build.gradle does not depend on ':sentry-capacitor'.")
  }

  return problems
}

if (
  process.argv[1] !== undefined &&
  path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)
) {
  const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
  const problems = checkNativePlugins(root)
  if (problems.length > 0) {
    console.error('[sentry] FAIL: the native projects do not carry the Sentry plugin correctly:')
    for (const problem of problems) {
      console.error(`  - ${problem}`)
    }
    console.error('See docs/runbooks/native-sentry.md.')
    process.exit(1)
  }
  console.log(
    '[sentry] ok: Sentry plugin present in the iOS package and Android settings, pin exact',
  )
}
