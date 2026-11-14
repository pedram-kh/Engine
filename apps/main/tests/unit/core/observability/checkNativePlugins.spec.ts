/**
 * AH-121 C3: the post-`cap sync` check. Runs against the real repo (must be clean) and
 * against temp trees with each failure injected (must name the problem).
 */

import { cpSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import path from 'node:path'

import { afterEach, beforeEach, describe, expect, it } from 'vitest'

import {
  REQUIRED_NATIVE_OPTION_LINES,
  checkNativePlugins,
} from '../../../../scripts/check-native-plugins.mjs'

const REPO = path.resolve(__dirname, '../../../..')
let tree = ''

/** A copy of the four files the check reads, plus the plugin folders they point at. */
function copyTree(): string {
  const root = mkdtempSync(path.join(tmpdir(), 'native-plugins-'))
  for (const file of [
    'package.json',
    'ios/App/CapApp-SPM/Package.swift',
    'android/capacitor.settings.gradle',
    'android/app/capacitor.build.gradle',
  ]) {
    mkdirSync(path.dirname(path.join(root, file)), { recursive: true })
    cpSync(path.join(REPO, file), path.join(root, file))
  }
  // The folders the entries name, so the copy is a passing tree on its own.
  const swiftDir = path.join(root, 'ios/App/CapApp-SPM')
  const swiftPath = /name: "SentryCapacitor", path: "([^"]+)"/.exec(
    readFileSync(path.join(swiftDir, 'Package.swift'), 'utf8'),
  )?.[1]
  const gradlePath = /':sentry-capacitor'\)\.projectDir = new File\('([^']+)'\)/.exec(
    readFileSync(path.join(root, 'android/capacitor.settings.gradle'), 'utf8'),
  )?.[1]
  const swiftTarget = path.resolve(swiftDir, swiftPath ?? 'missing')
  mkdirSync(swiftTarget, { recursive: true })
  writeFileSync(path.join(swiftTarget, 'Package.swift'), '// plugin')
  const swiftSource = path.join(swiftTarget, 'ios/Sources/SentryCapacitorPlugin')
  mkdirSync(swiftSource, { recursive: true })
  writeFileSync(
    path.join(swiftSource, 'SentryCapacitorPlugin.swift'),
    `${REQUIRED_NATIVE_OPTION_LINES.join('\n')}\n`,
  )
  mkdirSync(path.resolve(root, 'android', gradlePath ?? 'missing'), { recursive: true })
  return root
}
const edit = (file: string, fn: (text: string) => string): void => {
  const p = path.join(tree, file)
  writeFileSync(p, fn(readFileSync(p, 'utf8')))
}

beforeEach(() => {
  tree = ''
})
afterEach(() => {
  if (tree !== '') {
    rmSync(tree, { recursive: true, force: true })
  }
})

describe('checkNativePlugins', () => {
  it('the real repo passes (after a cap sync)', () => {
    expect(checkNativePlugins(REPO)).toEqual([])
  })

  it('a copy with the plugin folders in place passes (so each failure below is the injected one)', () => {
    tree = copyTree()
    expect(checkNativePlugins(tree)).toEqual([])
  })

  it('fails when the Swift package entry is missing', () => {
    tree = copyTree()
    edit('ios/App/CapApp-SPM/Package.swift', (t) =>
      t.replace(/,\s*\.package\(name: "SentryCapacitor"[^\n]*\)/, ''),
    )
    expect(checkNativePlugins(tree).join('\n')).toMatch(/no SentryCapacitor package entry/)
  })

  it('fails when the path carries a stale pnpm peer-set hash', () => {
    tree = copyTree()
    edit('ios/App/CapApp-SPM/Package.swift', (t) =>
      t.replace(/(@sentry\+capacitor@4\.5\.0_[^/]*?)([0-9a-f]{8})\//, '$1deadbeef/'),
    )
    expect(checkNativePlugins(tree).join('\n')).toMatch(
      /does not exist \(stale pnpm peer-set hash\)/,
    )
  })

  it('fails when the plugin source lacks the patch (any one of its lines)', () => {
    for (const line of REQUIRED_NATIVE_OPTION_LINES) {
      tree = copyTree()
      const swiftDir = path.join(tree, 'ios/App/CapApp-SPM')
      const entry = /name: "SentryCapacitor", path: "([^"]+)"/.exec(
        readFileSync(path.join(swiftDir, 'Package.swift'), 'utf8'),
      )?.[1]
      const source = path.resolve(
        swiftDir,
        entry ?? '',
        'ios/Sources/SentryCapacitorPlugin/SentryCapacitorPlugin.swift',
      )
      writeFileSync(source, readFileSync(source, 'utf8').replace(line, '// removed'))
      expect(checkNativePlugins(tree).join('\n')).toMatch(/lacks the Catalyst patch/)
      rmSync(tree, { recursive: true, force: true })
      tree = ''
    }
  })

  it('the real plugin source carries every patched line', () => {
    expect(checkNativePlugins(REPO).join('\n')).not.toMatch(/Catalyst patch/)
  })

  it('requires exactly the eight patched lines (a literal pin, so dropping one is visible)', () => {
    expect(REQUIRED_NATIVE_OPTION_LINES).toEqual([
      'options.enableAutoSessionTracking = false',
      'options.enableNetworkBreadcrumbs = false',
      'options.enableNetworkTracking = false',
      'options.enableCaptureFailedRequests = false',
      'options.enableAutoBreadcrumbTracking = false',
      'options.enableMetrics = false',
      'options.sessionReplay.sessionSampleRate = 0',
      'options.sessionReplay.onErrorSampleRate = 0',
    ])
  })

  it('fails when the product is not linked', () => {
    tree = copyTree()
    edit('ios/App/CapApp-SPM/Package.swift', (t) =>
      t.replace(/,\s*\.product\(name: "SentryCapacitor"[^\n]*\)/, ''),
    )
    expect(checkNativePlugins(tree).join('\n')).toMatch(/does not link the SentryCapacitor product/)
  })

  it('fails when the pin is a range or missing', () => {
    tree = copyTree()
    edit('package.json', (t) =>
      t.replace('"@sentry/capacitor": "4.5.0"', '"@sentry/capacitor": "^4.5.0"'),
    )
    expect(checkNativePlugins(tree).join('\n')).toMatch(
      /pinned exactly to 4\.5\.0 \(found \^4\.5\.0\)/,
    )
    edit('package.json', (t) => t.replace(/\s*"@sentry\/capacitor": "\^4\.5\.0",?/, ''))
    expect(checkNativePlugins(tree).join('\n')).toMatch(/found nothing/)
  })

  it('fails when Android does not include the plugin', () => {
    tree = copyTree()
    edit('android/capacitor.settings.gradle', (t) =>
      t.replace(/include ':sentry-capacitor'[\s\S]*$/, ''),
    )
    edit('android/app/capacitor.build.gradle', (t) =>
      t.replace("    implementation project(':sentry-capacitor')\n", ''),
    )
    const out = checkNativePlugins(tree).join('\n')
    expect(out).toMatch(/no :sentry-capacitor project/)
    expect(out).toMatch(/does not depend on ':sentry-capacitor'/)
  })
})
