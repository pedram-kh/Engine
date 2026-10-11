// @vitest-environment node
/**
 * AH-102 Stage 5 (iOS) — the native wiring, pinned.
 *
 * What can rot silently without a device in the loop:
 *   1. the Firebase plugin must be in the iOS Swift package (a `cap sync ios`
 *      someone forgets, or a symlink option dropped, loses push on iOS only);
 *   2. FCM auto-init must stay OFF (consent-first: no token before the soft-ask);
 *   3. the AppDelegate must forward the three remote-notification callbacks the
 *      plugin needs, or `getToken()` never resolves on a real iPhone;
 *   4. the Xcode project must carry the Push entitlement, the team and the
 *      Firebase client config as a bundle resource;
 *   5. GoogleService-Info.plist must be this app's, in the same Firebase project
 *      as the Android config, and must never be (or hold) a credential.
 */
import { existsSync, readdirSync, readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

import { APP_ROOT } from '../../../scripts/app-icon-sources.mjs'

const read = (rel: string) => readFileSync(path.join(APP_ROOT, rel), 'utf8')

const plistString = (xml: string, key: string): string | null =>
  new RegExp(`<key>${key}</key>\\s*<string>([^<]*)</string>`).exec(xml)?.[1] ?? null

const IOS_APP = 'ios/App/App'
const CONFIG_TS = read('capacitor.config.ts')
const APP_ID = /appId:\s*'([^']+)'/.exec(CONFIG_TS)?.[1]

describe('Swift package and Capacitor config', () => {
  it('the Firebase messaging plugin is in the iOS package, linked by symlink', () => {
    const swift = read('ios/App/CapApp-SPM/Package.swift')

    expect(swift).toContain('.package(name: "CapacitorFirebaseMessaging", path: "symlinks/')
    expect(swift).toContain(
      '.product(name: "CapacitorFirebaseMessaging", package: "CapacitorFirebaseMessaging")',
    )
  })

  it('asks Capacitor to symlink that plugin (SwiftPM package-identity collision)', () => {
    expect(CONFIG_TS).toMatch(
      /packageOptions:\s*\{\s*'@capacitor-firebase\/messaging':\s*\{\s*symlink:\s*true/,
    )
  })

  it('shows no system banner for a push that arrives in the foreground (P-5)', () => {
    expect(CONFIG_TS).toMatch(/FirebaseMessaging:\s*\{\s*presentationOptions:\s*\[\s*\]/)
  })

  it('does not commit the machine-specific symlink', () => {
    expect(read('ios/.gitignore')).toContain('App/CapApp-SPM/symlinks')
  })
})

describe('Info.plist', () => {
  it('keeps FCM auto-init OFF: no token before the in-app soft-ask is accepted', () => {
    expect(read(`${IOS_APP}/Info.plist`)).toMatch(
      /<key>FirebaseMessagingAutoInitEnabled<\/key>\s*<false\/>/,
    )
  })

  it('needs no background mode (a push is shown by the OS, nothing runs in the background)', () => {
    expect(read(`${IOS_APP}/Info.plist`)).not.toContain('UIBackgroundModes')
  })
})

describe('AppDelegate.swift', () => {
  const swift = read(`${IOS_APP}/AppDelegate.swift`)

  it('forwards the APNs registration result and failure to the plugin', () => {
    expect(swift).toContain('didRegisterForRemoteNotificationsWithDeviceToken')
    expect(swift).toContain('.capacitorDidRegisterForRemoteNotifications')
    expect(swift).toContain('didFailToRegisterForRemoteNotificationsWithError')
    expect(swift).toContain('.capacitorDidFailToRegisterForRemoteNotifications')
  })

  it('forwards remote notifications to the plugin', () => {
    expect(swift).toContain('didReceiveRemoteNotification userInfo')
    expect(swift).toContain('Notification.Name.init("didReceiveRemoteNotification")')
  })
})

describe('Xcode project', () => {
  const pbx = read('ios/App/App.xcodeproj/project.pbxproj')

  it('carries the Push entitlement', () => {
    expect(read(`${IOS_APP}/App.entitlements`)).toMatch(/<key>aps-environment<\/key>/)
    // Both the Debug and the Release configuration of the app target.
    expect(pbx.match(/CODE_SIGN_ENTITLEMENTS = App\/App\.entitlements;/g)).toHaveLength(2)
    expect(pbx).toContain('com.apple.Push')
  })

  it('names the Apple team on both configurations', () => {
    expect(pbx.match(/DEVELOPMENT_TEAM = LTW9262ZXP;/g)).toHaveLength(2)
  })

  it('ships GoogleService-Info.plist as a bundle resource', () => {
    const resources =
      /\/\* Begin PBXResourcesBuildPhase section \*\/[\s\S]*?\/\* End PBXResourcesBuildPhase section \*\//.exec(
        pbx,
      )?.[0]

    expect(resources).toContain('GoogleService-Info.plist in Resources')
    expect(existsSync(path.join(APP_ROOT, `${IOS_APP}/GoogleService-Info.plist`))).toBe(true)
  })
})

describe('GoogleService-Info.plist (the iOS Firebase client config, committed per Q14)', () => {
  const plist = read(`${IOS_APP}/GoogleService-Info.plist`)
  const android = JSON.parse(read('android/app/google-services.json')) as {
    project_info: { project_id: string; project_number: string }
  }

  it('is registered for this app’s bundle id', () => {
    expect(APP_ID).toBe('com.catalystgrowth.platform')
    expect(plistString(plist, 'BUNDLE_ID')).toBe(APP_ID)
  })

  it('is the same Firebase project as the Android config (one production project)', () => {
    expect(plistString(plist, 'PROJECT_ID')).toBe(android.project_info.project_id)
    expect(plistString(plist, 'GCM_SENDER_ID')).toBe(android.project_info.project_number)
  })

  it('is the client config, never a credential', () => {
    expect(plist).not.toContain('private_key')
    expect(plist).not.toContain('client_email')
    expect(plist).not.toContain('BEGIN PRIVATE KEY')
  })

  it('the iOS project holds no APNs auth key (.p8 stays with its owner)', () => {
    const found: string[] = []
    const walk = (dir: string) => {
      for (const entry of readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name)
        if (entry.isDirectory()) {
          if (!['build', 'Pods', 'DerivedData', 'output', 'xcuserdata'].includes(entry.name)) {
            walk(full)
          }
        } else if (entry.name.endsWith('.p8')) {
          found.push(full)
        }
      }
    }
    walk(path.join(APP_ROOT, 'ios'))

    expect(found).toEqual([])
  })
})
