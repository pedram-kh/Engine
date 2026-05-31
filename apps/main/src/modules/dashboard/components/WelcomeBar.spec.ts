/**
 * Sprint 4 Chunk 1 (1b) — Vitest coverage for the WelcomeBar, under the
 * theme-aware dashboard harness.
 *
 * Pins: the user name renders in the greeting; a locale-aware date renders
 * (formatted via `Intl.DateTimeFormat` keyed to the active locale); and the
 * aurora-bearing welcome-bar element is present (the aurora rule itself is
 * source-locked by `aurora-surfacing.spec.ts`).
 */

import { afterEach, describe, expect, it } from 'vitest'

import { mountDashboardPage } from '../../../../tests/unit/helpers/mountDashboardPage'

import WelcomeBar from './WelcomeBar.vue'

function expectedDate(locale: string): string {
  return new Intl.DateTimeFormat(locale, {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  }).format(new Date())
}

describe('WelcomeBar (Sprint 4 Chunk 1, 1b)', () => {
  let cleanup: (() => void) | null = null

  afterEach(() => {
    cleanup?.()
    cleanup = null
  })

  it('greets the authenticated user by name', async () => {
    const h = await mountDashboardPage(WelcomeBar, { userName: 'Ada Lovelace' })
    cleanup = h.unmount

    expect(h.wrapper.find('[data-test="welcome-bar-greeting"]').text()).toContain('Ada Lovelace')
  })

  it('falls back to a name-less greeting when no user name is present', async () => {
    const h = await mountDashboardPage(WelcomeBar, { userName: null })
    cleanup = h.unmount

    const greeting = h.wrapper.find('[data-test="welcome-bar-greeting"]').text()
    expect(greeting).toBe('Welcome back')
  })

  it('renders the current date formatted for the active locale (en)', async () => {
    const h = await mountDashboardPage(WelcomeBar, { locale: 'en' })
    cleanup = h.unmount

    expect(h.wrapper.find('[data-test="welcome-bar-date"]').text()).toBe(expectedDate('en'))
  })

  it('formats the date differently under a non-en locale (it)', async () => {
    const h = await mountDashboardPage(WelcomeBar, { locale: 'it' })
    cleanup = h.unmount

    expect(h.wrapper.find('[data-test="welcome-bar-date"]').text()).toBe(expectedDate('it'))
  })

  it('renders the aurora-bearing welcome-bar element', async () => {
    const h = await mountDashboardPage(WelcomeBar)
    cleanup = h.unmount

    expect(h.wrapper.find('[data-test="welcome-bar"]').exists()).toBe(true)
  })
})
