/**
 * Meta (Facebook) Pixel loader for the sign-in page.
 *
 * Mounted from `SignInPage` ONLY, never globally and never from the
 * layout. That restriction is load-bearing rather than tidiness: the
 * pixel reports the full document location with every event, and
 * `/auth/reset-password`, `/auth/verify-email` and `/auth/accept-invite`
 * all carry a single-use credential in their query string. Installing
 * this in `index.html` — where the vendor snippet is normally pasted —
 * would hand those tokens to Meta. Any future call site must be checked
 * against that same rule.
 *
 * Automatic Advanced Matching is switched off before `init`. Left on, the
 * pixel harvests recognised form fields and ships hashed emails and phone
 * numbers; on a sign-in form that means the address being typed into the
 * login box. `autoConfig: false` also stops the automatic button-click
 * and form-submit instrumentation, so the only event sent is the
 * PageView asked for below.
 *
 * CONSENT: this fires unconditionally today. `_fbp` is a non-essential
 * cookie, and docs/05-SECURITY-COMPLIANCE.md §2.1 puts analytics
 * tracking behind consent, with the consent platform scheduled for
 * Sprint 14 (docs/20-PHASE-1-SPEC.md). Shipping ahead of that platform
 * is a deliberate, logged decision — see the Meta Pixel entry in
 * docs/tech-debt.md, which carries the trigger for gating it.
 */

/** Signature every `fbq(...)` call goes through. */
type FbqCommand = (...args: readonly unknown[]) => void

/**
 * The pixel's global entry point.
 *
 * Before the vendor bundle arrives this is a stub that queues calls; the
 * bundle then drains `queue` through `callMethod`. Both shapes are the
 * same callable, which is why the queue fields are optional.
 */
interface Fbq extends FbqCommand {
  callMethod?: FbqCommand
  queue?: unknown[]
  /** Alias of the callable itself; see `ensureFbq`. */
  push?: FbqCommand
  loaded?: boolean
  version?: string
}

declare global {
  interface Window {
    fbq?: Fbq
    _fbq?: Fbq
  }
}

/**
 * Hardcoded by decision rather than read from `VITE_META_PIXEL_ID`: the
 * accepted trade-off is that local development and staging report into
 * the production pixel. Swapping this for an env var is the fix if that
 * traffic ever needs separating.
 */
const PIXEL_ID = '1372598514719791'

const SDK_SRC = 'https://connect.facebook.net/en_US/fbevents.js'

/**
 * Installs the queueing stub and requests the vendor bundle, once per
 * document.
 *
 * Returns the existing `fbq` on later calls so re-entering the sign-in
 * route re-uses the loaded pixel instead of injecting a second `<script>`
 * — the vendor snippet's own `if (f.fbq) return` guard, kept because a
 * SPA can mount this page many times per session.
 */
function ensureFbq(win: Window, doc: Document): Fbq {
  const existing = win.fbq
  if (existing) {
    return existing
  }

  const queue: unknown[] = []
  const fbq: Fbq = (...args: readonly unknown[]): void => {
    // Once the bundle loads it replaces this behaviour via `callMethod`;
    // until then every call is buffered and replayed.
    if (fbq.callMethod) {
      fbq.callMethod(...args)
      return
    }
    queue.push(args)
  }
  fbq.queue = queue
  // `push` aliases the callable, exactly as the vendor snippet's `n.push = n`
  // does. Nothing here needs it, but parts of the SDK and its integrations
  // reach for `fbq.push`, so the stub matches the contract they expect.
  fbq.push = fbq
  fbq.loaded = true
  fbq.version = '2.0'

  win.fbq = fbq
  win._fbq = fbq

  const script = doc.createElement('script')
  script.async = true
  script.src = SDK_SRC
  // Appended to <head> rather than spliced in before the first <script>
  // as the vendor snippet does: same result, no assumption that such a
  // script exists or has a parent.
  doc.head.append(script)

  return fbq
}

/**
 * Initialises the pixel and reports one PageView.
 *
 * Safe to call on every mount: the SDK is fetched at most once, and each
 * call records a fresh PageView, which is what a SPA revisit is.
 */
export function loadMetaPixel(win: Window, doc: Document): void {
  const fbq = ensureFbq(win, doc)

  // Must precede `init` — after it, advanced matching is already armed.
  fbq('set', 'autoConfig', false, PIXEL_ID)
  fbq('init', PIXEL_ID)
  fbq('track', 'PageView')
}
