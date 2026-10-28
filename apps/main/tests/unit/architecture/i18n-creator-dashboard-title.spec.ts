/**
 * Tech-debt 16 (2026-10-28): `creator.ui.dashboard.title` ("Your application") is the heading an
 * incomplete creator sees on the dashboard, above the card that leads back into the wizard. It
 * shipped as the English string in the ten locales below. This pins that one key (explicit, so a
 * rename fails by name) against the English value and against an empty string.
 *
 * Scope is deliberately this ONE key. The other English creator-namespace keys in these locales
 * are the AH-001 i18n-completeness row, not this spec's job.
 */

import { promises as fs } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const LOCALE_ROOT = path.resolve(__dirname, '../../../src/core/i18n/locales')
const FLAKY_TEN = ['bg', 'el', 'et', 'fi', 'ga', 'hu', 'lt', 'lv', 'mt', 'ro'] as const

async function title(locale: string): Promise<unknown> {
  const raw = await fs.readFile(path.join(LOCALE_ROOT, locale, 'creator.json'), 'utf8')
  const parsed = JSON.parse(raw) as { creator?: { ui?: { dashboard?: { title?: unknown } } } }
  return parsed.creator?.ui?.dashboard?.title
}

describe('creator.ui.dashboard.title in the ten locales that shipped English', () => {
  it('has an English reference value to compare against', async () => {
    expect(await title('en')).toBe('Your application')
  })

  it.each(FLAKY_TEN)('%s: present, non-empty, and not the English string', async (locale) => {
    const value = await title(locale)

    expect(typeof value).toBe('string')
    expect((value as string).trim().length).toBeGreaterThan(0)
    expect(value).not.toBe(await title('en'))
    expect(value as string).not.toMatch(/application/i)
  })
})
