/**
 * PL-3.2b — the reorder of one list (hooks, lines). A move shows at once (numbers, letters and B-roll codes follow), then the WHOLE
 * ordered id list goes to the server. Moves that come while one is on its way are folded into the newest list and sent right after.
 * The server answers with the script; a stale list (somebody added or removed a block meanwhile) writes nothing and says so
 * (`order_mismatch`): the caller reloads the real order and tells the person. Nothing a person typed is touched by any of this.
 */
import type { WaveScript } from '@catalyst/api-client'

export function useReorder(opts: {
  /** Shows the order at once, before the server has answered. */
  optimistic: (ids: string[]) => void
  send: (ids: string[]) => Promise<WaveScript>
  /** The server's confirmed script. */
  confirmed: (script: WaveScript) => void
  /** A move the server refused (a stale list included): the caller reloads and tells the person. */
  failed: (err: unknown) => void
}): { request: (ids: string[]) => void } {
  let sending = false
  let pending: string[] | null = null

  async function pump(): Promise<void> {
    if (sending) return
    sending = true
    while (pending !== null) {
      const ids = pending
      pending = null
      try {
        const script = await opts.send(ids)
        if (pending === null) opts.confirmed(script)
      } catch (err) {
        pending = null
        opts.failed(err)
      }
    }
    sending = false
  }

  return {
    request(ids) {
      opts.optimistic(ids)
      pending = ids
      void pump()
    },
  }
}
