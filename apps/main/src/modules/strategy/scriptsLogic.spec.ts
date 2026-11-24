/**
 * PL-3.2a — the pure side of the editor: what a save sends (only what changed), how a failure is read, the worst-state chip, and
 * the persona colour key.
 */
import { ApiError } from '@catalyst/api-client'
import { describe, expect, it } from 'vitest'

import {
  briefPayload,
  briefValue,
  classifyFailure,
  hookHasContent,
  hookPayload,
  hookValue,
  isStuck,
  isUnsaved,
  personaColorKey,
  scriptSaveState,
} from './scriptsLogic'
import { conflict, makeHook, makeScript, PERSONA_REF } from './scriptsTestKit'

describe('scriptsLogic (PL-3.2a)', () => {
  it('an untouched angle is never sent, so a live angle never turns into the script text', () => {
    const base = briefValue(makeScript().brief)
    expect(briefPayload({ ...base }, base, 4)).toEqual({ revision: 4 })
    expect(briefPayload({ ...base, duration: 45 }, base, 4)).toEqual({
      revision: 4,
      duration_seconds: 45,
    })
    expect(briefPayload({ ...base, angle: 'mine' }, base, 4).angle).toBe('mine')
  })

  it('"use the persona angle" sends null; a blanked angle sends null too', () => {
    const base = briefValue(makeScript().brief)
    expect(briefPayload({ ...base, reset: true }, base, 1).angle).toBeNull()
    expect(briefPayload({ ...base, angle: '   ' }, base, 1).angle).toBeNull()
  })

  it('a hook sends the whole block with its revision; an empty visual is null', () => {
    const p = hookPayload({ shot: 'wide', visual: '', audio: 'hi', overlay: '' }, 7)
    expect(p).toEqual({
      shot_type: 'wide',
      visual_description: null,
      texts: { audio: { en: 'hi' }, overlay: { en: '' } },
      revision: 7,
    })
  })

  it('hookValue reads English text; hookHasContent sees any field', () => {
    const h = makeHook(1, { texts: { audio: { en: 'x' }, overlay: {} } })
    expect(hookValue(h).audio).toBe('x')
    expect(hookHasContent(h)).toBe(true)
    expect(hookHasContent(makeHook(2))).toBe(false)
  })

  it('classifies failures: 409 conflict with the other version, 404 gone, 422 refused, 5xx and network retry', () => {
    const theirs = makeHook(1)
    expect(classifyFailure(conflict('hook', theirs))).toEqual({
      kind: 'conflict',
      block: 'hook',
      current: theirs,
    })
    expect(classifyFailure(new ApiError({ status: 404, code: 'x', message: 'x' })).kind).toBe(
      'gone',
    )
    expect(classifyFailure(new ApiError({ status: 422, code: 'x', message: 'x' })).kind).toBe(
      'refused',
    )
    expect(classifyFailure(new ApiError({ status: 429, code: 'x', message: 'x' })).kind).toBe(
      'retry',
    )
    expect(classifyFailure(new ApiError({ status: 503, code: 'x', message: 'x' })).kind).toBe(
      'retry',
    )
    expect(classifyFailure(new Error('offline')).kind).toBe('retry')
    expect(classifyFailure(new ApiError({ status: 409, code: 'other', message: 'x' })).kind).toBe(
      'refused',
    )
  })

  it('the script chip shows the worst block: stuck beats saving beats saved', () => {
    expect(scriptSaveState(['saved', 'idle'])).toEqual({ kind: 'saved', stuck: 0 })
    expect(scriptSaveState(['saved', 'dirty'])).toEqual({ kind: 'saving', stuck: 0 })
    expect(scriptSaveState(['saving', 'failed', 'conflict'])).toEqual({ kind: 'stuck', stuck: 2 })
    expect(isUnsaved('saved')).toBe(false)
    expect(isUnsaved('failed')).toBe(true)
    expect(isStuck('dirty')).toBe(false)
  })

  it('the persona colour key is null with no persona', () => {
    expect(personaColorKey(null)).toBeNull()
    expect(personaColorKey(PERSONA_REF)).toBe('blue')
  })
})
