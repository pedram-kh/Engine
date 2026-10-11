// @vitest-environment node
/**
 * AH-102 — the Android WebView/bridge log is debug-only, and stays that way.
 *
 * The device walk found that a debug build writes plugin results to logcat,
 * including the stored bearer token (SecureStorage `internalGetItem`). That is
 * the Capacitor bridge's own logging. It is gated by ONE thing: the installed
 * APK's `android:debuggable` flag (a build-type property, not a setting this app
 * or the server can flip), as long as nobody overrides `loggingBehavior`.
 *
 * Pinned here, so the gate cannot be weakened silently:
 *   1. `capacitor.config.ts` never sets `loggingBehavior` (`production` would log
 *      unconditionally) or `webContentsDebuggingEnabled`;
 *   2. no source set other than `debug` can mark the app debuggable: no
 *      `android:debuggable` in the main manifest, no `debuggable` in the release
 *      build type;
 *   3. the installed `@capacitor/android` still defaults to the `debug` behaviour
 *      and still derives it from `FLAG_DEBUGGABLE` (a dependency bump that
 *      changes either fails here, before a release build could log).
 */
import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

import { APP_ROOT } from '../../../scripts/app-icon-sources.mjs'

const read = (rel: string): string => readFileSync(path.join(APP_ROOT, rel), 'utf8')

const CAP_CONFIG = read('capacitor.config.ts')
const MAIN_MANIFEST = read('android/app/src/main/AndroidManifest.xml')
const GRADLE = read('android/app/build.gradle')
const CAP_JAVA = read(
  'node_modules/@capacitor/android/capacitor/src/main/java/com/getcapacitor/CapConfig.java',
)

/** The body of `buildTypes { release { ... } }`, comments stripped. */
function releaseBuildType(): string {
  const stripped = GRADLE.replace(/\/\/.*$/gm, '')
  const match = /release\s*\{([\s\S]*?)\n {8}\}/.exec(stripped)
  if (!match) {
    throw new Error('release build type not found in android/app/build.gradle')
  }
  return match[1] ?? ''
}

describe('Android bridge logging is debug-only', () => {
  it('capacitor.config.ts does not override the logging or WebView-debugging behaviour', () => {
    const code = CAP_CONFIG.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '')

    expect(code).not.toMatch(/loggingBehavior/)
    expect(code).not.toMatch(/webContentsDebuggingEnabled/)
  })

  it('no non-debug source set can mark the app debuggable', () => {
    expect(MAIN_MANIFEST).not.toMatch(/android:debuggable/)
    expect(releaseBuildType()).toMatch(/minifyEnabled/)
    expect(releaseBuildType()).not.toMatch(/debuggable/)
  })

  it('@capacitor/android defaults to the debug behaviour and derives it from FLAG_DEBUGGABLE', () => {
    expect(CAP_JAVA).toMatch(/FLAG_DEBUGGABLE/)
    expect(CAP_JAVA).toMatch(/"loggingBehavior",\s*LOG_BEHAVIOR_DEBUG/)
    expect(CAP_JAVA).toMatch(
      /default:\s*\/\/\s*LOG_BEHAVIOR_DEBUG\s*\n\s*loggingEnabled = isDebug;/,
    )
  })
})
