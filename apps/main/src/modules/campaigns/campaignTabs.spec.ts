import { describe, expect, it } from 'vitest'

import { CAMPAIGN_DETAIL_TABS, campaignTabFromQuery, DEFAULT_CAMPAIGN_TAB } from './campaignTabs'

describe('campaignTabFromQuery (AH-103 whitelist)', () => {
  it.each(CAMPAIGN_DETAIL_TABS)('accepts %s', (tab) => {
    expect(campaignTabFromQuery(tab)).toBe(tab)
  })

  it('takes the first value of a repeated key', () => {
    expect(campaignTabFromQuery(['drafts', 'creators'])).toBe('drafts')
  })

  it.each([
    ['unknown', 'nonsense'],
    ['settings (role-gated, never a link target)', 'settings'],
    ['wrong case', 'Drafts'],
    ['padded', ' drafts'],
    ['prototype key', '__proto__'],
    ['empty', ''],
    ['null', null],
    ['undefined', undefined],
    ['a number', 3],
    ['an object', { tab: 'drafts' }],
    ['an empty array', []],
    ['an array led by junk', ['nope', 'drafts']],
  ])('rejects %s', (_label, value) => {
    expect(campaignTabFromQuery(value)).toBeNull()
  })

  it('the default is a whitelisted tab, and settings is deliberately not on the list', () => {
    expect(CAMPAIGN_DETAIL_TABS).toContain(DEFAULT_CAMPAIGN_TAB)
    expect(CAMPAIGN_DETAIL_TABS as readonly string[]).not.toContain('settings')
  })
})
