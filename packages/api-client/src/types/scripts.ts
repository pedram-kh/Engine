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

/** The longest angle, audio hook, overlay and visual description (characters). `StrategyLimits.php` is the source. */
export const SCRIPT_TEXT_LIMITS = { angle: 300, audio: 300, overlay: 100, visual: 400 } as const

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
  limits: { scripts_per_creator: number; hooks_per_script: number }
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
  /** The hook block's own revision: send it back with the save. */
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
  hooks: ScriptHook[]
  estimate: { words: number; seconds: number }
  updated_at: string
}

export interface ArchivedScript {
  id: string
  number: number
  hooks: number
  creator: ScriptCreatorRef
  archived_at: string | null
  /** True when the creator is in this market again (the script then came back on its own). */
  creator_in_market: boolean
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

/** What happens to a slot's scripts on Replace and Remove. */
export type ScriptsOnReplace = 'carry_over' | 'archive'
export type ScriptsOnRemove = 'archive' | 'delete'

/** `errors[0].meta` of a `wave_script.conflict` (409): the block as it is now. */
export interface ScriptConflictMeta {
  block: 'brief' | 'hook'
  current: ScriptBrief | ScriptHook
}
