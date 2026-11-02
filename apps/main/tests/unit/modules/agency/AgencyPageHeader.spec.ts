/**
 * AH-114 (S3.1, S3.2) — `AgencyPageHeader`: the title is the page's h1 and carries the page's own
 * anchor, the subtitle and the actions column render only when the page supplies them.
 * Type and spacing are pinned in a real browser by `tools/walk/pins-s31.mjs`.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import AgencyPageHeader from '@/modules/agency/components/AgencyPageHeader.vue'

describe('AgencyPageHeader', () => {
  it('renders the title as the h1 with the page anchor', () => {
    const wrapper = mount(AgencyPageHeader, {
      props: { title: 'Campaigns', titleTest: 'campaign-list-heading' },
    })
    const h1 = wrapper.get('h1')
    expect(h1.text()).toBe('Campaigns')
    expect(h1.attributes('data-test')).toBe('campaign-list-heading')
  })

  it.each([undefined, null, ''])('renders no subtitle line for %s', (subtitle) => {
    const wrapper = mount(AgencyPageHeader, { props: { title: 'Campaigns', subtitle } })
    expect(wrapper.find('.agency-page-header__subtitle').exists()).toBe(false)
  })

  it('renders the subtitle with its anchor when there is one', () => {
    const wrapper = mount(AgencyPageHeader, {
      props: { title: 'Campaigns', subtitle: '24 campaigns', subtitleTest: 'summary' },
    })
    const line = wrapper.get('.agency-page-header__subtitle')
    expect(line.text()).toBe('24 campaigns')
    expect(line.attributes('data-test')).toBe('summary')
  })

  it('renders the actions column only when the page passes actions', () => {
    const bare = mount(AgencyPageHeader, { props: { title: 'Campaigns' } })
    expect(bare.find('.agency-page-header__actions').exists()).toBe(false)

    const withActions = mount(AgencyPageHeader, {
      props: { title: 'Campaigns' },
      slots: { actions: '<button data-test="go">New</button>' },
    })
    expect(withActions.get('.agency-page-header__actions [data-test="go"]').text()).toBe('New')
  })

  it('puts a leading slot (the back button) before the text, in the same lead row', () => {
    const wrapper = mount(AgencyPageHeader, {
      props: { title: 'Summer launch' },
      slots: { leading: '<button data-test="back">Back</button>' },
    })
    const lead = wrapper.get('.agency-page-header__lead')
    expect(lead.element.firstElementChild?.getAttribute('data-test')).toBe('back')
    expect(lead.element.lastElementChild?.classList.contains('agency-page-header__text')).toBe(true)
  })

  it('puts a title-suffix slot (the status pill) after the h1, in the heading row', () => {
    const wrapper = mount(AgencyPageHeader, {
      props: { title: 'Summer launch' },
      slots: { 'title-suffix': '<span data-test="pill">Active</span>' },
    })
    const row = wrapper.get('.agency-page-header__heading')
    expect(row.element.firstElementChild?.tagName).toBe('H1')
    expect(row.element.lastElementChild?.getAttribute('data-test')).toBe('pill')
  })
})
