/**
 * PL-3.2a — the pure side of the editor: what a save sends (only what changed), how a failure is read, the worst-state chip, and
 * the persona colour key.
 */
import { ApiError } from '@catalyst/api-client'
import { describe, expect, it } from 'vitest'

import {
  briefPayload,
  briefValue,
  archivedReturn,
  classifyFailure,
  hookHasContent,
  hookPayload,
  hookValue,
  isStuck,
  isUnsaved,
  linePayload,
  lineValue,
  personaColorKey,
  scriptSaveState,
} from './scriptsLogic'
import { isGone } from './strategyErrors'
import { conflict, makeHook, makeLine, makeScript, PERSONA_REF } from './scriptsTestKit'

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
    const p = hookPayload(
      { shot: 'wide', visual: '', audio: 'hi', overlay: '', local: { audio: {}, overlay: {} } },
      7,
    )
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

describe('scriptsLogic (PL-3.2b walk rulings)', () => {
  it('the line shot type and visual description are sent only when changed; blank sends null', () => {
    const line = makeLine(1)
    const base = lineValue(line)
    expect(linePayload({ ...base }, base, 3, ['en'])).toEqual({ revision: 3 })
    expect(linePayload({ ...base, shot: 'close_up' }, base, 3, ['en'])).toEqual({
      revision: 3,
      shot_type: 'close_up',
    })
    expect(linePayload({ ...base, visual: 'A hand opens the bag' }, base, 3, ['en'])).toEqual({
      revision: 3,
      visual_description: 'A hand opens the bag',
    })
    const had = lineValue(makeLine(1, { visual_description: 'old' }))
    expect(linePayload({ ...had, visual: '  ' }, had, 3, ['en'])).toEqual({
      revision: 3,
      visual_description: null,
    })
  })

  it('the setting is sent only when changed; blank sends null', () => {
    const base = briefValue(makeScript().brief)
    expect(briefPayload({ ...base }, base, 4)).toEqual({ revision: 4 })
    expect(briefPayload({ ...base, setting: 'In the kitchen' }, base, 4)).toEqual({
      revision: 4,
      setting: 'In the kitchen',
    })
    const had = { ...base, setting: 'old' }
    expect(briefPayload({ ...had, setting: '' }, had, 4)).toEqual({ revision: 4, setting: null })
  })

  it('a save to a deleted hook (404 with its own code) is "gone", like a deleted line', () => {
    const err = (code: string) => new ApiError({ status: 404, code, message: 'x' })
    expect(classifyFailure(err('wave_script_hook.deleted'))).toEqual({ kind: 'gone' })
    expect(classifyFailure(err('wave_script_line.deleted'))).toEqual({ kind: 'gone' })
    // a deleted hook or line is NOT "the whole script is gone": the page stays
    expect(isGone(err('wave_script_hook.deleted'))).toBe(false)
    expect(isGone(err('wave_script_line.deleted'))).toBe(false)
    expect(isGone(err('wave_script.not_found'))).toBe(true)
  })

  it('archived scripts come back after the carried ones, up to three live; the rest stay', () => {
    expect(archivedReturn(2, 0)).toEqual({ back: 2, stay: 0 })
    expect(archivedReturn(2, 2)).toEqual({ back: 1, stay: 1 })
    expect(archivedReturn(4, 0)).toEqual({ back: 3, stay: 1 })
    expect(archivedReturn(3, 3)).toEqual({ back: 0, stay: 3 })
    expect(archivedReturn(0, 3)).toEqual({ back: 0, stay: 0 })
  })
})
