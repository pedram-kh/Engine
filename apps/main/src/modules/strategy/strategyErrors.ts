/**
 * PL-2.1 — one reader for what the strategy API refuses with. A known `error.code` maps to a string in the
 * viewer's language; a 404 means the card (or the wave) was removed by someone else; anything else is the generic
 * "could not save". A field error the server attached to an input (a 422 validation) is read separately so the
 * dialog can show it on that input.
 */
import { ApiError, copyExceedsCapMeta, extractFieldErrors } from '@catalyst/api-client'

type Translate = (key: string, named?: Record<string, unknown>) => string

const CODE_KEYS: Record<string, string> = {
  'usp.max_reached': 'app.strategy.errors.uspMaxReached',
  'usp.order_mismatch': 'app.strategy.errors.uspOrderMismatch',
  'usp.in_use': 'app.strategy.errors.uspInUse',
  'persona.max_reached': 'app.strategy.errors.personaMaxReached',
  'persona.market_not_in_wave': 'app.strategy.errors.personaMarketNotInWave',
  'persona.code_taken': 'app.strategy.errors.personaCodeTaken',
  'persona.copy_invalid': 'app.strategy.errors.copyInvalid',
  'angle.already_linked': 'app.strategy.errors.angleAlreadyLinked',
  'strategy.conflict': 'app.strategy.errors.conflict',
  'wave.brand_archived': 'app.strategy.errors.brandArchived',
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
