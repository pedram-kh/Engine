/**
 * PL-1.1, PL-1.2 and PL-1.3 — i18n keys for waves, brand client files, campaigns for a wave, and the wave tracker: presence, placeholder integrity and the
 * "not an English copy" gate across all 24 UI locales (the "flaky ten" bg, el, et, fi, ga, hu, lt,
 * lv, mt, ro included). Same shape as `messagesV2Keys.spec.ts`.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

import { UI_LOCALES } from '@catalyst/api-client'

const LOCALE_ROOT = path.resolve(__dirname, 'locales')
const SOT = 'en'
const TARGETS = UI_LOCALES.filter((locale) => locale !== SOT)

const KEYS = [
  'app.waves.status.draft',
  'app.waves.panel.title',
  'app.waves.panel.subtitle',
  'app.waves.panel.newWave',
  'app.waves.panel.count',
  'app.waves.table.wave',
  'app.waves.table.markets',
  'app.waves.table.progress',
  'app.waves.table.updated',
  'app.waves.table.goLive',
  'app.waves.table.notStarted',
  'app.waves.empty.heading',
  'app.waves.empty.body',
  'app.waves.errors.loadFailed',
  'app.waves.errors.campaignNotAvailable',
  'app.waves.errors.campaignAlreadyLinked',
  'app.waves.errors.campaignLinkLocked',
  'app.waves.errors.brandArchived',
  'app.waves.errors.saveFailed',
  'app.waves.dialog.createTitle',
  'app.waves.dialog.editTitle',
  'app.waves.dialog.name',
  'app.waves.dialog.goLive',
  'app.waves.dialog.markets',
  'app.waves.dialog.addMarket',
  'app.waves.dialog.searchMarket',
  'app.waves.dialog.noMarketMatch',
  'app.waves.dialog.removeMarket',
  'app.waves.dialog.campaign',
  'app.waves.dialog.noCampaign',
  'app.waves.dialog.campaignHint',
  'app.waves.dialog.campaignLocked',
  'app.waves.dialog.create',
  'app.waves.dialog.save',
  'app.waves.validation.nameRequired',
  'app.waves.validation.goLiveRequired',
  'app.waves.validation.marketsRequired',
  'app.waves.validation.marketsMax',
  'app.waves.detail.title',
  'app.waves.detail.brand',
  'app.waves.detail.campaign',
  'app.waves.detail.noCampaign',
  'app.waves.detail.createdBy',
  'app.waves.detail.notFound',
  'app.campaigns.board.applications.titleWave',
  'app.campaigns.board.wave.moveRefused',
  'app.campaigns.board.wave.stage.approved',
  'app.campaigns.board.wave.stage.client_review',
  'app.campaigns.board.wave.stage.dropped',
  'app.campaigns.board.wave.stage.filming',
  'app.campaigns.board.wave.stage.in_edits',
  'app.campaigns.board.wave.stage.invited',
  'app.campaigns.board.wave.stage.selected',
  'app.campaigns.board.wave.stage.submitted',
  'app.campaigns.errors.waveBudgetRequired',
  'app.campaigns.errors.waveTypeDisabled',
  'app.campaigns.type.forWave',
  'app.campaigns.type.forWaveHelp',
  'app.campaigns.type.label',
  'app.campaigns.type.normal',
  'app.campaigns.type.normalHelp',
  'app.campaigns.waveCard.change',
  'app.campaigns.waveCard.failed',
  'app.campaigns.waveCard.link',
  'app.campaigns.waveCard.locked',
  'app.campaigns.waveCard.noOptions',
  'app.campaigns.waveCard.none',
  'app.campaigns.waveCard.pick',
  'app.campaigns.waveCard.unlink',
  'app.waves.detail.linkLocked',
  'app.waves.dialog.choice.existing',
  'app.waves.dialog.choice.new',
  'app.waves.dialog.choice.none',
  'app.waves.dialog.newCampaignHint',
  'app.waves.dialog.newCampaignName',
  'app.waves.errors.waveNotAvailable',
  'app.waves.validation.newCampaignNameRequired',
  'app.brandFiles.title',
  'app.brandFiles.subtitle',
  'app.brandFiles.upload',
  'app.brandFiles.type',
  'app.brandFiles.types.brief',
  'app.brandFiles.types.research',
  'app.brandFiles.types.guidelines',
  'app.brandFiles.types.assets',
  'app.brandFiles.types.other',
  'app.brandFiles.dropZone',
  'app.brandFiles.empty',
  'app.brandFiles.actions',
  'app.brandFiles.uploadDialog.title',
  'app.brandFiles.uploadDialog.body',
  'app.brandFiles.uploadDialog.typeLabel',
  'app.brandFiles.uploadDialog.choose',
  'app.brandFiles.uploadDialog.done',
  'app.brandFiles.uploadDialog.uploading',
  'app.brandFiles.uploadDialog.success',
  'app.brandFiles.errors.tooLarge',
  'app.brandFiles.errors.unsupported',
  'app.brandFiles.errors.uploadFailed',
  'app.brandFiles.errors.loadFailed',
  'app.brandFiles.errors.deleteFailed',
  'app.brandFiles.errors.downloadFailed',
  'app.brandFiles.errors.brandArchived',
  'app.waves.tracker.progress.complete',
  'app.waves.tracker.health.onTrack',
  'app.waves.tracker.health.atRisk',
  'app.waves.tracker.streams.ugc_video',
  'app.waves.tracker.streams.statics',
  'app.waves.tracker.streams.stills',
  'app.waves.tracker.streamStatus.on_track',
  'app.waves.tracker.streamStatus.delayed',
  'app.waves.tracker.streamStatus.complete',
  'app.waves.tracker.card.steps',
  'app.waves.tracker.card.nextStep',
  'app.waves.tracker.card.finalDelivery',
  'app.waves.tracker.card.noDate',
  'app.waves.tracker.card.allDone',
  'app.waves.tracker.card.noSteps',
  'app.waves.tracker.filter.all',
  'app.waves.tracker.filter.ugc',
  'app.waves.tracker.filter.statics',
  'app.waves.tracker.filter.stills',
  'app.waves.tracker.filter.mine',
  'app.waves.tracker.col.step',
  'app.waves.tracker.col.owner',
  'app.waves.tracker.col.due',
  'app.waves.tracker.col.status',
  'app.waves.tracker.status.up_next',
  'app.waves.tracker.status.in_progress',
  'app.waves.tracker.status.due_today',
  'app.waves.tracker.status.delayed',
  'app.waves.tracker.status.done',
  'app.waves.tracker.stepStatus.not_started',
  'app.waves.tracker.stepStatus.in_progress',
  'app.waves.tracker.stepStatus.done',
  'app.waves.tracker.steps.title',
  'app.waves.tracker.group.done',
  'app.waves.tracker.completed.count',
  'app.waves.tracker.completed.show',
  'app.waves.tracker.completed.hide',
  'app.waves.tracker.due.today',
  'app.waves.tracker.due.tomorrow',
  'app.waves.tracker.owner.unassigned',
  'app.waves.tracker.owner.former',
  'app.waves.tracker.owner.client',
  'app.waves.tracker.actions.addStep',
  'app.waves.tracker.actions.addStream',
  'app.waves.tracker.actions.edit',
  'app.waves.tracker.actions.remove',
  'app.waves.tracker.actions.removeStream',
  'app.waves.tracker.actions.streamMenu',
  'app.waves.tracker.actions.markDone',
  'app.waves.tracker.actions.reopen',
  'app.waves.tracker.actions.rowMenu',
  'app.waves.tracker.dialog.addTitle',
  'app.waves.tracker.dialog.editTitle',
  'app.waves.tracker.dialog.name',
  'app.waves.tracker.dialog.stream',
  'app.waves.tracker.dialog.owner',
  'app.waves.tracker.dialog.status',
  'app.waves.tracker.dialog.startsOn',
  'app.waves.tracker.dialog.dueOn',
  'app.waves.tracker.dialog.add',
  'app.waves.tracker.dialog.save',
  'app.waves.tracker.dialog.cancel',
  'app.waves.tracker.remove.title',
  'app.waves.tracker.remove.body',
  'app.waves.tracker.remove.confirm',
  'app.waves.tracker.removeStream.title',
  'app.waves.tracker.removeStream.body',
  'app.waves.tracker.removeStream.confirm',
  'app.waves.tracker.empty.filtered',
  'app.waves.tracker.removedByOther',
  'app.waves.tracker.validation.nameRequired',
  'app.waves.tracker.errors.loadFailed',
  'app.waves.tracker.errors.retry',
  'app.waves.tracker.errors.saveFailed',
  'app.waves.tracker.errors.forbidden',
  'app.waves.tracker.errors.ownerNotMember',
  'app.waves.tracker.errors.ownerConflict',
  'app.waves.tracker.errors.strategyLocked',
  'app.waves.tracker.errors.dates_invalid',
  'app.waves.tracker.errors.streamNotRemovable',
  'app.waves.tracker.errors.brandArchived',
] as const

/**
 * (locale:key) pairs that are legitimately identical to English: loanwords and a word that is the same
 * in that language.
 */
const SAME_AS_ENGLISH_BY_DESIGN = new Set<string>([
  // "Brief" is the working word for a creative brief in these languages.
  'el:app.brandFiles.types.brief',
  'hu:app.brandFiles.types.brief',
  'it:app.brandFiles.types.brief',
  'pl:app.brandFiles.types.brief',
  'ro:app.brandFiles.types.brief',
  'sv:app.brandFiles.types.brief',
  // "Brand" is the product's own loanword in Greek and Romanian (the glossary keeps it).
  'el:app.waves.detail.brand',
  'ro:app.waves.detail.brand',
  // "Type" is the same word in French.
  'fr:app.brandFiles.type',
  // PL-1.3: UGC, Statics, Stills, Status, Client and "UGC video" are the same loanword in these languages.
  'de:app.waves.tracker.filter.ugc',
  'de:app.waves.tracker.col.status',
  'de:app.waves.tracker.dialog.status',
  'fr:app.waves.tracker.filter.ugc',
  'fr:app.waves.tracker.owner.client',
  'es:app.waves.tracker.filter.ugc',
  'it:app.waves.tracker.filter.ugc',
  'pt:app.waves.tracker.filter.ugc',
  'nl:app.waves.tracker.streams.statics',
  'nl:app.waves.tracker.streams.stills',
  'nl:app.waves.tracker.filter.ugc',
  'nl:app.waves.tracker.filter.statics',
  'nl:app.waves.tracker.filter.stills',
  'nl:app.waves.tracker.col.status',
  'nl:app.waves.tracker.dialog.status',
  'da:app.waves.tracker.streams.stills',
  'da:app.waves.tracker.filter.ugc',
  'da:app.waves.tracker.filter.stills',
  'da:app.waves.tracker.filter.mine',
  'da:app.waves.tracker.col.status',
  'da:app.waves.tracker.dialog.status',
  'sv:app.waves.tracker.filter.ugc',
  'sv:app.waves.tracker.col.status',
  'sv:app.waves.tracker.dialog.status',
  'fi:app.waves.tracker.filter.ugc',
  'et:app.waves.tracker.filter.ugc',
  'lv:app.waves.tracker.streams.ugc_video',
  'lv:app.waves.tracker.filter.ugc',
  'lt:app.waves.tracker.filter.ugc',
  'pl:app.waves.tracker.filter.ugc',
  'pl:app.waves.tracker.col.status',
  'pl:app.waves.tracker.dialog.status',
  'cs:app.waves.tracker.streams.ugc_video',
  'cs:app.waves.tracker.filter.ugc',
  'sk:app.waves.tracker.streams.ugc_video',
  'sk:app.waves.tracker.filter.ugc',
  'sl:app.waves.tracker.streams.ugc_video',
  'sl:app.waves.tracker.filter.ugc',
  'hr:app.waves.tracker.streams.ugc_video',
  'hr:app.waves.tracker.filter.ugc',
  'hr:app.waves.tracker.col.status',
  'hr:app.waves.tracker.dialog.status',
  'ro:app.waves.tracker.filter.ugc',
  'ro:app.waves.tracker.owner.client',
  'hu:app.waves.tracker.filter.ugc',
  'bg:app.waves.tracker.filter.ugc',
  'el:app.waves.tracker.filter.ugc',
  'mt:app.waves.tracker.streams.stills',
  'mt:app.waves.tracker.filter.ugc',
  'mt:app.waves.tracker.filter.stills',
  'ga:app.waves.tracker.filter.ugc',
])

const NAMED_TOKEN = /\{([a-zA-Z_][a-zA-Z0-9_]*)\}/g

function load(locale: string): unknown {
  return JSON.parse(readFileSync(path.join(LOCALE_ROOT, locale, 'app.json'), 'utf8')) as unknown
}

function valueAt(bundle: unknown, key: string): unknown {
  let node: unknown = bundle
  for (const part of key.split('.')) {
    if (typeof node !== 'object' || node === null) {
      return undefined
    }
    node = (node as Record<string, unknown>)[part]
  }
  return node
}

function tokens(value: string): string[] {
  return [...new Set([...value.matchAll(NAMED_TOKEN)].map((m) => m[1] as string))].sort()
}

describe('PL-1.1 wave and client-file i18n keys', () => {
  it('checks 184 keys across 24 locales', () => {
    expect(KEYS).toHaveLength(184)
    expect(UI_LOCALES).toHaveLength(24)
  })

  it('every locale has every key as a non-empty string', () => {
    const missing: string[] = []
    for (const locale of UI_LOCALES) {
      const bundle = load(locale)
      for (const key of KEYS) {
        const value = valueAt(bundle, key)
        if (typeof value !== 'string' || value.trim() === '') {
          missing.push(`${locale}:${key}`)
        }
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
        if (JSON.stringify(expected) !== JSON.stringify(actual)) {
          offenders.push(`${locale}:${key}`)
        }
      }
    }
    expect(offenders).toEqual([])
  })

  it('the wave count is two plural forms, each with {count}', () => {
    for (const locale of UI_LOCALES) {
      const forms = (valueAt(load(locale), 'app.waves.panel.count') as string).split('|')
      expect(forms, locale).toHaveLength(2)
      for (const form of forms) {
        expect(form, locale).toContain('{count}')
      }
    }
  })

  it('no non-English locale ships an English copy (flaky ten included)', () => {
    const en = load(SOT)
    const offenders: string[] = []
    for (const locale of TARGETS) {
      const bundle = load(locale)
      for (const key of KEYS) {
        if (SAME_AS_ENGLISH_BY_DESIGN.has(`${locale}:${key}`)) {
          continue
        }
        if (valueAt(bundle, key) === valueAt(en, key)) {
          offenders.push(`${locale}:${key}`)
        }
      }
    }
    expect(offenders).toEqual([])
  })
})
