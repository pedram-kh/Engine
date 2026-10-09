/**
 * AH-100d — the two detail pages Figma does not draw (Invitation / assignment
 * detail and Job post detail) carry a token-derived native treatment.
 *
 * Three things here can regress without a single component test noticing,
 * because jsdom lays out nothing:
 *
 *  1. TYPE. Vuetify's typography utilities (`.text-h4` on the title,
 *     `.text-subtitle-1` on section titles, `.text-body-2` on prose,
 *     `.text-medium-emphasis` on the meta line) put `!important` on font-size,
 *     letter-spacing and colour. A native type rule without `!important` loses
 *     to them silently and the page keeps Vuetify's 34px title. The cause is
 *     asserted live, so this pin dies if Vuetify stops doing it (and the
 *     `!important`s can then go).
 *  2. BANNERS AND DRAFT ROUNDS. A round card is `<v-sheet color="success">`,
 *     i.e. `.bg-success { background: … !important }`: a solid green/pink block.
 *     The tinted treatment only wins with `!important`.
 *  3. COVERAGE. Every project class either page renders must be in the creator
 *     sheet (or be listed below with a reason), so a class added later does not
 *     quietly ship unstyled in the app.
 */

import { readFileSync, existsSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const SRC = path.resolve(__dirname, '../../../src')
const SHEET = path.join(SRC, 'modules/creators/layouts/creatorNative.css')

interface Rule {
  selector: string
  decls: Map<string, string>
}

function parse(file: string): Rule[] {
  const css = readFileSync(file, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')
  const rules: Rule[] = []
  for (const match of css.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
    const decls = new Map<string, string>()
    for (const decl of (match[2] as string).split(';')) {
      const idx = decl.indexOf(':')
      if (idx > 0) decls.set(decl.slice(0, idx).trim(), decl.slice(idx + 1).trim())
    }
    for (const selector of (match[1] as string).split(',')) {
      rules.push({ selector: selector.trim().replace(/\s+/g, ' '), decls })
    }
  }
  return rules
}

const RULES = parse(SHEET)

function rule(selectorPart: string): Rule {
  const found = RULES.find((r) => r.selector.includes(selectorPart))
  if (found === undefined) throw new Error(`no native rule for ${selectorPart}`)
  return found
}

function vuetifyCss(): string {
  const entry = require.resolve('vuetify/lib/styles/main.css')
  return readFileSync(entry, 'utf8')
}

describe('detail pages: type beats Vuetify utilities', () => {
  it('the cause is live: the utilities are !important on size and letter-spacing', () => {
    const css = vuetifyCss()
    for (const cls of ['text-h4', 'text-subtitle-1', 'text-body-2']) {
      const block = new RegExp(`\\.${cls}\\s*\\{([^}]*)\\}`).exec(css)?.[1] ?? ''
      expect(block, cls).toMatch(/font-size:[^;]*!important/)
      expect(block, cls).toMatch(/letter-spacing:[^;]*!important/)
    }
    expect(css).toMatch(/\.text-medium-emphasis\s*\{[^}]*color:[^;]*!important/)
  })

  it.each([
    ['title', "[data-testid='creator-job-detail-name']", '--native-type-display'],
    [
      'assignment title',
      "[data-testid='creator-assignment-detail'] > header h1",
      '--native-type-display',
    ],
    ['section title', '.job-detail__section h2', '--native-type-h4'],
    ['card title', "[data-testid='creator-assignment-detail'] .v-card-title", '--native-type-h4'],
    ['prose', '.job-detail__description', '--native-type-body-small'],
    ['offer note', '.assignment-offer-description', '--native-type-body-small'],
  ])('%s: token font, !important, letter-spacing reset', (_name, selector, token) => {
    const r = rule(selector)
    expect(r.decls.get('font')).toBe(`var(${token}) !important`)
    expect(r.decls.get('letter-spacing')).toBe('0 !important')
  })

  it('the meta line beats .text-medium-emphasis on colour', () => {
    const r = rule('> header + p')
    expect(r.decls.get('color')).toBe('var(--native-text-secondary) !important')
  })
})

describe('detail pages: banners, draft rounds, actions', () => {
  it.each([
    ['success', 'success'],
    ['warning', 'warning'],
    ['error', 'danger'],
  ])('a %s alert is the tinted banner (subtle fill, 1px status border)', (tone, token) => {
    const r = rule(`.v-alert--variant-tonal.text-${tone}`)
    expect(r.decls.get('background')).toBe(`var(--native-status-${token}-subtle)`)
    expect(r.decls.get('border')).toBe(`1px solid var(--native-status-${token})`)
  })

  it('a draft round card is NOT a solid colour block: fills are subtle and !important', () => {
    expect(rule('.draft-round-card').decls.get('background')).toMatch(/!important$/)
    for (const [tone, token] of [
      ['success', 'success'],
      ['warning', 'warning'],
      ['error', 'danger'],
      ['info', 'info'],
    ]) {
      const r = rule(`.draft-round-card.bg-${tone}`)
      expect(r.decls.get('background')).toBe(`var(--native-status-${token}-subtle) !important`)
    }
    expect(rule('.draft-round-card').decls.get('color')).toMatch(/!important$/)
  })

  it('the invitation answer buttons stack on full 44px rows (Accept above Decline)', () => {
    const row = rule('.invitation-actions)')
    expect(row.decls.get('display')).toBe('grid')
    expect(row.decls.get('grid-template-columns')).toBe('minmax(0, 1fr)')
    expect(rule('.invitation-actions .v-btn').decls.get('min-height')).toBe('44px')
    expect(rule('.invitation-actions .v-btn').decls.get('min-width')).toBe('0')
  })

  it('a form card’s submit is the primary on its own full 44px row', () => {
    const r = rule('.v-card-actions > .v-btn')
    expect(r.decls.get('flex')).toBe('1 1 100%')
    expect(r.decls.get('min-height')).toBe('44px')
  })

  it('the status pill is info-tinted when invited and neutral otherwise', () => {
    expect(rule("[data-testid='assignment-detail-status']").decls.get('border-radius')).toBe(
      'var(--native-radius-full)',
    )
    expect(
      rule("[data-testid='assignment-detail-status'][data-status='invited']").decls.get('color'),
    ).toBe('var(--native-status-info)')
  })
})

/* ── coverage ─────────────────────────────────────────────────────────────── */

const PAGES = [
  'modules/creators/pages/CreatorAssignmentDetailPage.vue',
  'modules/creators/pages/CreatorJobDetailPage.vue',
  // Rendered inside them:
  'modules/creators/components/CreatorAttachmentsBlock.vue',
  'modules/messaging/components/ChatPanel.vue',
]

/** Rendered, but with nothing visual of its own to style. Reason per entry. */
const NO_VISUAL: Record<string, string> = {
  'creator-assignment-detail': 'page root; the data-testid selectors carry its layout',
  'job-detail': 'page root; the data-testid selectors carry its layout',
  'job-detail__brand': 'styled by its data-testid (creator-job-detail-brand)',
  'job-detail__card': 'styled by its data-testid (the card is a v-card variant)',
  'chat-panel': 'structural wrapper; the feed, thread and composer inside are styled',
  'chat-panel__header': 'rendered only when a title is passed; the detail page passes none',
  'chat-panel__feed': 'scroll container; the thread inside is styled',
  'chat-panel__messages': 'structural list; the bubbles in it are styled',
  'chat-panel__load-earlier': 'a centred text button, correct as is',
  'chat-panel__pending': 'composer pending-files list, ordinary text',
  'chat-panel__error': 'composer error line, danger text',
  'chat-panel__closed': 'wrapper around a banner',
  'chat-panel__compose': 'form wrapper; the row inside is styled by the chat panel itself',
  'chat-panel__compose-row': 'flex row owned by the component',
  'chat-panel__attach-btn': 'icon button, native button rules apply',
  'chat-panel__attach-btn--active': 'the rotate-45 state of the same button',
  'chat-panel__input': 'a v-textarea; the native field rules apply',
  'chat-panel__attach-menu': 'flex row of icon buttons',
  'chat-panel__file-input': 'hidden file input',
  'chat-message': 'row wrapper; alignment is its own',
  'chat-message--system': 'alignment variant; the system line is styled',
  'chat-message__attachments': 'attachment list inside a bubble, ordinary text',
  'draft-round-feedback': 'pre-wrap text only; colour inherited from the round card',
  'assignment-revision-feedback': 'pre-wrap text only; colour inherited from the banner',
}

function resolveTemplate(file: string): string {
  const source = readFileSync(path.join(SRC, file), 'utf8')
  return /<template>([\s\S]*)<\/template>/.exec(source)?.[1] ?? ''
}

const FOREIGN =
  /^(v-|mdi-|d-|text-|bg-|ma?[trblxy]?-|pa?[trblxy]?-|ga?p?-|flex-|align-|justify-|font-)/

function renderedClasses(template: string): Set<string> {
  const out = new Set<string>()
  for (const m of template.matchAll(/(?<![:@\w-])class="([^"]*)"/g)) {
    for (const c of (m[1] as string).split(/\s+/)) if (c) out.add(c)
  }
  for (const m of template.matchAll(/:class="([^"]*)"/g)) {
    for (const q of (m[1] as string).matchAll(/'([a-z][a-z0-9_-]*)'/g)) out.add(q[1] as string)
  }
  // `'system'` is a string compared against in a :class expression, not a class.
  return new Set([...out].filter((c) => !FOREIGN.test(c) && c !== 'system'))
}

const css = readFileSync(SHEET, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')
const styled = new Set([...css.matchAll(/\.([a-z][a-z0-9_-]*)/g)].map((m) => m[1] as string))

const rendered = new Set<string>()
for (const page of PAGES) {
  expect(existsSync(path.join(SRC, page))).toBe(true)
  for (const c of renderedClasses(resolveTemplate(page))) rendered.add(c)
}

describe('detail pages: native coverage', () => {
  it('walks the real templates (the pin is not vacuous)', () => {
    expect(rendered.has('job-detail__meta')).toBe(true)
    expect(rendered.has('assignment-detail__section')).toBe(true)
    expect(rendered.has('chat-message__bubble')).toBe(true)
  })

  it('styles every project class the pages render (or explains why not)', () => {
    const bare = [...rendered].filter((c) => !styled.has(c) && !(c in NO_VISUAL)).sort()
    expect(bare).toEqual([])
  })

  it('keeps the no-visual list honest: each entry is rendered and has no rule of its own', () => {
    const stale = Object.keys(NO_VISUAL).filter((c) => !rendered.has(c) || styled.has(c))
    expect(stale).toEqual([])
  })
})
