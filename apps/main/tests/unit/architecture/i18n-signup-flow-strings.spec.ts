/**
 * AH-104d (S2, C8) — the strings the in-app sign-up flow can render, in the
 * "flaky ten" locales (bg, el, et, fi, ga, hu, lt, lv, mt, ro).
 *
 * `i18n-locale-parity.spec.ts` pins the KEY-SET only. These locales shipped
 * three kinds of string parity cannot see, all on screens a new account walks:
 *
 *  1. the value IS the English string (the translator-less fallback);
 *  2. a HYBRID: a native verb glued onto English ("Létrehozás your account",
 *     "Моля try again") — it is not equal to English, so an equality check
 *     passes it;
 *  3. a SUFFIX mangle on the loading labels ("Küldésing…", "Esitating…").
 *
 * The key list below is EXPLICIT (an absent or renamed key must fail by name,
 * not shrink the check). It is the set the sign-up sheet, the verification
 * pending/confirm pages, the sign-in sheet and the server error codes they
 * resolve can render. The ~48 other English keys in these locales (2FA,
 * forgot/reset password, the agency invitation pages) are tracked in the
 * AH-001 i18n-completeness row, not here.
 */

import { promises as fs } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const LOCALE_ROOT = path.resolve(__dirname, '../../../src/core/i18n/locales')

const FLAKY_TEN = ['bg', 'el', 'et', 'fi', 'ga', 'hu', 'lt', 'lv', 'mt', 'ro'] as const

const FLOW_KEYS = [
  // verification screens
  'auth.ui.headings.verify_email_pending',
  'auth.ui.headings.verify_email_confirm',
  'auth.ui.descriptions.verify_email_pending',
  'auth.ui.banners.verification_email_sent',
  'auth.ui.banners.email_verified',
  'auth.ui.actions.resend_verification',
  'auth.creator_invitation.banners.completing_invitation',
  // the field labels on the sign-up and sign-in sheets (W12 2026-10-28: Maltese showed "Email" / "Password")
  'auth.ui.labels.email',
  'auth.ui.labels.password',
  'auth.ui.labels.password_confirmation',
  // sign-up page chrome
  'auth.ui.headings.sign_up',
  'auth.ui.actions.back_to_sign_in',
  'auth.ui.headings.forgot_password',
  'auth.ui.errors.validation_password_match',
  // loading labels (submit button, resend, verify, sign-in)
  'auth.ui.loading.submitting',
  'auth.ui.loading.sending',
  'auth.ui.loading.verifying',
  'auth.ui.loading.logging_in',
  // server error codes the sign-up and verify screens resolve
  'rate_limit.exceeded',
  'auth.password.breached',
  'auth.password.too_short',
  'auth.password.too_long',
  'auth.email.verification_expired',
  'auth.email.verification_invalid',
  'auth.email.already_verified',
  // generic UI errors
  'auth.ui.errors.network',
  'auth.ui.errors.unknown',
  'auth.ui.errors.validation_required',
  'auth.ui.errors.validation_email',
  'auth.ui.errors.validation_min_length',
  'auth.ui.errors.validation_max_length',
  'auth.ui.errors.missing_token',
  // sign-in right after verification
  'auth.invalid_credentials',
  'auth.account_locked.suspended',
  'auth.account_locked.temporary',
  'auth.mfa_required',
  'auth.mfa.invalid_code',
  'auth.ui.banners.session_expired',
] as const

/**
 * Keys that are the English word because the language really uses it, so equality with
 * English is not a failure there. Each entry is a decision, not a gap: Greek and Romanian
 * product UIs label this field "Email", and a machine translation would only make it worse.
 * Maltese is NOT here: it has "Indirizz tal-email".
 */
const GENUINE_LOANWORD: Record<string, readonly string[]> = {
  el: ['auth.ui.labels.email'],
  ro: ['auth.ui.labels.email'],
}

async function load(locale: string): Promise<Record<string, unknown>> {
  const file = path.join(LOCALE_ROOT, locale, 'auth.json')
  return JSON.parse(await fs.readFile(file, 'utf8')) as Record<string, unknown>
}

function lookup(bundle: Record<string, unknown>, dotted: string): unknown {
  let node: unknown = bundle
  for (const part of dotted.split('.')) {
    if (node === null || typeof node !== 'object') return undefined
    node = (node as Record<string, unknown>)[part]
  }
  return node
}

/**
 * Loanwords these locales really use ("Email" in el/mt/ro, "password" and
 * "link" in mt/ro): not evidence of an untranslated string, so they do not
 * count toward the overlap.
 */
const LOANWORDS = new Set([
  'email',
  'password',
  'passwords',
  'inbox',
  'link',
  'setup',
  'privacy', // "informativa sulla privacy" is idiomatic Italian
  'catalyst', // the product name is not a translation failure
])

const stripPlaceholders = (s: string): string => s.replace(/\{[^}]*\}/g, '')
const englishWords = (s: string): Set<string> =>
  new Set(
    (
      stripPlaceholders(s)
        .toLowerCase()
        .match(/[a-z']{3,}/g) ?? []
    ).filter((w) => !LOANWORDS.has(w)),
  )
const placeholders = (s: string): string[] => (s.match(/\{[^}]*\}/g) ?? []).slice().sort()

/** Share of the English string's words that appear verbatim in the locale's string. */
function overlap(english: string, localised: string): number {
  const ew = englishWords(english)
  if (ew.size < 2) return 0
  const lw = englishWords(localised)
  let hit = 0
  for (const w of ew) if (lw.has(w)) hit++
  return hit / ew.size
}

describe('overlap() — the hybrid detector is not vacuous', () => {
  it('catches a native verb glued onto English, an English string, and passes real text', () => {
    expect(overlap('Create your account', 'Létrehozás your account')).toBeGreaterThanOrEqual(0.5)
    expect(overlap('Please try again.', 'Моля try again.')).toBeGreaterThanOrEqual(0.5)
    expect(overlap('Back to sign in', 'Back to sign in')).toBe(1)
    expect(overlap('Create your account', 'Hozza létre fiókját')).toBe(0)
    expect(overlap('Invalid email or password.', 'Érvénytelen e-mail-cím vagy jelszó.')).toBe(0)
  })
})

describe('Maltese field labels (W12, 2026-10-28)', () => {
  it('are Maltese, and consistent with each other', async () => {
    const mt = await load('mt')
    expect(lookup(mt, 'auth.ui.labels.email')).toBe('Indirizz tal-email')
    expect(lookup(mt, 'auth.ui.labels.password')).toBe('Kelma tas-sigriet')
    expect(lookup(mt, 'auth.ui.labels.password_confirmation')).toBe(
      'Ikkonferma l-kelma tas-sigriet',
    )
  })

  it('the loanword exemptions are exactly the two named, and the keys exist', async () => {
    expect(GENUINE_LOANWORD).toEqual({
      el: ['auth.ui.labels.email'],
      ro: ['auth.ui.labels.email'],
    })
    for (const [locale, keys] of Object.entries(GENUINE_LOANWORD)) {
      for (const key of keys)
        expect(lookup(await load(locale), key), `${locale} ${key}`).toBe('Email')
    }
  })
})

describe('a native-language mangle no detector can see', () => {
  it('Romanian "Confirm password" is the imperative "Confirmați parola", not "Confirmațiați"', async () => {
    const ro = await load('ro')
    expect(lookup(ro, 'auth.ui.labels.password_confirmation')).toBe('Confirmați parola')
  })
})

describe('the sign-up flow strings in the flaky ten', () => {
  it('names the ten, so the loop cannot silently run over fewer', () => {
    expect([...FLAKY_TEN]).toHaveLength(10)
  })

  it('has an explicit, duplicate-free key list', () => {
    expect(new Set(FLOW_KEYS).size).toBe(FLOW_KEYS.length)
    expect(FLOW_KEYS.length).toBeGreaterThanOrEqual(35)
    expect(FLOW_KEYS).toEqual(
      expect.arrayContaining([
        'auth.ui.labels.email',
        'auth.ui.labels.password',
        'auth.ui.labels.password_confirmation',
      ]),
    )
  })

  describe.each([...FLAKY_TEN])('%s', (locale) => {
    it('is not English, not an English hybrid, keeps placeholders and has no "-ing…" mangle', async () => {
      const en = await load('en')
      const bundle = await load(locale)
      const keys = [...FLOW_KEYS]
      const loanwords = GENUINE_LOANWORD[locale] ?? []
      const problems: string[] = []

      for (const key of keys) {
        const english = lookup(en, key)
        const value = lookup(bundle, key)
        if (typeof english !== 'string') {
          problems.push(`${key}: missing from en`)
          continue
        }
        if (typeof value !== 'string' || value.trim() === '') {
          problems.push(`${key}: missing or empty`)
          continue
        }
        if (value === english) {
          if (!loanwords.includes(key)) problems.push(`${key}: equals the English string`)
        } else if (overlap(english, value) >= 0.5)
          problems.push(`${key}: English hybrid -> ${value}`)
        if (placeholders(value).join() !== placeholders(english).join()) {
          problems.push(
            `${key}: placeholders differ (${placeholders(english).join()} vs ${placeholders(value).join()})`,
          )
        }
        if (key.startsWith('auth.ui.loading.') && /ing…?$/.test(value)) {
          problems.push(`${key}: "-ing" suffix mangle -> ${value}`)
        }
      }

      expect(problems).toEqual([])
    })
  })
})

/**
 * The keys AH-104d adds, in ALL 24 locales (not just the flaky ten): present,
 * non-empty, same placeholders as `en`, and in the 23 others never the English
 * string nor an English hybrid. The retired pointer key is covered in S4.
 */
const NEW_KEYS = [
  'auth.ui.native.sign_up_subtitle',
  'auth.ui.native.new_to_catalyst',
  'auth.ui.consent.line',
  'auth.ui.consent.privacy',
  'auth.ui.banners.email_verified_app',
  'auth.ui.actions.verified_sign_in',
  'auth.ui.errors.not_verified_yet',
  'auth.ui.actions.different_address',
] as const

describe('the AH-104d keys in every locale', () => {
  it('finds all 24 locale directories', async () => {
    const dirs = (await fs.readdir(LOCALE_ROOT, { withFileTypes: true })).filter((d) =>
      d.isDirectory(),
    )
    expect(dirs).toHaveLength(24)
  })

  it('has the consent sentence with exactly one {privacy} slot, in all 24', async () => {
    const dirs = (await fs.readdir(LOCALE_ROOT, { withFileTypes: true }))
      .filter((d) => d.isDirectory())
      .map((d) => d.name)
    for (const locale of dirs) {
      const line = lookup(await load(locale), 'auth.ui.consent.line')
      expect(typeof line, locale).toBe('string')
      expect((line as string).split('{privacy}').length - 1, locale).toBe(1)
      expect(line as string, locale).not.toContain('{terms}')
    }
  })

  it('has no Terms key: the Terms link joins when a page exists', async () => {
    expect(lookup(await load('en'), 'auth.ui.consent.terms')).toBeUndefined()
  })

  it.each([
    'bg',
    'cs',
    'da',
    'de',
    'el',
    'es',
    'et',
    'fi',
    'fr',
    'ga',
    'hr',
    'hu',
    'it',
    'lt',
    'lv',
    'mt',
    'nl',
    'pl',
    'pt',
    'ro',
    'sk',
    'sl',
    'sv',
  ])('%s: every new key is a real translation with the right placeholders', async (locale) => {
    const en = await load('en')
    const bundle = await load(locale)
    const problems: string[] = []
    for (const key of NEW_KEYS) {
      const english = lookup(en, key)
      const value = lookup(bundle, key)
      if (typeof english !== 'string') {
        problems.push(`${key}: missing from en`)
        continue
      }
      if (typeof value !== 'string' || value.trim() === '') {
        problems.push(`${key}: missing or empty`)
        continue
      }
      if (value === english) problems.push(`${key}: equals the English string`)
      else if (overlap(english, value) >= 0.5) problems.push(`${key}: English hybrid -> ${value}`)
      if (placeholders(value).join() !== placeholders(english).join()) {
        problems.push(`${key}: placeholders differ`)
      }
    }
    expect(problems).toEqual([])
  })
})

describe('retired keys (AH-104d S4)', () => {
  it('auth.ui.native.create_account (the "go to the website" pointer) is gone from every locale', async () => {
    const locales = (await fs.readdir(LOCALE_ROOT, { withFileTypes: true }))
      .filter((d) => d.isDirectory())
      .map((d) => d.name)
    expect(locales).toHaveLength(24)
    const stillThere: string[] = []
    for (const locale of locales) {
      const bundle = await load(locale)
      if (lookup(bundle, 'auth.ui.native.create_account') !== undefined) stillThere.push(locale)
    }
    expect(stillThere).toEqual([])
  })
})
