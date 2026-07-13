/**
 * BoardCard (Sprint 12 Chunk 2, D-10; board-card facelift). Pins the null-safe
 * card face: lead avatar + name + days-remaining, status/history chips, the
 * agreed fee, and the aurora accent bar. A null assignment renders the
 * "removed" tile instead of crashing.
 */

import type { BoardCardResource } from '@catalyst/api-client'
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

import BoardCard from './BoardCard.vue'

function card(
  assignment: BoardCardResource['relationships']['assignment']['data'],
): BoardCardResource {
  return {
    id: 'k1',
    type: 'board_cards',
    attributes: {
      position: 0,
      created_at: '2026-06-01T00:00:00+00:00',
      updated_at: '2026-06-01T00:00:00+00:00',
    },
    relationships: {
      column: { data: { id: 'c1', type: 'board_columns' } },
      assignment: { data: assignment },
    },
  }
}

function assignmentData(
  overrides: Partial<NonNullable<BoardCardResource['relationships']['assignment']['data']>> = {},
): NonNullable<BoardCardResource['relationships']['assignment']['data']> {
  return {
    id: 'a1',
    type: 'campaign_assignments',
    status: 'invited',
    deliverables: null,
    posting_due_at: null,
    creator: { id: 'cr1', display_name: 'Jane Q' },
    ...overrides,
  }
}

function mountCard(c: BoardCardResource) {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  return mount(BoardCard, {
    props: { card: c },
    global: { plugins: [i18n, vuetify] },
  })
}

function isoInDays(days: number): string {
  const d = new Date()
  d.setDate(d.getDate() + days)
  return d.toISOString()
}

describe('BoardCard', () => {
  it('renders the creator name + status badge + aurora accent', () => {
    const wrapper = mountCard(card(assignmentData()))
    expect(wrapper.find('[data-test="board-card-name-k1"]').text()).toBe('Jane Q')
    expect(wrapper.find('[data-test="board-card-status-k1"]').text()).toBe('Invited')
    expect(wrapper.find('.board-card__accent').exists()).toBe(true)
  })

  it('leads with the initial when no avatar, and the photo when present', () => {
    const noAvatar = mountCard(card(assignmentData()))
    expect(noAvatar.find('[data-test="board-card-avatar-k1"]').exists()).toBe(false)
    expect(noAvatar.text()).toContain('J')

    const withAvatar = mountCard(
      card(
        assignmentData({
          creator: { id: 'cr1', display_name: 'Jane Q', avatar_url: 'https://cdn/x.jpg' },
        }),
      ),
    )
    expect(withAvatar.find('[data-test="board-card-avatar-k1"]').exists()).toBe(true)
    // The url is the VImg's `src` prop (the fall-through data-test sits on the
    // VImg root, so the inner <img> src isn't a root attribute).
    const vimg = withAvatar.findComponent({ name: 'VImg' })
    expect(vimg.props('src')).toBe('https://cdn/x.jpg')
  })

  it('anchors the agreed fee with its unit when present, and hides it otherwise', () => {
    const withFee = mountCard(
      card(
        assignmentData({
          agreed_fee_minor_units: 20000,
          agreed_fee_currency: 'EUR',
          fee_per: 'script',
        }),
      ),
    )
    const fee = withFee.find('[data-test="board-card-fee-k1"]')
    expect(fee.exists()).toBe(true)
    expect(fee.text()).toContain('200')
    expect(fee.text()).toContain('script')

    const noFee = mountCard(card(assignmentData()))
    expect(noFee.find('[data-test="board-card-fee-k1"]').exists()).toBe(false)
  })

  it('shows days-remaining for a future due date', () => {
    const wrapper = mountCard(card(assignmentData({ posting_due_at: isoInDays(3) })))
    expect(wrapper.find('[data-test="board-card-due-k1"]').text()).toBe('3d left')
  })

  it('shows "Overdue" in the error tone for a past due date', () => {
    const wrapper = mountCard(card(assignmentData({ posting_due_at: isoInDays(-2) })))
    const due = wrapper.find('[data-test="board-card-due-k1"]')
    expect(due.text()).toBe('Overdue')
    expect(due.classes()).toContain('text-error')
  })

  it('falls back to "Unnamed creator" when the display name is null', () => {
    const wrapper = mountCard(card(assignmentData({ creator: { id: 'cr1', display_name: null } })))
    expect(wrapper.find('[data-test="board-card-name-k1"]').text()).toBe('Unnamed creator')
  })

  it('renders a null-safe "removed" tile when the assignment is null', () => {
    const wrapper = mountCard(card(null))
    expect(wrapper.find('[data-test="board-card-removed-k1"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="board-card-name-k1"]').exists()).toBe(false)
  })

  it('shows a Declined history tag on a re-offered row, alongside the live status', () => {
    const wrapper = mountCard(
      card(assignmentData({ status: 'invited', previously_declined: true })),
    )
    expect(wrapper.find('[data-test="board-card-declined-history-k1"]').text()).toBe('Declined')
    expect(wrapper.find('[data-test="board-card-status-k1"]').text()).toBe('Invited')
  })

  it('hides the history tag on a plain invited row, and while still declined', () => {
    const plain = mountCard(card(assignmentData({ status: 'invited' })))
    expect(plain.find('[data-test="board-card-declined-history-k1"]').exists()).toBe(false)

    // A still-declined row shows only the status chip (no redundant tag).
    const declined = mountCard(
      card(assignmentData({ status: 'declined', previously_declined: true })),
    )
    expect(declined.find('[data-test="board-card-declined-history-k1"]').exists()).toBe(false)
  })
})
