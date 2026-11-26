/**
 * PL-3.2a — i18n keys of the Scripts step (the rail, the editor, the brief, the hooks, autosave, the conflict box, the leave guard,
 * the archived dialog, the Remove and Replace choices, the errors) and the wave dialog's archived-scripts warning: presence,
 * placeholder integrity, no plural pipes (every string is count-neutral on purpose) and the "not an English copy" gate across all
 * 24 UI locales. Same shape as `creatorsKeys.spec.ts`.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

import { UI_LOCALES } from '@catalyst/api-client'

const LOCALE_ROOT = path.resolve(__dirname, 'locales')
const SOT = 'en'
const TARGETS = UI_LOCALES.filter((locale) => locale !== SOT)

const KEYS = [
  'app.strategy.scripts.stepper.none',
  'app.strategy.scripts.stepper.count',
  'app.strategy.scripts.rail.title',
  'app.strategy.scripts.rail.script',
  'app.strategy.scripts.rail.draft',
  'app.strategy.scripts.rail.add',
  'app.strategy.scripts.rail.limit',
  'app.strategy.scripts.rail.limitHint',
  'app.strategy.scripts.rail.footnote',
  'app.strategy.scripts.rail.archived',
  'app.strategy.scripts.editor.title',
  'app.strategy.scripts.editor.lastEdited',
  'app.strategy.scripts.editor.archive',
  'app.strategy.scripts.editor.archiveTitle',
  'app.strategy.scripts.editor.archiveConfirm',
  'app.strategy.scripts.editor.loadFailed',
  'app.strategy.scripts.save.saving',
  'app.strategy.scripts.save.saved',
  'app.strategy.scripts.save.failed',
  'app.strategy.scripts.save.refused',
  'app.strategy.scripts.save.retry',
  'app.strategy.scripts.save.gone',
  'app.strategy.scripts.save.allSaved',
  'app.strategy.scripts.save.stuck',
  'app.strategy.scripts.brief.title',
  'app.strategy.scripts.brief.editInStep3',
  'app.strategy.scripts.brief.persona',
  'app.strategy.scripts.brief.usps',
  'app.strategy.scripts.brief.priority',
  'app.strategy.scripts.brief.support',
  'app.strategy.scripts.brief.noUsps',
  'app.strategy.scripts.brief.noPersona',
  'app.strategy.scripts.brief.openStep5',
  'app.strategy.scripts.brief.angle',
  'app.strategy.scripts.brief.anglePlaceholder',
  'app.strategy.scripts.brief.angleFromPersona',
  'app.strategy.scripts.brief.angleFromMatrix',
  'app.strategy.scripts.brief.angleOwn',
  'app.strategy.scripts.brief.useLive',
  'app.strategy.scripts.brief.duration',
  'app.strategy.scripts.brief.seconds',
  'app.strategy.scripts.brief.estimate',
  'app.strategy.scripts.brief.noEstimate',
  'app.strategy.scripts.hooks.title',
  'app.strategy.scripts.hooks.hook',
  'app.strategy.scripts.hooks.menu',
  'app.strategy.scripts.hooks.collapse',
  'app.strategy.scripts.hooks.expand',
  'app.strategy.scripts.hooks.duplicate',
  'app.strategy.scripts.hooks.delete',
  'app.strategy.scripts.hooks.audio',
  'app.strategy.scripts.hooks.visual',
  'app.strategy.scripts.hooks.overlay',
  'app.strategy.scripts.hooks.optional',
  'app.strategy.scripts.hooks.audioPlaceholder',
  'app.strategy.scripts.hooks.visualPlaceholder',
  'app.strategy.scripts.hooks.overlayPlaceholder',
  'app.strategy.scripts.hooks.addOverlay',
  'app.strategy.scripts.hooks.noShot',
  'app.strategy.scripts.hooks.empty',
  'app.strategy.scripts.hooks.add',
  'app.strategy.scripts.hooks.limit',
  'app.strategy.scripts.hooks.deleteTitle',
  'app.strategy.scripts.hooks.deleteBody',
  'app.strategy.scripts.hooks.deleteConfirm',
  'app.strategy.scripts.shot.wide',
  'app.strategy.scripts.shot.medium',
  'app.strategy.scripts.shot.close_up',
  'app.strategy.scripts.shot.detail',
  'app.strategy.scripts.shot.pov',
  'app.strategy.scripts.shot.over_the_shoulder',
  'app.strategy.scripts.shot.top_down',
  'app.strategy.scripts.shot.screen_recording',
  'app.strategy.scripts.shot.reaction',
  'app.strategy.scripts.shot.creator_to_camera',
  'app.strategy.scripts.conflict.title',
  'app.strategy.scripts.conflict.hint',
  'app.strategy.scripts.conflict.theirs',
  'app.strategy.scripts.conflict.empty',
  'app.strategy.scripts.conflict.copy',
  'app.strategy.scripts.conflict.copied',
  'app.strategy.scripts.conflict.copyFailed',
  'app.strategy.scripts.conflict.useTheirs',
  'app.strategy.scripts.conflict.keepMine',
  'app.strategy.scripts.leave.title',
  'app.strategy.scripts.leave.body',
  'app.strategy.scripts.leave.stay',
  'app.strategy.scripts.leave.retry',
  'app.strategy.scripts.leave.leave',
  'app.strategy.scripts.archived.title',
  'app.strategy.scripts.archived.empty',
  'app.strategy.scripts.archived.row',
  'app.strategy.scripts.archived.on',
  'app.strategy.scripts.archived.restore',
  'app.strategy.scripts.archived.restoreFull',
  'app.strategy.scripts.archived.creatorGone',
  'app.strategy.scripts.archived.delete',
  'app.strategy.scripts.archived.deleteTitle',
  'app.strategy.scripts.archived.deleteConfirm',
  'app.strategy.scripts.replace.label',
  'app.strategy.scripts.replace.carry',
  'app.strategy.scripts.replace.archive',
  'app.strategy.scripts.remove.label',
  'app.strategy.scripts.remove.archive',
  'app.strategy.scripts.remove.delete',
  'app.strategy.scripts.empty.noCreators.title',
  'app.strategy.scripts.empty.noCreators.body',
  'app.strategy.scripts.empty.noCreators.action',
  'app.strategy.scripts.empty.noScripts.title',
  'app.strategy.scripts.empty.noScripts.body',
  'app.strategy.scripts.errors.limitReached',
  'app.strategy.scripts.errors.hooksLimit',
  'app.strategy.scripts.errors.lastHook',
  'app.strategy.scripts.errors.restoreLimit',
  'app.strategy.scripts.errors.restoreCreatorMissing',
  'app.strategy.scripts.errors.choiceRequired',
  'app.strategy.scripts.hooks.deleteBodyImages',
  'app.strategy.scripts.editor.archiveBodyFull',
  'app.strategy.scripts.archived.infoFull',
  'app.strategy.scripts.archived.counts',
  'app.strategy.scripts.archived.deleteBodyFull',
  'app.strategy.scripts.images.add',
  'app.strategy.scripts.images.drop',
  'app.strategy.scripts.images.failed',
  'app.strategy.scripts.images.full',
  'app.strategy.scripts.images.hint',
  'app.strategy.scripts.images.label',
  'app.strategy.scripts.images.open',
  'app.strategy.scripts.images.progress',
  'app.strategy.scripts.images.refusedBytes',
  'app.strategy.scripts.images.refusedCount',
  'app.strategy.scripts.images.refusedType',
  'app.strategy.scripts.images.remove',
  'app.strategy.scripts.images.removeBody',
  'app.strategy.scripts.images.removeConfirm',
  'app.strategy.scripts.images.removeTitle',
  'app.strategy.scripts.images.retry',
  'app.strategy.scripts.images.usage',
  'app.strategy.scripts.lang.add',
  'app.strategy.scripts.lang.alwaysOn',
  'app.strategy.scripts.lang.barLabel',
  'app.strategy.scripts.lang.dialogBody',
  'app.strategy.scripts.lang.dialogTitle',
  'app.strategy.scripts.lang.estimateFallback',
  'app.strategy.scripts.lang.full',
  'app.strategy.scripts.lang.keepNote',
  'app.strategy.scripts.lang.manage',
  'app.strategy.scripts.lang.remove',
  'app.strategy.scripts.lang.removeShort',
  'app.strategy.scripts.lang.removing',
  'app.strategy.scripts.lang.sideBySide',
  'app.strategy.scripts.lang.unsaved',
  'app.strategy.scripts.lang.used',
  'app.strategy.scripts.lang.view',
  'app.strategy.scripts.lang.with',
  'app.strategy.scripts.lines.add',
  'app.strategy.scripts.lines.addShot',
  'app.strategy.scripts.lines.broll',
  'app.strategy.scripts.lines.collapse',
  'app.strategy.scripts.lines.cta',
  'app.strategy.scripts.lines.ctaLabel',
  'app.strategy.scripts.lines.delete',
  'app.strategy.scripts.lines.deleteBody',
  'app.strategy.scripts.lines.deleteConfirm',
  'app.strategy.scripts.lines.deleteTitle',
  'app.strategy.scripts.lines.duplicate',
  'app.strategy.scripts.lines.empty',
  'app.strategy.scripts.lines.expand',
  'app.strategy.scripts.lines.goneHint',
  'app.strategy.scripts.lines.goneTitle',
  'app.strategy.scripts.lines.imagesCount',
  'app.strategy.scripts.lines.limit',
  'app.strategy.scripts.lines.line',
  'app.strategy.scripts.lines.lineCta',
  'app.strategy.scripts.lines.menu',
  'app.strategy.scripts.lines.noLines',
  'app.strategy.scripts.lines.noShots',
  'app.strategy.scripts.lines.script',
  'app.strategy.scripts.lines.scriptPlaceholder',
  'app.strategy.scripts.lines.shotDelete',
  'app.strategy.scripts.lines.shotDescription',
  'app.strategy.scripts.lines.shotDown',
  'app.strategy.scripts.lines.shotLabel',
  'app.strategy.scripts.lines.shotLimit',
  'app.strategy.scripts.lines.shotPlaceholder',
  'app.strategy.scripts.lines.shotType',
  'app.strategy.scripts.lines.shotUp',
  'app.strategy.scripts.lines.shotsCount',
  'app.strategy.scripts.lines.shotsUsage',
  'app.strategy.scripts.lines.title',
  'app.strategy.scripts.lines.usage',
  'app.strategy.scripts.order.changedElsewhere',
  'app.strategy.scripts.order.down',
  'app.strategy.scripts.order.grip',
  'app.strategy.scripts.order.gripHint',
  'app.strategy.scripts.order.moved',
  'app.strategy.scripts.order.up',
  'app.strategy.scripts.errors.linesLimit',
  'app.strategy.scripts.errors.brollLimit',
  'app.strategy.scripts.errors.languageNotInMarket',
  'app.strategy.scripts.errors.languageLimit',
  'app.strategy.scripts.errors.imageFailed',
  'app.strategy.scripts.errors.imageCopyFailed',
  'app.strategy.scripts.errors.imageDeleteFailed',
  'app.strategy.scripts.errors.imageLimitCount',
  'app.strategy.scripts.errors.imageLimitBytes',
  'app.waves.dialog.scriptsWarning',
  'app.waves.dialog.scriptsConfirm',
]

/** `locale:key` pairs that are the same word as English on purpose (a loan word such as Persona, Overlay, Hook, POV, or a pattern of names and numbers). */
const SAME_AS_ENGLISH_BY_DESIGN = new Set<string>([
  'nl:app.strategy.scripts.lines.shotLabel',
  'nl:app.strategy.scripts.lines.shotsCount',
  'mt:app.strategy.scripts.lines.shotLabel',
  'mt:app.strategy.scripts.lines.shotsCount',
  'da:app.strategy.scripts.hooks.title',
  'de:app.strategy.scripts.hooks.title',
  'el:app.strategy.scripts.hooks.title',
  'mt:app.strategy.scripts.hooks.title',
  'nl:app.strategy.scripts.hooks.title',
  'sv:app.strategy.scripts.hooks.title',
  'cs:app.strategy.scripts.brief.title',
  'cs:app.strategy.scripts.brief.persona',
  'cs:app.strategy.scripts.hooks.hook',
  'cs:app.strategy.scripts.shot.detail',
  'da:app.strategy.scripts.stepper.count',
  'da:app.strategy.scripts.rail.title',
  'da:app.strategy.scripts.rail.script',
  'da:app.strategy.scripts.editor.title',
  'da:app.strategy.scripts.brief.title',
  'da:app.strategy.scripts.brief.persona',
  'da:app.strategy.scripts.hooks.hook',
  'da:app.strategy.scripts.hooks.overlay',
  'da:app.strategy.scripts.shot.close_up',
  'da:app.strategy.scripts.shot.pov',
  'da:app.strategy.scripts.archived.row',
  'de:app.strategy.scripts.brief.persona',
  'de:app.strategy.scripts.brief.usps',
  'de:app.strategy.scripts.hooks.hook',
  'de:app.strategy.scripts.hooks.overlay',
  'de:app.strategy.scripts.hooks.optional',
  'de:app.strategy.scripts.shot.detail',
  'de:app.strategy.scripts.shot.pov',
  'el:app.strategy.scripts.brief.title',
  'el:app.strategy.scripts.brief.persona',
  'el:app.strategy.scripts.hooks.hook',
  'es:app.strategy.scripts.brief.title',
  'es:app.strategy.scripts.brief.persona',
  'et:app.strategy.scripts.brief.title',
  'et:app.strategy.scripts.brief.persona',
  'et:app.strategy.scripts.shot.detail',
  'fi:app.strategy.scripts.brief.title',
  'fr:app.strategy.scripts.rail.title',
  'fr:app.strategy.scripts.rail.script',
  'fr:app.strategy.scripts.editor.title',
  'fr:app.strategy.scripts.brief.title',
  'fr:app.strategy.scripts.brief.persona',
  'fr:app.strategy.scripts.brief.angle',
  'fr:app.strategy.scripts.archived.row',
  'ga:app.strategy.scripts.rail.script',
  'ga:app.strategy.scripts.editor.title',
  'ga:app.strategy.scripts.archived.row',
  'hr:app.strategy.scripts.brief.title',
  'hr:app.strategy.scripts.brief.persona',
  'hr:app.strategy.scripts.hooks.hook',
  'hr:app.strategy.scripts.hooks.audio',
  'hu:app.strategy.scripts.brief.title',
  'hu:app.strategy.scripts.brief.persona',
  'it:app.strategy.scripts.rail.script',
  'it:app.strategy.scripts.editor.title',
  'it:app.strategy.scripts.brief.title',
  'it:app.strategy.scripts.brief.persona',
  'it:app.strategy.scripts.hooks.hook',
  'it:app.strategy.scripts.hooks.overlay',
  'it:app.strategy.scripts.archived.row',
  'lt:app.strategy.scripts.brief.persona',
  'lv:app.strategy.scripts.brief.persona',
  'mt:app.strategy.scripts.brief.title',
  'mt:app.strategy.scripts.brief.persona',
  'mt:app.strategy.scripts.brief.usps',
  'mt:app.strategy.scripts.hooks.hook',
  'mt:app.strategy.scripts.hooks.overlay',
  'mt:app.strategy.scripts.shot.close_up',
  'nl:app.strategy.scripts.stepper.count',
  'nl:app.strategy.scripts.rail.title',
  'nl:app.strategy.scripts.rail.script',
  'nl:app.strategy.scripts.editor.title',
  'nl:app.strategy.scripts.brief.persona',
  'nl:app.strategy.scripts.hooks.hook',
  'nl:app.strategy.scripts.hooks.overlay',
  'nl:app.strategy.scripts.shot.close_up',
  'nl:app.strategy.scripts.shot.detail',
  'nl:app.strategy.scripts.shot.pov',
  'nl:app.strategy.scripts.archived.row',
  'pl:app.strategy.scripts.brief.title',
  'pl:app.strategy.scripts.brief.persona',
  'pl:app.strategy.scripts.hooks.hook',
  'pt:app.strategy.scripts.brief.persona',
  'ro:app.strategy.scripts.brief.title',
  'ro:app.strategy.scripts.brief.persona',
  'ro:app.strategy.scripts.hooks.hook',
  'sk:app.strategy.scripts.brief.title',
  'sk:app.strategy.scripts.hooks.hook',
  'sk:app.strategy.scripts.shot.detail',
  'sl:app.strategy.scripts.brief.persona',
  'sv:app.strategy.scripts.brief.title',
  'sv:app.strategy.scripts.brief.persona',
  'sv:app.strategy.scripts.hooks.hook',
  'sv:app.strategy.scripts.hooks.overlay',
  'sv:app.strategy.scripts.shot.pov',
])

/** Keys that are the same loan word in every language on purpose ("B-roll", "CTA"). */
const LOAN_KEYS = new Set<string>([
  'app.strategy.scripts.lines.broll',
  'app.strategy.scripts.lines.cta',
])

type Bundle = Record<string, unknown>

function load(locale: string): Bundle {
  return JSON.parse(readFileSync(path.join(LOCALE_ROOT, locale, 'app.json'), 'utf8')) as Bundle
}

function valueAt(bundle: Bundle, dotted: string): unknown {
  return dotted.split('.').reduce<unknown>((node, part) => {
    if (typeof node === 'object' && node !== null) {
      return (node as Record<string, unknown>)[part]
    }
    return undefined
  }, bundle)
}

function tokens(text: string): string[] {
  return [
    ...new Set([...text.matchAll(/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/g)].map((m) => m[1] ?? '')),
  ].sort()
}

describe('PL-3.2a, PL-3.2b scripts strings', () => {
  it('checks 208 keys across 24 locales', () => {
    expect(KEYS).toHaveLength(208)
    expect(UI_LOCALES).toHaveLength(24)
  })

  it('every locale has every key as a non-empty string', () => {
    const missing: string[] = []
    for (const locale of UI_LOCALES) {
      const bundle = load(locale)
      for (const key of KEYS) {
        const value = valueAt(bundle, key)
        if (typeof value !== 'string' || value.trim() === '') missing.push(`${locale}:${key}`)
      }
    }
    expect(missing).toEqual([])
  })

  it('placeholder sets match en for every key', () => {
    const en = load(SOT)
    const offenders: string[] = []
    for (const locale of TARGETS) {
      const bundle = load(locale)
      for (const key of KEYS) {
        const expected = tokens(valueAt(en, key) as string)
        const actual = tokens(valueAt(bundle, key) as string)
        if (JSON.stringify(expected) !== JSON.stringify(actual)) offenders.push(`${locale}:${key}`)
      }
    }
    expect(offenders).toEqual([])
  })

  it('every string is count-neutral (no plural pipes)', () => {
    const offenders: string[] = []
    for (const locale of UI_LOCALES) {
      const bundle = load(locale)
      for (const key of KEYS) {
        if ((valueAt(bundle, key) as string).includes('|')) offenders.push(`${locale}:${key}`)
      }
    }
    expect(offenders).toEqual([])
  })

  it('no non-English locale ships an English copy', () => {
    const en = load(SOT)
    const offenders: string[] = []
    for (const locale of TARGETS) {
      const bundle = load(locale)
      for (const key of KEYS) {
        if (LOAN_KEYS.has(key) || SAME_AS_ENGLISH_BY_DESIGN.has(`${locale}:${key}`)) continue
        if (valueAt(bundle, key) === valueAt(en, key)) offenders.push(`${locale}:${key}`)
      }
    }
    expect(offenders).toEqual([])
  })
})
