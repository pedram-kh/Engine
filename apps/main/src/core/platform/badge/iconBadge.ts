import { registerPlugin } from '@capacitor/core'

import { nativePlatform } from '../isNativePlatform'

/**
 * AH-108 (4b) — the home-screen icon badge, the TypeScript side of the in-repo
 * iOS `CatalystBadge` plugin (`ios/App/App/CatalystBadgePlugin.swift`).
 *
 * iOS ONLY, by construction. There is no Android implementation (launcher badges
 * are OEM-dependent, Q6) and no web one, so every call is gated on the platform
 * BEFORE it reaches the plugin: Android and the browser never call a plugin that
 * does not exist there. The gate is this module's whole contract and is pinned
 * by a spec.
 *
 * The number is the bell total (Q5), written as-is: the OS shows what it is given,
 * so the 99+ cap that applies to the in-app badges does NOT apply here.
 *
 * Failure policy: a badge is decoration. Nothing here ever throws or rejects;
 * a permission the user declined, a missing plugin, a bridge hiccup all end quietly.
 */

export interface CatalystBadgePlugin {
  set(options: { count: number }): Promise<{ applied: boolean; count: number }>
}

export interface IconBadgeDeps {
  isIos: () => boolean
  plugin: () => CatalystBadgePlugin
}

export interface IconBadge {
  /** Re-allow writes after a {@link IconBadge.clear} (a new session is mounting). */
  enable: () => void
  /** Write the number. Skips a repeat of the last written value unless `force`. */
  set: (count: number, options?: { force?: boolean }) => Promise<void>
  /** Write 0 and refuse further writes until {@link IconBadge.enable}. */
  clear: () => Promise<void>
}

/** A negative, fractional or non-finite count is never shown as such: floor, and 0 for the rest. */
function normalise(count: number): number {
  return Number.isFinite(count) && count > 0 ? Math.floor(count) : 0
}

export function createIconBadge(deps: IconBadgeDeps): IconBadge {
  let last: number | null = null
  // After a clear (sign-out, a lapsed session) a poll response already in flight
  // must not write a signed-out device's badge back to life. Cleared by `enable()`,
  // which the next session's shell calls as it mounts.
  let cleared = false

  /** The platform probe, trusted to say "not iOS" if it cannot answer. */
  function onIos(): boolean {
    try {
      return deps.isIos()
    } catch {
      return false
    }
  }

  async function write(count: number): Promise<void> {
    try {
      await deps.plugin().set({ count })
    } catch {
      // Decoration only.
    }
  }

  return {
    enable: () => {
      cleared = false
    },

    set: async (count, options = {}) => {
      if (!onIos() || cleared) {
        return
      }
      const next = normalise(count)
      if (options.force !== true && next === last) {
        return
      }
      last = next
      await write(next)
    },

    clear: async () => {
      if (!onIos()) {
        return
      }
      cleared = true
      last = 0
      await write(0)
    },
  }
}

/* c8 ignore start -- the real Capacitor bridge; the logic around it is specced with fakes. */
let plugin: CatalystBadgePlugin | null = null

/** The plugin proxy, created on first use so merely importing this module touches no bridge. */
function bridgePlugin(): CatalystBadgePlugin {
  plugin ??= registerPlugin<CatalystBadgePlugin>('CatalystBadge')
  return plugin
}
/* c8 ignore stop */

/** The app's one badge, wired to the real platform probe and the real plugin. */
export const iconBadge: IconBadge = createIconBadge({
  isIos: () => nativePlatform() === 'ios',
  plugin: bridgePlugin,
})

/**
 * Clear the icon badge. Called by the auth store whenever a session ends; never
 * throws (a store spec that mocks the platform probe loosely must not be able to
 * break sign-out through here).
 */
export async function clearIconBadge(): Promise<void> {
  try {
    await iconBadge.clear()
  } catch {
    // Sign-out proceeds regardless.
  }
}
