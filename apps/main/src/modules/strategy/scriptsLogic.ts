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
  ScriptImage,
  ScriptLine,
  ScriptLinePayload,
  ShotType,
  WaveScript,
} from '@catalyst/api-client'
import { SCRIPT_IMAGE_MIME_TYPES } from '@catalyst/api-client'

/** The English language code: always a script language, first, and never removed. */
export const EN = 'en'

export interface BriefValue {
  /** The angle as shown: the script's own text or the persona's live line. */
  angle: string
  duration: ScriptDuration
  /** True after "Use the persona's angle": the next save sends `angle: null`. */
  reset: boolean
  /** "The overall setting of the video" (English, optional). */
  setting: string
}

export interface HookValue {
  shot: ShotType | null
  visual: string
  /** English. */
  audio: string
  overlay: string
  /** The market's local languages: `local.audio.sk`. A language that is not there is the empty text. */
  local: { audio: Record<string, string>; overlay: Record<string, string> }
}

/** One B-roll shot as the line's draft holds it. */
export interface BrollValue {
  shot: ShotType | null
  description: string
}

export interface LineValue {
  /** Spoken text by language, English included. */
  texts: Record<string, string>
  cta: boolean
  /** The line's own shot type and English visual description. */
  shot: ShotType | null
  visual: string
  /** By the row's id. A row that is not here is one this draft never touched. */
  broll: Record<string, BrollValue>
}

export function briefValue(brief: ScriptBrief): BriefValue {
  return {
    angle: brief.angle.value ?? '',
    duration: brief.duration_seconds as ScriptDuration,
    reset: false,
    setting: brief.setting ?? '',
  }
}

/** The languages after English, from a script's language list. */
export function localLanguages(languages: readonly string[]): string[] {
  return languages.filter((l) => l !== EN)
}

export function hookValue(hook: ScriptHook, languages: readonly string[] = [EN]): HookValue {
  const audio: Record<string, string> = {}
  const overlay: Record<string, string> = {}
  for (const l of localLanguages(languages)) {
    audio[l] = hook.texts.audio[l] ?? ''
    overlay[l] = hook.texts.overlay[l] ?? ''
  }
  return {
    shot: hook.shot_type,
    visual: hook.visual_description ?? '',
    audio: hook.texts.audio[EN] ?? '',
    overlay: hook.texts.overlay[EN] ?? '',
    local: { audio, overlay },
  }
}

export function lineValue(line: ScriptLine, languages: readonly string[] = [EN]): LineValue {
  const texts: Record<string, string> = {}
  for (const l of languages) texts[l] = line.texts[l] ?? ''
  const broll: Record<string, BrollValue> = {}
  for (const row of line.broll) {
    broll[row.id] = { shot: row.shot_type, description: row.description ?? '' }
  }
  return {
    texts,
    cta: line.cta,
    shot: line.shot_type,
    visual: line.visual_description ?? '',
    broll,
  }
}

export function sameBrief(a: BriefValue, b: BriefValue): boolean {
  return (
    a.angle === b.angle &&
    a.duration === b.duration &&
    a.reset === b.reset &&
    a.setting === b.setting
  )
}

/** Two text maps are the same when every language in either holds the same text (a missing one is the empty text). */
function sameTexts(a: Record<string, string>, b: Record<string, string>): boolean {
  for (const k of new Set([...Object.keys(a), ...Object.keys(b)])) {
    if ((a[k] ?? '') !== (b[k] ?? '')) return false
  }
  return true
}

export function sameHook(a: HookValue, b: HookValue): boolean {
  return (
    a.shot === b.shot &&
    a.visual === b.visual &&
    a.audio === b.audio &&
    a.overlay === b.overlay &&
    sameTexts(a.local.audio, b.local.audio) &&
    sameTexts(a.local.overlay, b.local.overlay)
  )
}

/**
 * Two line drafts are the same when the texts and the CTA flag are, and every B-roll row BOTH know holds the same fields. A row that
 * only one side knows (added or deleted in the meantime) is not a change the person made.
 */
export function sameLine(a: LineValue, b: LineValue): boolean {
  if (a.cta !== b.cta || a.shot !== b.shot || a.visual !== b.visual) return false
  if (!sameTexts(a.texts, b.texts)) return false
  for (const id of Object.keys(a.broll)) {
    const x = a.broll[id]
    const y = b.broll[id]
    if (x === undefined || y === undefined) continue
    if (x.shot !== y.shot || x.description !== y.description) return false
  }
  return true
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
  if (value.setting !== base.setting)
    payload.setting = value.setting.trim() === '' ? null : value.setting
  return payload
}

/**
 * The whole hook block (the server compares it and writes nothing when nothing changed). Only the languages the script has now are
 * sent: a language that was removed keeps its hidden texts on the server and they are never touched from here.
 */
export function hookPayload(
  value: HookValue,
  revision: number,
  languages: readonly string[] = [EN],
): ScriptHookPayload {
  const audio: Record<string, string> = { [EN]: value.audio }
  const overlay: Record<string, string> = { [EN]: value.overlay }
  for (const l of localLanguages(languages)) {
    audio[l] = value.local.audio[l] ?? ''
    overlay[l] = value.local.overlay[l] ?? ''
  }
  return {
    shot_type: value.shot,
    visual_description: value.visual === '' ? null : value.visual,
    texts: { audio, overlay },
    revision,
  }
}

/** What the person changed in a line since `base`: the texts that differ, the CTA flag, and the B-roll rows that differ or are new to `base`. */
export function linePayload(
  value: LineValue,
  base: LineValue,
  revision: number,
  languages: readonly string[],
): ScriptLinePayload {
  const payload: ScriptLinePayload = { revision }
  const texts: Record<string, string | null> = {}
  for (const l of languages) {
    if ((value.texts[l] ?? '') !== (base.texts[l] ?? '')) texts[l] = value.texts[l] ?? ''
  }
  if (Object.keys(texts).length > 0) payload.texts = texts
  if (value.cta !== base.cta) payload.cta = value.cta
  if (value.shot !== base.shot) payload.shot_type = value.shot
  if (value.visual !== base.visual)
    payload.visual_description = value.visual.trim() === '' ? null : value.visual
  const broll: NonNullable<ScriptLinePayload['broll']> = {}
  for (const [id, row] of Object.entries(value.broll)) {
    const was = base.broll[id]
    if (was === undefined) continue
    const change: { shot_type?: ShotType | null; description?: string | null } = {}
    if (row.shot !== was.shot) change.shot_type = row.shot
    if (row.description !== was.description)
      change.description = row.description === '' ? null : row.description
    if (Object.keys(change).length > 0) broll[id] = change
  }
  if (Object.keys(broll).length > 0) payload.broll = broll
  return payload
}

/** What a failed save means for the block. */
export type SaveFailure =
  | {
      kind: 'conflict'
      block: 'brief' | 'hook' | 'line'
      current: ScriptBrief | ScriptHook | ScriptLine | null
    }
  | { kind: 'gone' }
  /** 4xx the server will refuse again: no automatic retry, the message says why. */
  | { kind: 'refused' }
  /** Network and 5xx: retried after 3 s and 10 s, then on the next edit. */
  | { kind: 'retry' }

export function classifyFailure(err: unknown): SaveFailure {
  if (!(err instanceof ApiError)) return { kind: 'retry' }
  // A save to a line somebody deleted (404 with this code): the line is gone, the person's text stays on screen.
  if (
    err.status === 404 &&
    (err.code === 'wave_script_line.deleted' || err.code === 'wave_script_hook.deleted')
  )
    return { kind: 'gone' }
  // `broll_gone`: a B-roll shot of this line was deleted elsewhere. It carries the current line like a conflict does.
  if (
    err.status === 409 &&
    (err.code === 'wave_script.conflict' || err.code === 'wave_script.broll_gone')
  ) {
    const meta = err.details[0]?.meta
    const named = meta?.['block']
    const block = named === 'hook' || named === 'line' ? named : 'brief'
    const current = meta?.['current']
    return {
      kind: 'conflict',
      block,
      current:
        typeof current === 'object' && current !== null
          ? (current as ScriptBrief | ScriptHook | ScriptLine)
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

// ---------------------------------------------------------------------------------------------------------------------
// PL-3.2b — lines, B-roll, languages, images
// ---------------------------------------------------------------------------------------------------------------------

/** The first line of a script is line 2: the hook is line 1. */
export const FIRST_LINE_NUMBER = 2

/** The B-roll code of a row: `{line number}_{n}`, both from positions. The server sends the same; this keeps the screen exact while a move is on its way. */
export function brollCode(lineNumber: number, index: number): string {
  return `${lineNumber}_${index + 1}`
}

/** The ids moved one step (delta -1 up, +1 down); the same list when the move would leave the list. */
export function moveId(ids: readonly string[], id: string, delta: -1 | 1): string[] {
  const from = ids.indexOf(id)
  const to = from + delta
  if (from < 0 || to < 0 || to >= ids.length) return [...ids]
  const next = [...ids]
  const [moved] = next.splice(from, 1)
  if (moved !== undefined) next.splice(to, 0, moved)
  return next
}

/** The ids with `id` put where `target` is (dropping a block onto another one). */
export function moveBefore(ids: readonly string[], id: string, target: string): string[] {
  const from = ids.indexOf(id)
  const to = ids.indexOf(target)
  if (from < 0 || to < 0 || from === to) return [...ids]
  const next = [...ids]
  const [moved] = next.splice(from, 1)
  if (moved !== undefined) next.splice(to, 0, moved)
  return next
}

/** The lines in the given order, with numbers and B-roll codes recomputed, so they change at once (the server confirms). Unknown ids are left out. */
export function applyLineOrder(lines: readonly ScriptLine[], ids: readonly string[]): ScriptLine[] {
  const byId = new Map(lines.map((l) => [l.id, l]))
  const out: ScriptLine[] = []
  ids.forEach((id, index) => {
    const line = byId.get(id)
    if (line === undefined) return
    const number = FIRST_LINE_NUMBER + index
    out.push({
      ...line,
      position: index,
      number,
      broll: line.broll.map((row, i) => ({ ...row, code: brollCode(number, i) })),
    })
  })
  return out
}

/** The B-roll rows of one line in the given order, with positions and codes recomputed. */
export function applyBrollOrder(line: ScriptLine, ids: readonly string[]): ScriptLine {
  const byId = new Map(line.broll.map((r) => [r.id, r]))
  const broll: ScriptLine['broll'] = []
  ids.forEach((id, index) => {
    const row = byId.get(id)
    if (row !== undefined)
      broll.push({ ...row, position: index, code: brollCode(line.number, index) })
  })
  return { ...line, broll }
}

export function hookLetter(index: number): string {
  return String.fromCharCode(65 + index)
}

/** The hooks in the given order, with the letters recomputed. */
export function applyHookOrder(hooks: readonly ScriptHook[], ids: readonly string[]): ScriptHook[] {
  const byId = new Map(hooks.map((h) => [h.id, h]))
  const out: ScriptHook[] = []
  ids.forEach((id, index) => {
    const hook = byId.get(id)
    if (hook !== undefined) out.push({ ...hook, position: index, letter: hookLetter(index) })
  })
  return out
}

/** A limit message shows only near the limit: from `from` of `max` up. */
export function nearLimit(count: number, max: number, from: number): boolean {
  return max > 0 && count >= Math.min(from, max)
}

/** Lines: the limit text starts at 25 of 30. */
export const LINES_NEAR = 25
/** Images in one block: the usage starts at 8 of 10. */
export const IMAGES_NEAR = 8
/** Script image bytes: the usage starts at 80 % of the total. */
export const IMAGE_BYTES_NEAR = 0.8

export type ScriptUploadRefusal = 'type' | 'empty' | 'count' | 'bytes'

/**
 * Which of the chosen files may start, before any byte moves: JPG, PNG, WebP or GIF by the browser's type (the server reads the
 * real bytes), not empty, ten in the block, and the script's 300 MB. Counts what is stored plus the files accepted earlier in
 * the same choice. The server repeats every check.
 */
export function planScriptUploads(
  files: { name: string; type: string; size: number }[],
  used: { blockCount: number; scriptBytes: number },
  limits: { images: number; bytes: number },
): { accepted: number[]; refused: { index: number; reason: ScriptUploadRefusal }[] } {
  const accepted: number[] = []
  const refused: { index: number; reason: ScriptUploadRefusal }[] = []
  let count = used.blockCount
  let bytes = used.scriptBytes
  files.forEach((f, index) => {
    if (!(SCRIPT_IMAGE_MIME_TYPES as readonly string[]).includes(f.type)) {
      refused.push({ index, reason: 'type' })
    } else if (f.size < 1) {
      refused.push({ index, reason: 'empty' })
    } else if (count >= limits.images) {
      refused.push({ index, reason: 'count' })
    } else if (bytes + f.size > limits.bytes) {
      refused.push({ index, reason: 'bytes' })
    } else {
      accepted.push(index)
      count += 1
      bytes += f.size
    }
  })
  return { accepted, refused }
}

/**
 * The language the server counted for a block without English: the first local language that has text (ruling 2). Null when every
 * spoken block with text has English, so no hint is needed.
 */
export function estimateFallbackLanguage(script: WaveScript): string | null {
  const local = localLanguages(script.languages)
  if (local.length === 0) return null
  const spoken: Record<string, string>[] = [
    ...script.hooks.map((h) => h.texts.audio),
    ...script.lines.map((l) => l.texts),
  ]
  for (const block of spoken) {
    if ((block[EN] ?? '').trim() !== '') continue
    const used = local.find((l) => (block[l] ?? '').trim() !== '')
    if (used !== undefined) return used
  }
  return null
}

/** The text a collapsed block quotes: English, else the first local language that has text. */
export function quoteOf(texts: Record<string, string>, languages: readonly string[]): string {
  for (const l of languages) {
    const text = (texts[l] ?? '').trim().replace(/\s+/g, ' ')
    if (text !== '') return text
  }
  return ''
}

/** What a script view shows: one language, or two side by side (English and one chosen local language). */
export type LanguageView = { mode: 'single'; language: string } | { mode: 'side'; local: string }

/** Keeps a view valid after the languages changed: a language that is gone falls back to English. */
export function normalizeView(view: LanguageView, languages: readonly string[]): LanguageView {
  const local = localLanguages(languages)
  if (view.mode === 'side') {
    if (local.includes(view.local)) return view
    return local[0] === undefined
      ? { mode: 'single', language: EN }
      : { mode: 'side', local: local[0] }
  }
  return languages.includes(view.language) ? view : { mode: 'single', language: EN }
}

/** The text columns a view draws, in order. */
export function viewLanguages(view: LanguageView): string[] {
  return view.mode === 'side' ? [EN, view.local] : [view.language]
}

/** Every image a script holds, across hooks and lines (the usage the dialogs and limits read). */
export function allImages(script: WaveScript): ScriptImage[] {
  return [...script.hooks.flatMap((h) => h.images), ...script.lines.flatMap((l) => l.images)]
}

/** A line draft with the B-roll rows of the server's copy: a row added elsewhere appears (with the server's fields), a row deleted elsewhere goes; texts, CTA and the fields of rows both know stay as they are. */
export function reconcileLine(value: LineValue, remote: LineValue): LineValue {
  const broll: Record<string, BrollValue> = {}
  for (const [id, row] of Object.entries(remote.broll)) broll[id] = value.broll[id] ?? row
  return { ...value, texts: { ...value.texts }, broll }
}

/** The languages a market can add (two-letter codes, English left out: it is always there). The 24 UI languages and a few neighbours. */
export const SCRIPT_LANGUAGE_OPTIONS = [
  'bg',
  'bs',
  'cs',
  'da',
  'de',
  'el',
  'es',
  'et',
  'fi',
  'fr',
  'ga',
  'hr',
  'hu',
  'is',
  'it',
  'lt',
  'lv',
  'mk',
  'mt',
  'nl',
  'no',
  'pl',
  'pt',
  'ro',
  'ru',
  'sk',
  'sl',
  'sq',
  'sr',
  'sv',
  'tr',
  'uk',
] as const

/** A language's name in the viewer's language ("Slovak"); the upper-case code when the browser does not know it. */
export function languageName(code: string, locale: string): string {
  try {
    const name = new Intl.DisplayNames([locale], { type: 'language' }).of(code)
    return name === undefined || name === code ? code.toUpperCase() : name
  } catch {
    return code.toUpperCase()
  }
}

/** "English + Slovak + Hungarian". */
export function languageListLabel(languages: readonly string[], locale: string): string {
  return languages.map((l) => languageName(l, locale)).join(' + ')
}

/** The most live scripts one creator holds in a market (the server's `scripts_per_creator`). */
export const LIVE_SCRIPTS_MAX = 3

/**
 * What happens to a creator's archived scripts when they come into a market (Add, or Replace): the scripts carried over from the
 * old creator come first, then the creator's own archived scripts fill what is left of the three places, lowest number first; the
 * rest stay archived. The server does exactly this (`ScriptArchive::restore`); the dialog says it before the click.
 */
export function archivedReturn(
  archived: number,
  carried: number,
  max: number = LIVE_SCRIPTS_MAX,
): { back: number; stay: number } {
  const back = Math.max(0, Math.min(archived, max - carried))
  return { back, stay: Math.max(0, archived - back) }
}
