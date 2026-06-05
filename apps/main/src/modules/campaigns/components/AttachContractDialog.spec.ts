/**
 * Attach-contract dialog — Vitest coverage for the contract-issue visibility
 * fix. Pins: success emit, and the previously-silent failure paths now surface
 * an `error` event (top-level 422 `contract.already_attached` + generic 5xx),
 * while per-field 422s keep the dialog open.
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
  campaignsApi: { attachContract: vi.fn() },
}))

import { campaignsApi } from '../api/campaigns.api'
import AttachContractDialog from './AttachContractDialog.vue'

const ASSIGNMENT_ID = '01ASSIGNULIDXXXXXXXXXXXXXX'

function makeAssignment(): CampaignAssignmentResource {
  return {
    id: ASSIGNMENT_ID,
    type: 'campaign_assignments',
    attributes: {
      status: 'accepted',
      agreed_fee_minor_units: 100000,
      agreed_fee_currency: 'EUR',
      countered_fee_minor_units: null,
      countered_fee_currency: null,
      invited_at: '2026-06-01T10:00:00.000000Z',
      responded_at: null,
      posting_due_at: null,
      verification_status: null,
      has_pending_contract: false,
      creator: { id: 'creator-ulid', display_name: 'Alex Creator' },
    },
  }
}

const VDialogStub = {
  name: 'VDialog',
  props: ['modelValue'],
  template: '<div class="vdialog-stub"><slot /></div>',
}

function mountDialog() {
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: enApp } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })

  return mount(AttachContractDialog, {
    props: {
      modelValue: true,
      agencyId: 'agency-ulid',
      campaignId: 'campaign-ulid',
      assignment: makeAssignment(),
    },
    global: {
      plugins: [i18n, vuetify],
      stubs: { VDialog: VDialogStub },
    },
    attachTo: document.createElement('div'),
  })
}

async function fillAndSubmit(wrapper: ReturnType<typeof mountDialog>) {
  await wrapper.find('[data-test="attach-contract-title"] input').setValue('Service agreement')
  await wrapper.find('[data-test="attach-contract-terms"] textarea').setValue('You agree to post.')
  await wrapper.find('[data-test="attach-contract-submit"]').trigger('click')
  await flushPromises()
}

describe('AttachContractDialog (contract-issue visibility fix)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('emits success and closes after a successful attach()', async () => {
    vi.mocked(campaignsApi.attachContract).mockResolvedValue({
      data: { id: 'contract-ulid', type: 'contracts', attributes: {} },
    } as never)
    const wrapper = mountDialog()

    await fillAndSubmit(wrapper)

    expect(campaignsApi.attachContract).toHaveBeenCalledWith(
      'agency-ulid',
      'campaign-ulid',
      ASSIGNMENT_ID,
      { title: 'Service agreement', body_markdown: 'You agree to post.', body_pdf_path: null },
    )
    expect(wrapper.emitted('success')).toHaveLength(1)
    expect(wrapper.emitted('error')).toBeUndefined()
    wrapper.unmount()
  })

  it('surfaces a friendly error on a top-level 422 contract.already_attached', async () => {
    vi.mocked(campaignsApi.attachContract).mockRejectedValue(
      new ApiError({
        status: 422,
        code: 'contract.already_attached',
        message: 'A contract is already awaiting creator acceptance.',
      }),
    )
    const wrapper = mountDialog()

    await fillAndSubmit(wrapper)

    expect(wrapper.emitted('success')).toBeUndefined()
    const errors = wrapper.emitted('error') as string[][] | undefined
    expect(errors).toHaveLength(1)
    expect(errors?.[0]?.[0]).toBe(enApp.app.campaigns.contract.attach.alreadyAttached)
    wrapper.unmount()
  })

  it('surfaces a generic error on a non-422 failure instead of closing silently', async () => {
    vi.mocked(campaignsApi.attachContract).mockRejectedValue(
      new ApiError({ status: 500, code: 'server.error', message: 'Boom.' }),
    )
    const wrapper = mountDialog()

    await fillAndSubmit(wrapper)

    expect(wrapper.emitted('success')).toBeUndefined()
    const errors = wrapper.emitted('error') as string[][] | undefined
    expect(errors).toHaveLength(1)
    expect(errors?.[0]?.[0]).toBe(enApp.app.campaigns.contract.attach.error)
    wrapper.unmount()
  })

  it('keeps the dialog open (no error emit) on a per-field 422', async () => {
    vi.mocked(campaignsApi.attachContract).mockRejectedValue(
      new ApiError({
        status: 422,
        code: 'validation.failed',
        message: 'Validation failed.',
        details: [
          {
            code: 'validation.required',
            detail: 'The contract title is required.',
            source: { pointer: '/data/attributes/title' },
          },
        ],
      }),
    )
    const wrapper = mountDialog()

    await fillAndSubmit(wrapper)

    expect(wrapper.emitted('success')).toBeUndefined()
    expect(wrapper.emitted('error')).toBeUndefined()
    wrapper.unmount()
  })
})
