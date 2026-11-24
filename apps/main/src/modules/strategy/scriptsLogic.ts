/**
 * PL-3.2a — the pure side of the script editor: the block values the editor holds, how a server block becomes one, what a
 * save sends, and how a failed save is read. Nothing here touches the DOM or a store.
 */
import { ApiError } from '@catalyst/api-client'
import type {
  ScriptBrief,
  ScriptBriefPayload,
  ScriptDuration,
  ScriptHook,
  ScriptHookPayload,
  ShotType,
} from '@catalyst/api-client'

/** The English language code: the only text language of 3.2a (PL-3.2b adds the others). */
export const EN = 'en'

export interface BriefValue {
  /** The angle as shown: the script's own text or the persona's live line. */
  angle: string
  duration: ScriptDuration
  /** True after "Use the persona's angle": the next save sends `angle: null`. */
  reset: boolean
}

export interface HookValue {
  shot: ShotType | null
  visual: string
  audio: string
  overlay: string
}

export function briefValue(brief: ScriptBrief): BriefValue {
  return {
    angle: brief.angle.value ?? '',
    duration: brief.duration_seconds as ScriptDuration,
    reset: false,
  }
}

export function hookValue(hook: ScriptHook): HookValue {
  return {
    shot: hook.shot_type,
    visual: hook.visual_description ?? '',
    audio: hook.texts.audio[EN] ?? '',
    overlay: hook.texts.overlay[EN] ?? '',
  }
}

export function sameBrief(a: BriefValue, b: BriefValue): boolean {
  return a.angle === b.angle && a.duration === b.duration && a.reset === b.reset
}

export function sameHook(a: HookValue, b: HookValue): boolean {
  return (
    a.shot === b.shot && a.visual === b.visual && a.audio === b.audio && a.overlay === b.overlay
  )
}

/** Only what the person changed: an untouched angle is never sent, so a live angle never turns into the script's own text. */
export function briefPayload(
  value: BriefValue,
  base: BriefValue,
  revision: number,
): ScriptBriefPayload {
  const payload: ScriptBriefPayload = { revision }
  if (value.reset) payload.angle = null
  else if (value.angle !== base.angle)
    payload.angle = value.angle.trim() === '' ? null : value.angle
  if (value.duration !== base.duration) payload.duration_seconds = value.duration
  return payload
}

/** The whole hook block (the server compares it and writes nothing when nothing changed). */
export function hookPayload(value: HookValue, revision: number): ScriptHookPayload {
  return {
    shot_type: value.shot,
    visual_description: value.visual === '' ? null : value.visual,
    texts: { audio: { [EN]: value.audio }, overlay: { [EN]: value.overlay } },
    revision,
  }
}

/** What a failed save means for the block. */
export type SaveFailure =
  | { kind: 'conflict'; block: 'brief' | 'hook'; current: ScriptBrief | ScriptHook | null }
  | { kind: 'gone' }
  /** 4xx the server will refuse again: no automatic retry, the message says why. */
  | { kind: 'refused' }
  /** Network and 5xx: retried after 3 s and 10 s, then on the next edit. */
  | { kind: 'retry' }

export function classifyFailure(err: unknown): SaveFailure {
  if (!(err instanceof ApiError)) return { kind: 'retry' }
  if (err.status === 409 && err.code === 'wave_script.conflict') {
    const meta = err.details[0]?.meta
    const block = meta?.['block'] === 'hook' ? 'hook' : 'brief'
    const current = meta?.['current']
    return {
      kind: 'conflict',
      block,
      current:
        typeof current === 'object' && current !== null
          ? (current as ScriptBrief | ScriptHook)
          : null,
    }
  }
  if (err.status === 404) return { kind: 'gone' }
  if (err.status >= 400 && err.status < 500 && err.status !== 408 && err.status !== 429) {
    return { kind: 'refused' }
  }
  return { kind: 'retry' }
}

export type BlockStatus = 'idle' | 'dirty' | 'saving' | 'saved' | 'failed' | 'conflict' | 'gone'

/** A block that still holds work the server does not have. */
export function isUnsaved(status: BlockStatus): boolean {
  return (
    status === 'dirty' ||
    status === 'saving' ||
    status === 'failed' ||
    status === 'conflict' ||
    status === 'gone'
  )
}

/** A block that is stuck: it will not save by itself. */
export function isStuck(status: BlockStatus): boolean {
  return status === 'failed' || status === 'conflict' || status === 'gone'
}

/** The state the script's chip shows: the worst of its blocks. */
export function scriptSaveState(statuses: BlockStatus[]): {
  kind: 'saved' | 'saving' | 'stuck'
  stuck: number
} {
  const stuck = statuses.filter(isStuck).length
  if (stuck > 0) return { kind: 'stuck', stuck }
  if (statuses.some((s) => s === 'dirty' || s === 'saving')) return { kind: 'saving', stuck: 0 }
  return { kind: 'saved', stuck: 0 }
}

/** The slot-colour of a persona (a palette key), or null for a creator with no persona. */
export function personaColorKey(persona: { color_key: string } | null): string | null {
  return persona === null ? null : persona.color_key
}

/** A hook with anything written in it (deleting it asks first). */
export function hookHasContent(hook: ScriptHook): boolean {
  return (
    hook.visual_description !== null ||
    hook.shot_type !== null ||
    Object.keys(hook.texts.audio).length > 0 ||
    Object.keys(hook.texts.overlay).length > 0
  )
}
