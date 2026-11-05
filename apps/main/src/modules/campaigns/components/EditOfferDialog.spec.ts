/**
 * AH-118 — Vitest coverage for the agency "Edit offer" dialog.
 * Pins: the editable form (prefilled, major→minor, no currency on the wire),
 * the locked state for each server reason (form disabled, reason sentence), a
 * 422 `assignment.offer_locked` flipping an OPEN dialog to that reason, the
 * no-op guard, and the generic failure.
 */

import { ApiError, type OfferEditLockReason } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

vi.mock('../api/campaigns.api', () => ({
  campaignsApi: { editOffer: vi.fn() },
}))

import { campaignsApi } from '../api/campaigns.api'
import EditOfferDialog from './EditOfferDialog.vue'

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

  return mount(EditOfferDialog, {
    props: {
      modelValue: true,
      agencyId: 'agency-ulid',
      campaignId: 'campaign-ulid',
      assignmentId: 'assignment-ulid',
      currentMinor: 100_00,
      currency: 'EUR',
      lockReason: null,
      ...overrides,
    },
    global: { plugins: [i18n, vuetify], stubs: { VDialog: VDialogStub } },
    attachTo: document.createElement('div'),
  })
}

function lockedError(reason: string): ApiError {
  return new ApiError({
    status: 422,
    code: 'assignment.offer_locked',
    message: 'locked',
    details: [{ code: 'assignment.offer_locked', meta: { reason } }],
  })
}

describe('EditOfferDialog (AH-118)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('opens prefilled with the current amount, the currency a read-only suffix', () => {
    const wrapper = mountDialog()
    const field = wrapper.findComponent({ name: 'VTextField' })
    expect(field.props('suffix')).toBe('EUR')
    expect(
      (wrapper.find('[data-test="edit-offer-amount"] input').element as HTMLInputElement).value,
    ).toBe('100')
    expect(wrapper.find('[data-test="edit-offer-locked"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('disables Save until the amount differs from the current one, and says so', async () => {
    const wrapper = mountDialog()
    const submit = wrapper.find('[data-test="edit-offer-submit"]')
    expect(submit.attributes('disabled')).toBeDefined()
    expect(wrapper.findComponent({ name: 'VTextField' }).props('errorMessages')).toContain(
      'That is the current amount.',
    )

    await wrapper.find('[data-test="edit-offer-amount"] input').setValue('250')
    expect(wrapper.find('[data-test="edit-offer-submit"]').attributes('disabled')).toBeUndefined()
    wrapper.unmount()
  })

  it('sends the amount in minor units and never a currency, then emits success', async () => {
    vi.mocked(campaignsApi.editOffer).mockResolvedValue({ data: {} } as never)
    const wrapper = mountDialog()

    await wrapper.find('[data-test="edit-offer-amount"] input').setValue('250.5')
    await wrapper.find('[data-test="edit-offer-submit"]').trigger('click')
    await flushPromises()

    expect(campaignsApi.editOffer).toHaveBeenCalledWith(
      'agency-ulid',
      'campaign-ulid',
      'assignment-ulid',
      { agreed_fee_minor_units: 250_50 },
    )
    expect(wrapper.emitted('success')).toHaveLength(1)
    wrapper.unmount()
  })

  it.each([
    ['status', 'The offer can no longer be edited at this stage.'],
    ['contract_pending', 'A contract is waiting for the creator to sign.'],
    ['contract_signed', 'The contract is signed.'],
    ['payment', 'Payment has started.'],
  ] as [OfferEditLockReason, string][])(
    'opens locked for %s: the reason sentence, a disabled form, no request',
    async (reason, sentence) => {
      const wrapper = mountDialog({ lockReason: reason })

      const alert = wrapper.find('[data-test="edit-offer-locked"]')
      expect(alert.text()).toBe(sentence)
      expect(alert.attributes('data-reason')).toBe(reason)
      expect(wrapper.findComponent({ name: 'VTextField' }).props('disabled')).toBe(true)

      await wrapper.find('[data-test="edit-offer-amount"] input').setValue('250')
      await wrapper.find('[data-test="edit-offer-submit"]').trigger('click')
      expect(campaignsApi.editOffer).not.toHaveBeenCalled()
      wrapper.unmount()
    },
  )

  it('flips an open dialog to the locked state when a 422 names the reason (a stale list)', async () => {
    vi.mocked(campaignsApi.editOffer).mockRejectedValue(lockedError('contract_pending'))
    const wrapper = mountDialog()

    await wrapper.find('[data-test="edit-offer-amount"] input').setValue('250')
    await wrapper.find('[data-test="edit-offer-submit"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="edit-offer-locked"]').text()).toBe(
      'A contract is waiting for the creator to sign.',
    )
    expect(wrapper.emitted('success')).toBeUndefined()
    wrapper.unmount()
  })

  it('shows the unchanged message when the server answers offer_unchanged', async () => {
    vi.mocked(campaignsApi.editOffer).mockRejectedValue(
      new ApiError({ status: 422, code: 'assignment.offer_unchanged', message: 'same' }),
    )
    const wrapper = mountDialog({ currentMinor: 100_00 })

    // 100.001 rounds to the current amount client-side only after the server
    // disagrees; model the race by changing then restoring via the server answer.
    await wrapper.find('[data-test="edit-offer-amount"] input').setValue('250')
    await wrapper.find('[data-test="edit-offer-submit"]').trigger('click')
    await flushPromises()

    expect(wrapper.findComponent({ name: 'VTextField' }).props('errorMessages')).toContain(
      'That is the current amount.',
    )
    wrapper.unmount()
  })

  it('shows the generic failure for any other error and stays open', async () => {
    vi.mocked(campaignsApi.editOffer).mockRejectedValue(
      new ApiError({ status: 500, code: 'http.server_error', message: 'boom' }),
    )
    const wrapper = mountDialog()

    await wrapper.find('[data-test="edit-offer-amount"] input').setValue('250')
    await wrapper.find('[data-test="edit-offer-submit"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="edit-offer-error"]').text()).toBe(
      'The offer could not be changed. Please try again.',
    )
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    wrapper.unmount()
  })
})
