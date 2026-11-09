/**
 * AH-115 L0.1 — freezes the node-side clock for `walk.mjs --set agency`, so the mock API
 * fixtures (`Date.now()`-relative timestamps and `daysFromToday`) do not drift from one day
 * to the next. MUST be the first import of `walk.mjs` (static imports evaluate in order).
 * The page side is frozen with `context.clock.setFixedTime(FROZEN_NOW)`.
 */
export const FROZEN_NOW = '2026-11-09T11:00:00.000Z'

const setIdx = process.argv.indexOf('--set')
if (setIdx !== -1 && process.argv[setIdx + 1] === 'agency') {
  const RealDate = Date
  const fixed = new RealDate(FROZEN_NOW).getTime()
  class FrozenDate extends RealDate {
    constructor(...args) {
      if (args.length === 0) super(fixed)
      else super(...args)
    }
    static now() {
      return fixed
    }
  }
  globalThis.Date = FrozenDate
}
