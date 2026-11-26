/**
 * PL-3.2a — the scripts of a wave (step 6 of the Strategy workspace). Mirrors `ScriptPresenter` in `apps/api`
 * (`MOD/Strategy`). Ulids only. The hook letter is derived from `position` and never stored.
 */

/** The ten shot types, in the order the editor lists them. `ShotType.php` is the source; `scripts.spec.ts` keeps both equal. */
export const SHOT_TYPES = [
  'wide',
  'medium',
  'close_up',
  'detail',
  'pov',
  'over_the_shoulder',
  'top_down',
  'screen_recording',
  'reaction',
  'creator_to_camera',
] as const
export type ShotType = (typeof SHOT_TYPES)[number]

/** The durations a script can have, in seconds. */
export const SCRIPT_DURATIONS = [15, 20, 30, 45, 60] as const
export type ScriptDuration = (typeof SCRIPT_DURATIONS)[number]

/**
 * The longest angle, audio hook, overlay and visual description, spoken line text and B-roll description (characters).
 * `StrategyLimits.php` is the source.
 */
export const SCRIPT_TEXT_LIMITS = {
  angle: 300,
  audio: 300,
  overlay: 100,
  visual: 400,
  line: 600,
  broll: 400,
} as const

/** The image types a script reference accepts (the server reads the real bytes and has the last word). */
export const SCRIPT_IMAGE_MIME_TYPES = [
  'image/jpeg',
  'image/png',
  'image/webp',
  'image/gif',
] as const

export type ScriptStepState = 'not_started' | 'in_progress' | 'done'

export interface ScriptCreatorRef {
  id: string
  /** True once the creator was erased: the screen shows "Deleted creator" and `display_name` is null. */
  is_erased: boolean
  display_name: string | null
  primary_language: string | null
  secondary_languages: string[]
}

export interface ScriptPersonaRef {
  id: string
  code: string
  title: string
  slot: number
  /** The persona's palette key: the colour of the slot chip. */
  color_key: string
}

export interface ScriptRailScript {
  id: string
  number: number
  hooks: number
  duration_seconds: number
  revision: number
  updated_at: string
}

export interface ScriptRailCreator {
  /** The creator slot's ulid (the `slot` of a create). */
  id: string
  slot: number
  slot_code: string
  creator: ScriptCreatorRef
  persona: ScriptPersonaRef | null
  scripts: ScriptRailScript[]
  /** False at three scripts (archived ones count). */
  can_add_script: boolean
}

export interface ScriptRail {
  market: string
  state: ScriptStepState
  creators: ScriptRailCreator[]
  /** Internal notes of the `scripts` step in this market. */
  notes: number
}

export interface ScriptsOverview {
  step: { state: ScriptStepState; markets_total: number; markets_started: number }
  markets: { id: string; state: ScriptStepState; scripts: number; archived_scripts: number }[]
  /** Present only when the request carried `?market=`. */
  rail: ScriptRail | null
  limits: ScriptLimits
}

/** The limits the screens show (near the limit only). `StrategyLimits.php` is the source. */
export interface ScriptLimits {
  scripts_per_creator: number
  hooks_per_script: number
  lines_per_script: number
  broll_per_script: number
  images_per_block: number
  image_total_bytes: number
  local_languages_per_market: number
}

/** One ready reference image of a hook or a line. The two URLs are API paths behind the session (read with the app's client). */
export interface ScriptImage {
  id: string
  position: number
  original_filename: string
  mime_type: string
  size_bytes: number | null
  has_thumbnail: boolean
  file_url: string
  thumbnail_url: string | null
}

export interface ScriptBriefUsp {
  id: string
  title: string
  color_key: string
  level: 'priority' | 'support'
}

export type ScriptAngleSource = 'script' | 'persona' | 'matrix' | 'none'

export interface ScriptBrief {
  persona: ScriptPersonaRef | null
  usps: ScriptBriefUsp[]
  angle: { value: string | null; source: ScriptAngleSource; custom: boolean }
  duration_seconds: number
  /** The brief block's own revision: send it back with the save. */
  revision: number
}

/** `texts[field][language]`; English is `en`. An empty text is absent. */
export type ScriptHookTexts = Record<'audio' | 'overlay', Record<string, string>>

export interface ScriptHook {
  id: string
  position: number
  /** Derived from `position`: A, B, C ... */
  letter: string
  shot_type: ShotType | null
  visual_description: string | null
  texts: ScriptHookTexts
  images: ScriptImage[]
  /** The hook block's own revision: send it back with the save. */
  revision: number
}

/** One B-roll shot of a line. The `code` is `{line}_{n}`, derived from positions: a display, never a key (the `id` is the key). */
export interface ScriptBroll {
  id: string
  position: number
  code: string
  shot_type: ShotType | null
  description: string | null
}

/** One line of a script. `number` runs from 2 (the hook is line 1) and is derived from the position. */
export interface ScriptLine {
  id: string
  position: number
  number: number
  cta: boolean
  /** Spoken text by language (`en` first); an empty text is absent. */
  texts: Record<string, string>
  broll: ScriptBroll[]
  images: ScriptImage[]
  /** The line block's own revision: send it back with the save. */
  revision: number
}

export interface WaveScript {
  id: string
  number: number
  /** Moves on every change of the script or any block of it. */
  revision: number
  archived: boolean
  market: string
  slot: { id: string; slot_code: string } | null
  creator: ScriptCreatorRef
  brief: ScriptBrief
  /** The market's script languages: `en` first, then up to three local ones, in order. */
  languages: string[]
  hooks: ScriptHook[]
  lines: ScriptLine[]
  usage: { hooks: number; lines: number; broll: number; image_bytes: number }
  limits: ScriptLimits
  estimate: { words: number; seconds: number }
  updated_at: string
}

export interface ArchivedScript {
  id: string
  number: number
  hooks: number
  lines: number
  images: number
  creator: ScriptCreatorRef
  archived_at: string | null
  /** True while the creator holds a slot in this market. When they are not, the script comes back when they are added again. */
  creator_in_market: boolean
  /** True when "Restore" works now: the creator is in the market and has fewer than three live scripts. */
  can_restore: boolean
}

export interface ScriptBriefPayload {
  /** A text, or null to go back to the persona's live angle. */
  angle?: string | null
  duration_seconds?: ScriptDuration
  revision: number
}

export interface ScriptHookPayload {
  shot_type?: ShotType | null
  visual_description?: string | null
  texts?: Partial<Record<'audio' | 'overlay', Record<string, string | null>>>
  revision: number
}

/** The save of one line block: only what the person changed (the B-roll rows by their ids). */
export interface ScriptLinePayload {
  texts?: Record<string, string | null>
  cta?: boolean
  broll?: Record<string, { shot_type?: ShotType | null; description?: string | null }>
  revision: number
}

/** The market's script languages (the local ones, in order) with what holds text in each. */
export interface ScriptLanguagesResource {
  market: string
  max_local: number
  languages: { code: string; position: number; scripts: number; texts: number }[]
}

/** The answer of `images/uploads/init`: where the browser sends the bytes, and the id to complete with. */
export interface ScriptImageInit {
  upload_url: string
  upload_id: string
  expires_at: string
  max_bytes: number
}

/** What happens to a slot's scripts on Replace and Remove. */
export type ScriptsOnReplace = 'carry_over' | 'archive'
export type ScriptsOnRemove = 'archive' | 'delete'

/** `errors[0].meta` of a `wave_script.conflict` (409): the block as it is now. */
export interface ScriptConflictMeta {
  block: 'brief' | 'hook' | 'line'
  current: ScriptBrief | ScriptHook | ScriptLine
}
