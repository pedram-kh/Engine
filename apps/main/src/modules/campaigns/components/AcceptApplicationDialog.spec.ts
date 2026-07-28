/**
 * Vitest coverage for the accept-an-application dialog (AH-058, D2/Q2) and,
 * through it, the shared {@link OfferFieldsForm} child.
 *
 * The load-bearing cases are the two gate tiers, because they are the ones that
 * decide whether an operator can finish the job: a 409 must offer a
 * proceed-anyway path carrying the SAME payload, and a 422 must say which of the
 * four refusals happened rather than closing quietly.
 */

import { ApiError } from '@catalyst/api-client'
import type { CampaignApplicationListItemResource } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

vi.mock('../api/campaigns.api', () => ({
  campaignsApi: {
    acceptApplication: vi.fn(),
    offerAttachmentInit: vi.fn(),
    offerAttachmentComplete: vi.fn(),
  },
}))

import { campaignsApi } from '../api/campaigns.api'
import AcceptApplicationDialog from './AcceptApplicationDialog.vue'

const APPLICATION_ID = '01APPPENDINGXXXXXXXXXXXXXX'

const application: CampaignApplicationListItemResource = {
  id: APPLICATION_ID,
  type: 'campaign_application_list_item',
  attributes: {
    status: 'pending',
    note: 'Happy to shoot in Lisbon.',
    applied_at: '2026-07-01T10:00:00.000000Z',
    responded_at: null,
    creator: {
      id: '01CREATORULIDXXXXXXXXXXXXX',
      display_name: 'Maria Lopez',
      avatar_url: null,
    },
  },
}

function conflict(): ApiError {
  return new ApiError({
    status: 409,
    code: 'http.invalid_response_body',
    message: 'availability conflict',
    raw: { meta: { code: 'assignment.availability_conflict' } },
  })
}

function refusal(code: string): ApiError {
  return new ApiError({
    status: 422,
    code: 'http.invalid_response_body',
    message: 'refused',
    raw: { meta: { code } },
  })
}

async function mountDialog() {
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: enApp } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })

  const wrapper = mount(AcceptApplicationDialog, {
    props: {
      modelValue: true,
      agencyId: 'agency-ulid',
      campaignId: 'campaign-ulid',
      application,
      campaignCurrency: 'EUR',
    },
    global: { plugins: [i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return wrapper
}

/** The fee lives in the shared child; set it the way an operator would. */
async function fillFee(amount: number): Promise<void> {
  const input = document.querySelector<HTMLInputElement>(
    '[data-test="accept-application-fee"] input',
  )
  if (input === null) throw new Error('fee input not rendered')
  input.value = String(amount)
  input.dispatchEvent(new Event('input'))
  await flushPromises()
}

function submit(): void {
  document.querySelector<HTMLElement>('[data-test="accept-application-submit"]')?.click()
}

describe('AcceptApplicationDialog', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(campaignsApi.acceptApplication).mockResolvedValue({
      data: {
        id: '01ASSIGNMENTULIDXXXXXXXXXX',
        type: 'campaign_assignments',
        attributes: {
          status: 'invited',
          agreed_fee_minor_units: 50000,
          agreed_fee_currency: 'EUR',
          countered_fee_minor_units: null,
          countered_fee_currency: null,
          invited_at: '2026-07-02T10:00:00.000000Z',
          responded_at: null,
          posting_due_at: null,
          verification_status: null,
          has_pending_contract: null,
          creator: { id: '01CREATORULIDXXXXXXXXXXXXX', display_name: 'Maria Lopez' },
        },
      },
    })
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it("shows the applicant's note so the offer is written knowing what they said", async () => {
    const wrapper = await mountDialog()

    expect(document.querySelector('[data-test="accept-application-note"]')?.textContent).toContain(
      'Lisbon',
    )
    wrapper.unmount()
  })

  it('keeps submit disabled until the fee is a positive amount', async () => {
    const wrapper = await mountDialog()

    const button = document.querySelector('[data-test="accept-application-submit"]')
    expect(button?.classList.contains('v-btn--disabled')).toBe(true)

    await fillFee(500)

    expect(
      document
        .querySelector('[data-test="accept-application-submit"]')
        ?.classList.contains('v-btn--disabled'),
    ).toBe(false)
    wrapper.unmount()
  })

  it('sends the offer in MINOR units and emits accepted on success', async () => {
    const wrapper = await mountDialog()
    await fillFee(500)

    submit()
    await flushPromises()

    expect(campaignsApi.acceptApplication).toHaveBeenCalledWith(
      'agency-ulid',
      'campaign-ulid',
      APPLICATION_ID,
      {
        agreed_fee_minor_units: 50000,
        agreed_fee_currency: 'EUR',
        fee_per: null,
        offer_description: null,
        attachment: null,
        acknowledged: false,
      },
    )
    expect(wrapper.emitted('accepted')).toBeTruthy()
    expect(wrapper.emitted('update:modelValue')?.at(-1)?.[0]).toBe(false)
    wrapper.unmount()
  })

  it('never sends a creator_id — the applicant is the application', async () => {
    const wrapper = await mountDialog()
    await fillFee(500)

    submit()
    await flushPromises()

    const payload = vi.mocked(campaignsApi.acceptApplication).mock.calls[0]?.[3] as Record<
      string,
      unknown
    >
    expect(payload).not.toHaveProperty('creator_id')
    wrapper.unmount()
  })

  it('TIER 2: a 409 offers proceed-anyway and re-sends the same payload acknowledged', async () => {
    vi.mocked(campaignsApi.acceptApplication).mockRejectedValueOnce(conflict())
    const wrapper = await mountDialog()
    await fillFee(500)

    submit()
    await flushPromises()

    // The availability tier is a WARN, not a block: the dialog stays open and
    // the agency decides.
    expect(document.querySelector('[data-test="accept-availability-warning"]')).not.toBeNull()
    expect(wrapper.emitted('accepted')).toBeFalsy()

    document.querySelector<HTMLElement>('[data-test="accept-availability-proceed"]')?.click()
    await flushPromises()

    const second = vi.mocked(campaignsApi.acceptApplication).mock.calls[1]?.[3] as {
      acknowledged: boolean
      agreed_fee_minor_units: number
    }
    expect(second.acknowledged).toBe(true)
    expect(second.agreed_fee_minor_units).toBe(50000)
    expect(wrapper.emitted('accepted')).toBeTruthy()
    wrapper.unmount()
  })

  it('TIER 1: a 422 names the refusal and leaves the dialog open', async () => {
    vi.mocked(campaignsApi.acceptApplication).mockRejectedValue(refusal('assignment.blacklisted'))
    const wrapper = await mountDialog()
    await fillFee(500)

    submit()
    await flushPromises()

    expect(
      document.querySelector('[data-test="accept-application-refusal"]')?.textContent,
    ).toContain(enApp.app.campaigns.applications.refusal.assignment.blacklisted)
    expect(wrapper.emitted('accepted')).toBeFalsy()
    expect(wrapper.emitted('update:modelValue')).toBeFalsy()
    wrapper.unmount()
  })

  it.each([
    [
      'application.already_engaged',
      enApp.app.campaigns.applications.refusal.application.already_engaged,
    ],
    ['application.not_pending', enApp.app.campaigns.applications.refusal.application.not_pending],
    [
      'application.creator_not_approved',
      enApp.app.campaigns.applications.refusal.application.creator_not_approved,
    ],
  ])('maps the %s refusal to its own message', async (code, expected) => {
    vi.mocked(campaignsApi.acceptApplication).mockRejectedValue(refusal(code))
    const wrapper = await mountDialog()
    await fillFee(500)

    submit()
    await flushPromises()

    expect(
      document.querySelector('[data-test="accept-application-refusal"]')?.textContent,
    ).toContain(expected)
    wrapper.unmount()
  })

  it('falls back to the generic message for an unrecognised refusal', async () => {
    vi.mocked(campaignsApi.acceptApplication).mockRejectedValue(refusal('something.new'))
    const wrapper = await mountDialog()
    await fillFee(500)

    submit()
    await flushPromises()

    expect(
      document.querySelector('[data-test="accept-application-refusal"]')?.textContent,
    ).toContain(enApp.app.campaigns.applications.refusal.generic)
    wrapper.unmount()
  })
})
