import { isNativePlatform } from '../isNativePlatform'

/**
 * AH-103b — feed the creator app the links the OS opens it with.
 *
 * Native only (a no-op on the web: a browser follows the link itself). Two
 * sources, because a cold start delivers the link in whichever order the OS
 * and Capacitor settle on:
 *
 *   - the `appUrlOpen` event (warm start, and cold start on iOS where the
 *     event is held until a listener attaches), and
 *   - `App.getLaunchUrl()`, read once after subscribing (the cold-start
 *     fallback on both platforms).
 *
 * The same link can arrive through both; that is the duplicate guard in
 * `createAppLinkHandler` (D3), not this module's job. Nothing here may throw
 * or block the app from booting: a listener that fails to attach is swallowed.
 * The handler is injected because only the boot wiring in `main.ts` may touch
 * the router.
 */

export interface LinkSource {
  /** Subscribe to "the OS opened the app with this URL"; resolves to the unsubscribe. */
  subscribe(onUrl: (url: string) => void): Promise<() => void>
  /** The URL the app was launched with, if any. */
  launchUrl(): Promise<string | null>
}

/* c8 ignore start -- the Capacitor bridge calls; exercised on device (the AH-103b
   walk), injected as a fake in the spec. */
const capacitorLinkSource: LinkSource = {
  async subscribe(onUrl) {
    const { App } = await import('@capacitor/app')
    const handle = await App.addListener('appUrlOpen', ({ url }) => onUrl(url))
    return () => {
      void handle.remove()
    }
  },
  async launchUrl() {
    const { App } = await import('@capacitor/app')
    const launch = await App.getLaunchUrl()
    return launch?.url ?? null
  },
}
/* c8 ignore stop */

export interface StartAppLinksOptions {
  /** What to do with each link (the handler from `createAppLinkHandler`). */
  onLink: (url: string) => void
  /** The link source. Defaults to the Capacitor App plugin. */
  source?: LinkSource
  /** Native gate; injectable so the web polarity is specced without Capacitor. */
  isNative?: () => boolean
}

/** Start listening. Returns a stop function (always callable, a no-op when nothing was started). */
export function startAppLinks(options: StartAppLinksOptions): () => void {
  const { onLink, source = capacitorLinkSource, isNative = isNativePlatform } = options

  if (!isNative()) {
    return () => undefined
  }

  let stopped = false
  let unsubscribe: (() => void) | null = null

  const deliver = (url: string): void => {
    if (stopped) {
      return
    }
    try {
      onLink(url)
    } catch {
      // A handler error must never reach the bridge.
    }
  }

  source
    .subscribe(deliver)
    .then((off) => {
      if (stopped) {
        off()
        return
      }
      unsubscribe = off
    })
    .catch(() => undefined)

  source
    .launchUrl()
    .then((url) => {
      if (url !== null) {
        deliver(url)
      }
    })
    .catch(() => undefined)

  return () => {
    stopped = true
    unsubscribe?.()
    unsubscribe = null
  }
}
