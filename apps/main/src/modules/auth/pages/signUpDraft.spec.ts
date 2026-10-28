import { describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

import { NAME_MAX_LENGTH, SIGN_UP_DRAFT_KEY, draftState, readSignUpDraft } from './signUpDraft'

const Stub = { template: '<div />' }

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/sign-up', name: 'auth.sign-up', component: Stub },
      { path: '/verify-email/pending', name: 'auth.verify-email.pending', component: Stub },
    ],
  })
}

describe('signUpDraft: the two names that travel through history.state', () => {
  it('survives the real round trip: sign-up → pending → sign-up, the email never in it', async () => {
    const router = makeRouter()

    await router.push({
      name: 'auth.verify-email.pending',
      query: { email: 'typo@cataylst.local' },
      state: draftState({ name: 'Alice', last_name: 'Walker' }),
    })
    expect(readSignUpDraft(router)).toEqual({ name: 'Alice', last_name: 'Walker' })

    await router.push({ name: 'auth.sign-up', state: draftState(readSignUpDraft(router)!) })
    expect(readSignUpDraft(router)).toEqual({ name: 'Alice', last_name: 'Walker' })
    expect(JSON.stringify(router.options.history.state)).not.toContain('cataylst')
    expect(router.currentRoute.value.fullPath).toBe('/sign-up')
  })

  it('is gone on a fresh visit: a new history entry starts blank', async () => {
    const router = makeRouter()
    await router.push({ name: 'auth.sign-up', state: draftState({ name: 'A', last_name: 'B' }) })
    expect(readSignUpDraft(router)).not.toBeNull()

    await router.push({ name: 'auth.sign-up', query: { fresh: '1' } })

    expect(readSignUpDraft(router)).toBeNull()
  })

  it('keeps only the two named fields, whatever else was put under the key', async () => {
    const router = makeRouter()
    await router.push({
      name: 'auth.sign-up',
      state: {
        [SIGN_UP_DRAFT_KEY]: { name: 'A', last_name: 'B', password: 'secret', email: 'x@y.z' },
      },
    })

    expect(readSignUpDraft(router)).toEqual({ name: 'A', last_name: 'B' })
  })

  it('trims and caps each name at the backend limit, and drops non-strings', async () => {
    const router = makeRouter()
    await router.push({
      name: 'auth.sign-up',
      state: { [SIGN_UP_DRAFT_KEY]: { name: `  ${'x'.repeat(500)}  `, last_name: 7 } },
    })

    expect(readSignUpDraft(router)).toEqual({ name: 'x'.repeat(NAME_MAX_LENGTH), last_name: '' })
    expect(NAME_MAX_LENGTH).toBe(120)
  })

  it.each([
    ['a string', 'Alice Walker'],
    ['an array', ['Alice', 'Walker']],
    ['null', null],
    ['a number', 5],
  ])('treats %s under the key as no draft', async (_label, value) => {
    const router = makeRouter()
    await router.push({ name: 'auth.sign-up', state: { [SIGN_UP_DRAFT_KEY]: value as never } })

    expect(readSignUpDraft(router)).toBeNull()
  })
})
