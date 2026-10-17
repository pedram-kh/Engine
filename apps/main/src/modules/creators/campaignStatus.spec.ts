/**
 * AH-107 — the one table that says how a campaign's state reads to a creator.
 * Every surface (assignment card, assignment detail, job card, job detail) reads
 * it, so these specs are the executable form of Q1 and Q3:
 *   - Q1: `draft` is "Upcoming" (never "Draft").
 *   - Q3: job surfaces show Paused ONLY; the assignment surfaces show all five.
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import type { CampaignStatus } from '@catalyst/api-client'
import { describe, expect, it } from 'vitest'

import enCreator from '@/core/i18n/locales/en/creator.json'

import { CAMPAIGN_STATUSES, campaignStatusPresentation } from './campaignStatus'

/** The backend enum, read from the source of truth so a sixth case fails here. */
function backendStatuses(): string[] {
  const source = readFileSync(
    resolve(__dirname, '../../../../api/app/Modules/Campaigns/Enums/CampaignStatus.php'),
    'utf8',
  )
  return [...source.matchAll(/case\s+\w+\s*=\s*'([a-z_]+)'/g)].map((m) => m[1] as string)
}

function lookup(key: string): unknown {
  return key.split('.').reduce<unknown>((node, part) => {
    return typeof node === 'object' && node !== null
      ? (node as Record<string, unknown>)[part]
      : undefined
  }, enCreator)
}

describe('campaignStatusPresentation', () => {
  it('covers exactly the backend CampaignStatus enum', () => {
    expect([...CAMPAIGN_STATUSES].sort()).toEqual(backendStatuses().sort())
  })

  it('labels draft as "Upcoming" and never "Draft" (Q1)', () => {
    const p = campaignStatusPresentation('draft')
    expect(p?.labelKey).toBe('creator.ui.campaignStatus.upcoming')
    expect(lookup(p!.labelKey)).toBe('Upcoming')
    for (const status of CAMPAIGN_STATUSES) {
      expect(lookup(campaignStatusPresentation(status)!.labelKey)).not.toBe('Draft')
    }
  })

  it('shows PAUSED and only paused on a job surface (Q3)', () => {
    const shown = CAMPAIGN_STATUSES.filter((s) => campaignStatusPresentation(s)?.showOnJob)
    expect(shown).toEqual(['paused'])
  })

  it('gives a banner to paused, completed and cancelled only (Q2)', () => {
    const withBanner = CAMPAIGN_STATUSES.filter(
      (s) => campaignStatusPresentation(s)?.bannerKey !== null,
    )
    expect(withBanner.sort()).toEqual(['cancelled', 'completed', 'paused'])
  })

  it.each(CAMPAIGN_STATUSES)('%s: every i18n key it names exists in en', (status) => {
    const p = campaignStatusPresentation(status as CampaignStatus)!
    expect(typeof lookup(p.labelKey)).toBe('string')
    if (p.bannerKey !== null) expect(typeof lookup(p.bannerKey)).toBe('string')
  })

  it.each([undefined, null, '', 'archived', 42, {}, 'toString', '__proto__'])(
    'returns null for an absent or unknown value (%s), so nothing renders',
    (value) => {
      expect(campaignStatusPresentation(value)).toBeNull()
    },
  )
})
