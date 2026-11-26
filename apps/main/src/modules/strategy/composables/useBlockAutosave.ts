/**
 * PL-3.2a — autosave of ONE block of a script (the brief, or one hook).
 *
 * The block keeps its own draft. An edit marks it unsaved and starts a debounce; the save sends the whole block with the block's
 * own revision. Nothing the person typed is ever replaced by the server's answer while they are still typing: the answer is
 * adopted only when the draft is what was sent. A failed save keeps the text, shows "Could not save" and retries after 3 s and
 * 10 s (a network or server failure only; the server refusing a value is shown, not retried), then on the next edit or Retry. A
 * 409 keeps the person's text in place and holds the other version beside it until the person chooses.
 */
import { computed, onBeforeUnmount, ref, type ComputedRef, type Ref } from 'vue'

import { classifyFailure, type BlockStatus } from '../scriptsLogic'
import type { ScriptBrief, ScriptHook, ScriptLine } from '@catalyst/api-client'

export interface BlockSnapshot<V> {
  value: V
  revision: number
}

export interface BlockAutosaveOptions<V> {
  /** The server's current version of this block (it changes when the script is reloaded). */
  remote: () => BlockSnapshot<V>
  /** Sends the block. `base` is what the server held when the draft started. Resolves with the server's block. */
  save: (value: V, revision: number, base: V) => Promise<BlockSnapshot<V>>
  same: (a: V, b: V) => boolean
  /** Reads the block out of a 409's `current`. */
  fromConflict: (current: ScriptBrief | ScriptHook | ScriptLine) => BlockSnapshot<V> | null
  clone: (value: V) => V
  /**
   * Called when the server's copy changed while the block holds work of its own (it cannot adopt the new copy): returns the value
   * with the STRUCTURE of the new copy (a row added elsewhere appears, a row deleted elsewhere goes) and everything else as it was.
   * Applied to the draft AND to what the draft is compared with, so a structural change is never mistaken for an edit.
   */
  reconcile?: (value: V, remote: V) => V
  debounceMs?: number
  retryMs?: readonly number[]
}

export interface BlockAutosave<V> {
  draft: Ref<V>
  status: Ref<BlockStatus>
  /** Why the block is stuck: `refused` (the server said no) or `retry`; null otherwise. */
  failure: Ref<'refused' | 'retry' | null>
  /** The other person's version, while a conflict is open. */
  theirs: Ref<V | null>
  dirty: ComputedRef<boolean>
  edit: (change: (value: V) => void) => void
  flush: () => Promise<boolean>
  retry: () => Promise<boolean>
  keepMine: () => Promise<boolean>
  useTheirs: () => void
  /** Adopts a newer server version when the block holds no work of its own. */
  syncRemote: () => void
}

export function useBlockAutosave<V>(opts: BlockAutosaveOptions<V>): BlockAutosave<V> {
  const debounceMs = opts.debounceMs ?? 1500
  const retryMs = opts.retryMs ?? [3000, 10000]

  const first = opts.remote()
  const base = ref(opts.clone(first.value)) as Ref<V>
  const revision = ref(first.revision)
  const draft = ref(opts.clone(first.value)) as Ref<V>
  const status = ref<BlockStatus>('idle')
  const failure = ref<'refused' | 'retry' | null>(null)
  const theirs = ref(null) as Ref<V | null>
  let theirsRevision = 0

  let timer: ReturnType<typeof setTimeout> | undefined
  let retryTimer: ReturnType<typeof setTimeout> | undefined
  let attempts = 0
  let inflight: Promise<boolean> | null = null
  let disposed = false

  const dirty = computed(() => !opts.same(draft.value, base.value))

  function clearTimers(): void {
    clearTimeout(timer)
    clearTimeout(retryTimer)
  }

  async function run(): Promise<boolean> {
    while (true) {
      if (opts.same(draft.value, base.value)) {
        status.value = status.value === 'idle' ? 'idle' : 'saved'
        failure.value = null
        attempts = 0
        return true
      }
      const sent = opts.clone(draft.value)
      status.value = 'saving'
      try {
        const res = await opts.save(sent, revision.value, opts.clone(base.value))
        revision.value = res.revision
        base.value = opts.clone(res.value)
        // Adopt the server's wording (cleaned text) only when nothing was typed while the request was out.
        if (opts.same(draft.value, sent)) draft.value = opts.clone(res.value)
        attempts = 0
        failure.value = null
      } catch (err) {
        const f = classifyFailure(err)
        if (f.kind === 'conflict') {
          const cur = f.current === null ? null : opts.fromConflict(f.current)
          if (cur !== null) {
            theirs.value = opts.clone(cur.value)
            theirsRevision = cur.revision
          }
          status.value = 'conflict'
          return false
        }
        if (f.kind === 'gone') {
          status.value = 'gone'
          return false
        }
        status.value = 'failed'
        failure.value = f.kind
        if (f.kind === 'retry' && !disposed) {
          const delay = retryMs[attempts]
          attempts++
          if (delay !== undefined) retryTimer = setTimeout(() => void flush(), delay)
        }
        return false
      }
    }
  }

  function flush(): Promise<boolean> {
    clearTimers()
    if (status.value === 'conflict' || status.value === 'gone') return Promise.resolve(false)
    if (inflight !== null) return inflight.then(() => flush())
    if (!dirty.value) return Promise.resolve(status.value !== 'failed')
    inflight = run().finally(() => {
      inflight = null
    })
    return inflight
  }

  function edit(change: (value: V) => void): void {
    change(draft.value)
    if (status.value === 'conflict' || status.value === 'gone') return
    clearTimers()
    attempts = 0
    if (!dirty.value && status.value !== 'saving') {
      status.value = status.value === 'idle' ? 'idle' : 'saved'
      failure.value = null
      return
    }
    if (status.value !== 'saving') status.value = 'dirty'
    timer = setTimeout(() => void flush(), debounceMs)
  }

  function retry(): Promise<boolean> {
    attempts = 0
    if (status.value === 'failed') status.value = 'dirty'
    return flush()
  }

  /** Keep the person's text: it goes again on top of the other person's revision. */
  function keepMine(): Promise<boolean> {
    if (status.value !== 'conflict') return Promise.resolve(true)
    revision.value = theirsRevision
    if (theirs.value !== null) base.value = opts.clone(theirs.value)
    theirs.value = null
    status.value = 'dirty'
    return flush()
  }

  function useTheirs(): void {
    if (theirs.value === null) return
    revision.value = theirsRevision
    base.value = opts.clone(theirs.value)
    draft.value = opts.clone(theirs.value)
    theirs.value = null
    status.value = 'saved'
    failure.value = null
  }

  function syncRemote(): void {
    if (status.value === 'dirty' || status.value === 'saving' || status.value === 'failed') {
      if (opts.reconcile !== undefined) {
        const r = opts.remote()
        base.value = opts.reconcile(opts.clone(base.value), r.value)
        draft.value = opts.reconcile(opts.clone(draft.value), r.value)
      }
      return
    }
    if (status.value === 'conflict' || status.value === 'gone') return
    const r = opts.remote()
    if (r.revision < revision.value) return
    revision.value = r.revision
    base.value = opts.clone(r.value)
    draft.value = opts.clone(r.value)
  }

  onBeforeUnmount(() => {
    disposed = true
    clearTimers()
  })

  return {
    draft,
    status,
    failure,
    theirs,
    dirty,
    edit,
    flush,
    retry,
    keepMine,
    useTheirs,
    syncRemote,
  }
}
