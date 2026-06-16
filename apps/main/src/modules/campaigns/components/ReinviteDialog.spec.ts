/**
 * Re-invite UI chunk — Vitest coverage for the agency re-offer dialog.
 * Pins: countered-fee context, major↔minor conversion, reinvite() payload,
 * success emit, per-field 422 binding, read-only campaign currency suffix.
 */

import { ApiError, type CampaignAssignmentResource } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

vi.mock('../api/campaigns.api', () => ({
  campaignsApi: { reinvite: vi.fn() },
}))

import { campaignsApi } from '../api/campaigns.api'
import ReinviteDialog from './ReinviteDialog.vue'

const ASSIGNMENT_ID = '01ASSIGNULIDXXXXXXXXXXXXXX'

function makeAssignment(): CampaignAssignmentResource {
  return {
    id: ASSIGNMENT_ID,
    type: 'campaign_assignments',
    attributes: {
      status: 'countered',
      agreed_fee_minor_units: 100000,
      agreed_fee_currency: 'EUR',
      countered_fee_minor_units: 150000,
      countered_fee_currency: 'EUR',
      invited_at: '2026-06-01T10:00:00.000000Z',
      responded_at: '2026-06-02T10:00:00.000000Z',
      posting_due_at: null,
      verification_status: null,
      has_pending_contract: null,
      creator: { id: 'creator-ulid', display_name: 'Alex Creator' },
    },
  }
}

const VDialogStub = {
  name: 'VDialog',
  props: ['modelValue'],
  template: '<div class="vdialog-stub"><slot /></div>',
}

function mountDialog(overrides: Record<string, unknown> = {}) {
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: enApp } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })

  const wrapper = mount(ReinviteDialog, {
    props: {
      modelValue: true,
      agencyId: 'agency-ulid',
      campaignId: 'campaign-ulid',
      assignment: makeAssignment(),
      campaignCurrency: 'EUR',
      ...overrides,
    },
    global: {
      plugins: [i18n, vuetify],
      stubs: { VDialog: VDialogStub },
    },
    attachTo: document.createElement('div'),
  })
  return wrapper
}

describe('ReinviteDialog (re-invite UI chunk)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('shows the countered fee as context in the dialog body', () => {
    const wrapper = mountDialog()
    const body = wrapper.find('[data-test="reinvite-dialog-body"]')
    expect(body.text()).toContain('€1,500.00')
    wrapper.unmount()
  })

  it('shows the campaign currency as a read-only suffix on the fee field', () => {
    const wrapper = mountDialog()
    const field = wrapper.findComponent({ name: 'VTextField' })
    expect(field.props('suffix')).toBe('EUR')
    wrapper.unmount()
  })

  it('converts major units to minor on submit (1,500.00 → 150000)', async () => {
    vi.mocked(campaignsApi.reinvite).mockResolvedValue({
      data: makeAssignment(),
    })
    const wrapper = mountDialog()

    const input = wrapper.find('[data-test="reinvite-fee"] input')
    await input.setValue('1500')

    await wrapper.find('[data-test="reinvite-submit"]').trigger('click')
    await flushPromises()

    expect(campaignsApi.reinvite).toHaveBeenCalledWith(
      'agency-ulid',
      'campaign-ulid',
      ASSIGNMENT_ID,
      { agreed_fee_minor_units: 150000, agreed_fee_currency: 'EUR' },
    )
    wrapper.unmount()
  })

  it('emits success after a successful reinvite()', async () => {
    vi.mocked(campaignsApi.reinvite).mockResolvedValue({
      data: makeAssignment(),
    })
    const wrapper = mountDialog()

    const input = wrapper.find('[data-test="reinvite-fee"] input')
    await input.setValue('1200')

    await wrapper.find('[data-test="reinvite-submit"]').trigger('click')
    await flushPromises()

    expect(wrapper.emitted('success')).toHaveLength(1)
    wrapper.unmount()
  })

  it('binds a fee 422 onto the amount field via extractFieldErrors', async () => {
    vi.mocked(campaignsApi.reinvite).mockRejectedValue(
      new ApiError({
        status: 422,
        code: 'validation.failed',
        message: 'Validation failed.',
        details: [
          {
            code: 'validation.min',
            detail: 'The agreed fee must be positive.',
            source: { pointer: '/data/attributes/agreed_fee_minor_units' },
          },
        ],
      }),
    )
    const wrapper = mountDialog()

    const input = wrapper.find('[data-test="reinvite-fee"] input')
    await input.setValue('100')

    await wrapper.find('[data-test="reinvite-submit"]').trigger('click')
    await flushPromises()

    const field = wrapper.findComponent({ name: 'VTextField' })
    expect(field.props('errorMessages')).toContain('The agreed fee must be positive.')
    expect(wrapper.emitted('success')).toBeUndefined()
    wrapper.unmount()
  })
})
