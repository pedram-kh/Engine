/**
 * AH-119 Stage B — the unified inbox with the Stage B search off: the markup is the one Stage A
 * shipped (pinned in `stageBFlagOffPins.json`), the search field stays a client-side filter, and a
 * deleted newest message previews as "Message deleted" (only when the row says so).
 *
 * Break-revert: render the result group (or an empty `<!--v-if-->`) when `search` is null → the pin
 * fails; drop the `previewIsDeleted` branch → the deleted-preview case fails.
 */

import { flushPromises } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import AgencyUnifiedInbox from '@/modules/agency/components/AgencyUnifiedInbox.vue'

import {
  FLAG_OFF_PINS,
  mountView,
  renderHtml,
  unifiedInboxProps,
} from '../../helpers/stageBFlagOff'

describe('AgencyUnifiedInbox — Stage B off', () => {
  it('renders the pre-Stage-B markup when no search prop is given', async () => {
    const html = await renderHtml(
      AgencyUnifiedInbox,
      unifiedInboxProps,
      '[data-test="unified-inbox"]',
    )
    expect(html).toBe(FLAG_OFF_PINS['unified-inbox'])
    expect(html).not.toContain('message-search-results')
  })

  it('renders the same markup for an explicit null search', async () => {
    const html = await renderHtml(
      AgencyUnifiedInbox,
      { ...unifiedInboxProps, search: null },
      '[data-test="unified-inbox"]',
    )
    expect(html).toBe(FLAG_OFF_PINS['unified-inbox'])
  })

  it('keeps the search field as a client-side filter of the loaded rows', async () => {
    const wrapper = await mountView(AgencyUnifiedInbox, unifiedInboxProps)
    await wrapper.get('[data-test="agency-inbox-search"] input').setValue('Ana')
    await flushPromises()
    expect(wrapper.find('[data-test="inbox-row-k1"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="inbox-row-k2"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('previews a deleted newest message as "Message deleted", not as an empty row', async () => {
    const wrapper = await mountView(AgencyUnifiedInbox, {
      ...unifiedInboxProps,
      items: [
        { ...unifiedInboxProps.items[0], preview: null, previewIsDeleted: true },
        unifiedInboxProps.items[1],
      ],
    })
    const preview = wrapper.get('[data-test="inbox-row-k1"] .agency-inbox-row__preview')
    expect(preview.text()).toBe('Message deleted')
    expect(preview.classes()).toContain('agency-inbox-row__preview--deleted')
    // The other row is untouched.
    expect(wrapper.get('[data-test="inbox-row-k2"] .agency-inbox-row__preview').text()).toBe(
      'Attachment',
    )
    wrapper.unmount()
  })
})
