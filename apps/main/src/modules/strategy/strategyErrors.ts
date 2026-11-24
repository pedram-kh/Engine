/**
 * PL-2.1 — one reader for what the strategy API refuses with. A known `error.code` maps to a string in the
 * viewer's language; a 404 means the card (or the wave) was removed by someone else; anything else is the generic
 * "could not save". A field error the server attached to an input (a 422 validation) is read separately so the
 * dialog can show it on that input.
 */
import { ApiError, copyExceedsCapMeta, extractFieldErrors } from '@catalyst/api-client'

type Translate = (key: string, named?: Record<string, unknown>, plural?: number) => string

const CODE_KEYS: Record<string, string> = {
  'usp.max_reached': 'app.strategy.errors.uspMaxReached',
  'usp.order_mismatch': 'app.strategy.errors.uspOrderMismatch',
  'usp.in_use': 'app.strategy.errors.uspInUse',
  'persona.max_reached': 'app.strategy.errors.personaMaxReached',
  'persona.market_not_in_wave': 'app.strategy.errors.personaMarketNotInWave',
  'persona.code_taken': 'app.strategy.errors.personaCodeTaken',
  'persona.copy_invalid': 'app.strategy.errors.copyInvalid',
  'angle.already_linked': 'app.strategy.errors.angleAlreadyLinked',
  'angle.synced_read_only': 'app.strategy.errors.angleSyncedReadOnly',
  'strategy.conflict': 'app.strategy.errors.conflict',
  'concept.persona_invalid': 'app.strategy.errors.conceptPersonaInvalid',
  'concept.usp_mode_required': 'app.strategy.errors.conceptUspModeRequired',
  'concept.usp_invalid': 'app.strategy.errors.conceptUspInvalid',
  'concept.decision_invalid': 'app.strategy.errors.decisionInvalid',
  'concept.delete_failed': 'app.strategy.errors.conceptDeleteFailed',
  'concept.duplicate_failed': 'app.strategy.errors.conceptDuplicateFailed',
  'concept_image.invalid': 'app.strategy.errors.imageInvalid',
  'concept_image.unknown_upload': 'app.strategy.errors.imageUnknownUpload',
  'concept_image.already_completed': 'app.strategy.errors.imageAlreadyCompleted',
  'concept_image.delete_failed': 'app.strategy.errors.imageDeleteFailed',
  'link.invalid_url': 'app.strategy.errors.linkInvalidUrl',
  'concept_link.limit_count': 'app.strategy.errors.linkLimitCount',
  'wave.brand_archived': 'app.strategy.errors.brandArchived',
  'strategy.nothing_to_complete': 'app.strategy.errors.nothingToComplete',
  'note.thread_full': 'app.strategy.errors.noteThreadFull',
  'note.forbidden': 'app.strategy.errors.noteForbidden',
  'note.body_invalid': 'app.strategy.errors.noteBodyInvalid',
  'wave_creator.campaign_required': 'app.strategy.creators.errors.campaignRequired',
  'wave_creator.not_eligible': 'app.strategy.creators.errors.notEligible',
  'wave_creator.already_in_wave': 'app.strategy.creators.errors.alreadyInWave',
  'wave_creator.remove_blocked_in_work': 'app.strategy.creators.errors.removeBlockedInWork',
  'wave.campaign_already_linked': 'app.waves.errors.campaignAlreadyLinked',
  'wave.campaign_not_available': 'app.waves.errors.campaignNotAvailable',
  'wave_script.limit_reached': 'app.strategy.scripts.errors.limitReached',
  'wave_script.hooks_limit': 'app.strategy.scripts.errors.hooksLimit',
  'wave_script.carry_over_exceeds_limit': 'app.strategy.scripts.errors.carryOverExceeds',
  'wave_script.last_hook': 'app.strategy.scripts.errors.lastHook',
  'wave_script.language_not_available': 'app.strategy.scripts.errors.languageNotAvailable',
  'wave_creator.scripts_choice_required': 'app.strategy.scripts.errors.choiceRequired',
  forbidden: 'app.strategy.errors.forbidden',
}

/** True when the server answered "not found": the card, the wave or the whole workspace is gone. */
export function isGone(err: unknown): boolean {
  return err instanceof ApiError && err.status === 404
}

/** True for a server-side validation answer (HTTP 422 with no rule code of ours). */
function hasFieldErrors(err: unknown): err is ApiError {
  return err instanceof ApiError && Object.keys(extractFieldErrors(err)).length > 0
}

export function strategyErrorMessage(err: unknown, t: Translate): string {
  if (isGone(err)) return t('app.strategy.errors.gone')
  if (err instanceof ApiError) {
    if (err.code === 'persona.copy_exceeds_cap') {
      const meta = copyExceedsCapMeta(err)
      return meta === null
        ? t('app.strategy.errors.copyExceedsCapPlain')
        : t('app.strategy.errors.copyExceedsCap', {
            target: meta.targetCount,
            copy: meta.copyCount,
            max: meta.max,
          })
    }
    const meta = err.details[0]?.meta
    const num = (k: string): number | null => {
      const v = meta?.[k]
      return typeof v === 'number' ? v : null
    }
    if (err.code === 'persona.in_use_by_concepts') {
      return t(
        'app.strategy.errors.personaInUseByConcepts',
        { count: num('count') ?? 0 },
        num('count') ?? 0,
      )
    }
    if (err.code === 'persona.in_use_by_creators') {
      return t(
        'app.strategy.creators.errors.personaInUse',
        { count: num('count') ?? 0 },
        num('count') ?? 0,
      )
    }
    if (err.code === 'wave_creator.cap_reached') {
      return t(
        meta?.['scope'] === 'wave'
          ? 'app.strategy.creators.errors.capWave'
          : 'app.strategy.creators.errors.capMarket',
        { max: num('max') ?? 30 },
      )
    }
    if (err.code === 'wave_creator.slot_code_taken') {
      const code = meta?.['code']
      return t('app.strategy.creators.errors.slotCodeTaken', {
        code: typeof code === 'string' ? code : '',
      })
    }
    if (err.code === 'wave_script.limit_reached') {
      return t(
        num('archived') !== null && (num('archived') ?? 0) > 0
          ? 'app.strategy.scripts.errors.limitReachedArchived'
          : 'app.strategy.scripts.errors.limitReached',
        { max: num('max') ?? 3, archived: num('archived') ?? 0 },
      )
    }
    if (err.code === 'wave_script.hooks_limit') {
      return t('app.strategy.scripts.errors.hooksLimit', { max: num('max') ?? 6 })
    }
    if (err.code === 'wave_script.carry_over_exceeds_limit') {
      return t('app.strategy.scripts.errors.carryOverExceeds', {
        count: num('count') ?? 0,
        max: num('max') ?? 3,
      })
    }
    if (err.code === 'wave_creator.scripts_choice_required') {
      return t('app.strategy.scripts.errors.choiceRequired', { count: num('scripts') ?? 0 })
    }
    if (err.code === 'concept.market_full') {
      return t('app.strategy.errors.conceptMarketFull', { max: num('max') ?? 30 })
    }
    if (err.code === 'concept_image.limit_count') {
      return t('app.strategy.errors.imageLimitCount', {
        used: num('used') ?? 10,
        max: num('max') ?? 10,
      })
    }
    if (err.code === 'concept_image.limit_bytes') {
      const mb = (n: number | null): number => Math.round((n ?? 0) / (1024 * 1024))
      return t('app.strategy.errors.imageLimitBytes', {
        used: mb(num('used_bytes')),
        max: mb(num('max_bytes') ?? 300 * 1024 * 1024),
      })
    }
    const key = CODE_KEYS[err.code]
    if (key !== undefined) return t(key)
  }
  return t('app.strategy.errors.saveFailed')
}

/** The first message per input name the server attached to a validation answer; `{}` for any other error. */
export function fieldErrorsOf(err: unknown): Record<string, string> {
  if (!hasFieldErrors(err)) return {}
  const out: Record<string, string> = {}
  for (const [field, messages] of Object.entries(extractFieldErrors(err))) {
    const first = messages?.[0]
    if (first !== undefined) out[field] = first
  }
  return out
}

/**
 * The text for the dialog's error line after a failed save: none when the server's field errors all land on an
 * input the dialog shows (the input carries them), otherwise the server's own words for a field the dialog has no
 * input for (a list line, say) or, for any other error, the mapped message.
 */
export function formErrorFor(
  err: unknown,
  fields: Record<string, string>,
  shown: readonly string[],
  t: Translate,
): string | null {
  const keys = Object.keys(fields)
  if (keys.length === 0) return strategyErrorMessage(err, t)
  const stray = keys.find((k) => !shown.includes(k))
  return stray === undefined ? null : (fields[stray] ?? strategyErrorMessage(err, t))
}
