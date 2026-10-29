/**
 * AH-113 (2026-10-29): the deletion texts must not promise the account is deleted ON a date.
 *
 * The grace ends on that date and the account becomes erasable, but erasure is a manual admin
 * run (docs/runbooks/account-erasure.md §1, §1d), so the real day is later. Three strings carry
 * the date: the sign-in "scheduled for deletion" page (`auth.ui.deletion.body`), the sign-in
 * banner (`auth.ui.deletion.scheduled_banner`), and the API's confirmation mail
 * (`apps/api/lang/<locale>/auth.php`, `deletion_mail.body`). They now say "after {date}".
 *
 * Pinned in all 24 locales: (1) no deletion string matches /deleted on/i (the English guard, run
 * over every string in the deletion namespaces of every locale); (2) each of the three strings
 * carries the date placeholder exactly once, next to that locale's "after" word; (3) the en and
 * es values by word (es was read by hand, the rest are machine-consistent with it).
 */

import { promises as fs } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const LOCALE_ROOT = path.resolve(__dirname, '../../../src/core/i18n/locales')
const API_LANG_ROOT = path.resolve(__dirname, '../../../../api/lang')

const LOCALES = [
  'bg', 'cs', 'da', 'de', 'el', 'en', 'es', 'et', 'fi', 'fr', 'ga', 'hr',
  'hu', 'it', 'lt', 'lv', 'mt', 'nl', 'pl', 'pt', 'ro', 'sk', 'sl', 'sv',
] as const // prettier-ignore

type Locale = (typeof LOCALES)[number]

/** What must sit around the date in each locale ("after {date}"). `$` stands for the placeholder. */
const AFTER: Record<Locale, string> = {
  bg: 'след $',
  cs: 'po $',
  da: 'efter den $',
  de: 'nach dem $',
  el: 'μετά τις $',
  en: 'after $',
  es: 'después del $',
  et: 'pärast $',
  fi: '$ jälkeen',
  fr: 'après le $',
  ga: 'tar éis $',
  hr: 'nakon $',
  hu: '$ után',
  it: 'dopo il $',
  lt: 'po $',
  lv: 'pēc $',
  mt: 'wara l-$',
  nl: 'na $',
  pl: 'po $',
  pt: 'depois de $',
  ro: 'după $',
  sk: 'po $',
  sl: 'po $',
  sv: 'efter den $',
} // prettier-ignore

function around(template: string, placeholder: string): string {
  return template.replace('$', placeholder)
}

async function authJson(locale: string): Promise<Record<string, unknown>> {
  const raw = await fs.readFile(path.join(LOCALE_ROOT, locale, 'auth.json'), 'utf8')
  return JSON.parse(raw) as Record<string, unknown>
}

function deletionBlock(parsed: Record<string, unknown>): {
  body: string
  scheduled_banner: string
} {
  const ui = (parsed.auth as { ui: { deletion: { body: unknown; scheduled_banner: unknown } } }).ui
  expect(typeof ui.deletion.body).toBe('string')
  expect(typeof ui.deletion.scheduled_banner).toBe('string')
  return ui.deletion as { body: string; scheduled_banner: string }
}

/** The `deletion_mail.body` value of `apps/api/lang/<locale>/auth.php`, unescaped. */
async function mailBody(locale: string): Promise<string> {
  const php = await fs.readFile(path.join(API_LANG_ROOT, locale, 'auth.php'), 'utf8')
  const block = php.slice(php.indexOf("'deletion_mail' => ["))
  const match = /'body' => '((?:[^'\\]|\\.)*)',/.exec(block)
  expect(match).not.toBeNull()
  return (match?.[1] ?? '').replace(/\\'/g, "'")
}

function strings(node: unknown, out: string[] = []): string[] {
  if (typeof node === 'string') out.push(node)
  else if (node !== null && typeof node === 'object') {
    for (const v of Object.values(node)) strings(v, out)
  }
  return out
}

describe('AH-113: no deletion string promises deletion "on" a date', () => {
  it.each(LOCALES)(
    '%s: no string in the deletion namespaces matches /deleted on/i',
    async (locale) => {
      const parsed = await authJson(locale)
      const creator = JSON.parse(
        await fs.readFile(path.join(LOCALE_ROOT, locale, 'creator.json'), 'utf8'),
      ) as { creator: { ui: { security: unknown } } }
      const all = [
        ...strings((parsed.auth as { ui: { deletion: unknown } }).ui.deletion),
        ...strings(parsed.auth && (parsed.auth as { deletion_mail?: unknown }).deletion_mail),
        ...strings(creator.creator.ui.security),
        await mailBody(locale),
      ]

      expect(all.length).toBeGreaterThan(5)
      for (const text of all) expect(text).not.toMatch(/deleted on/i)
    },
  )

  it.each(LOCALES)(
    '%s: body, banner and mail say "after {date}" and no longer "on {date}"',
    async (locale) => {
      const { body, scheduled_banner: banner } = deletionBlock(await authJson(locale))
      const mail = await mailBody(locale)

      for (const [text, placeholder] of [
        [body, '{date}'],
        [banner, '{date}'],
        [mail, ':date'],
      ] as const) {
        expect(text.split(placeholder)).toHaveLength(2) // exactly one placeholder
        expect(text).toContain(around(AFTER[locale], placeholder))
      }
    },
  )

  it('en: the three strings, word for word', async () => {
    const { body, scheduled_banner: banner } = deletionBlock(await authJson('en'))

    expect(body).toBe(
      'It will be permanently deleted after {date}. Until then you can restore it, and everything will be as you left it.',
    )
    expect(banner).toBe(
      'Your account is scheduled for deletion after {date}. Sign in before then to restore it.',
    )
    expect(await mailBody('en')).toBe(
      'We received your request to delete your :app account. It will be permanently deleted after :date, and this cannot be undone after that.',
    )
  })

  it('es: the three strings, word for word (read by hand)', async () => {
    const { body, scheduled_banner: banner } = deletionBlock(await authJson('es'))

    expect(body).toBe(
      'Se eliminará de forma permanente después del {date}. Hasta entonces puedes restaurarla y todo estará como lo dejaste.',
    )
    expect(banner).toBe(
      'Tu cuenta está programada para eliminarse después del {date}. Inicia sesión antes de esa fecha para restaurarla.',
    )
    expect(await mailBody('es')).toBe(
      'Hemos recibido tu solicitud para eliminar tu cuenta de :app. Se eliminará de forma permanente después del :date y, una vez eliminada, no se podrá deshacer.',
    )
  })
})
