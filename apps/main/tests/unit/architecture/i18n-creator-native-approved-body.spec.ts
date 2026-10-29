/**
 * AH-111 (2026-10-29): `creator.ui.dashboard.native.approved_body` is the banner every approved
 * creator sees on the native app. It used to promise "more of your creator dashboard is coming
 * soon", which App Review reads as incomplete or placeholder content (guideline 2.1/4.2). It now
 * states what the app does today and promises nothing.
 *
 * This pins that ONE key, in all 24 locales. The check is on the reworded en and es values by
 * word (the two locales a human reads), plus a per-locale guard that the old promise sentence
 * did not survive in the translation (each locale's old text is listed by its distinctive stem).
 * The web "ships soon" key (`creator.ui.dashboard.approved.description`, en/creator.json:341) is a different key
 * and is deliberately NOT covered here (Q1: it changes after the store release, tech-debt 17).
 */

import { promises as fs } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const LOCALE_ROOT = path.resolve(__dirname, '../../../src/core/i18n/locales')

const LOCALES = [
  'bg', 'cs', 'da', 'de', 'el', 'en', 'es', 'et', 'fi', 'fr', 'ga', 'hr',
  'hu', 'it', 'lt', 'lv', 'mt', 'nl', 'pl', 'pt', 'ro', 'sk', 'sl', 'sv',
] as const // prettier-ignore

/** The "…more is coming soon" tail each locale shipped before AH-111. */
const OLD_PROMISE: Record<(typeof LOCALES)[number], string> = {
  bg: 'скоро ще бъде налично',
  cs: 'brzy přibudou',
  da: 'kommer snart',
  de: 'folgen bald',
  el: 'έρχονται σύντομα',
  en: 'is coming soon',
  es: 'Pronto llegará',
  et: 'tuleb varsti',
  fi: 'on tulossa pian',
  fr: 'arrive bientôt',
  ga: 'ag teacht go luath',
  hr: 'stiže uskoro',
  hu: 'hamarosan érkeznek',
  it: 'Presto arriveranno',
  lt: 'netrukus',
  lv: 'Drīz parādīsies',
  mt: 'se jasal dalwaqt',
  nl: 'komt er meer van',
  pl: 'pojawi się wkrótce',
  pt: 'Em breve',
  ro: 'În curând',
  sk: 'čoskoro pribudnú',
  sl: 'bo kmalu na voljo',
  sv: 'kommer snart',
}

async function approvedBody(locale: string): Promise<string> {
  const raw = await fs.readFile(path.join(LOCALE_ROOT, locale, 'creator.json'), 'utf8')
  const parsed = JSON.parse(raw) as {
    creator?: { ui?: { dashboard?: { native?: { approved_body?: unknown } } } }
  }
  const value = parsed.creator?.ui?.dashboard?.native?.approved_body
  expect(typeof value).toBe('string')
  return value as string
}

describe('creator.ui.dashboard.native.approved_body makes no "coming soon" promise', () => {
  it('en: states what works today, with no "soon" and no "coming"', async () => {
    const en = await approvedBody('en')

    expect(en).not.toMatch(/soon/i)
    expect(en).not.toMatch(/coming/i)
    expect(en).toMatch(/invitations/i)
  })

  it('es: no "pronto" and no "próximamente"', async () => {
    const es = await approvedBody('es')

    expect(es).not.toMatch(/pronto/i)
    expect(es).not.toMatch(/pr[óo]ximamente/i)
  })

  it.each(LOCALES)('%s: non-empty and the old promise sentence is gone', async (locale) => {
    const value = await approvedBody(locale)

    expect(value.trim().length).toBeGreaterThan(0)
    expect(value).not.toContain(OLD_PROMISE[locale])
  })
})
