/**
 * CreatorDetailPage unit tests — Sprint 3 Chunk 4 sub-step 9.
 *
 * Focus: the per-field edit affordance and the page-level wiring
 * between `EditFieldRow`, `EditFieldModal`, and `adminCreatorsApi`.
 * The read-only render path was covered in sub-step 9 of Chunk 3;
 * this spec stays focused on the edit interactions.
 */

import { ApiError } from '@catalyst/api-client'
import type { CreatorResource, CreatorResourceEnvelope } from '@catalyst/api-client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'

vi.mock('@/modules/creators/api/creators.api', async () => {
  const actual = await vi.importActual<typeof import('@/modules/creators/api/creators.api')>(
    '@/modules/creators/api/creators.api',
  )
  return {
    ...actual,
    adminCreatorsApi: {
      show: vi.fn(),
      updateField: vi.fn(),
      approve: vi.fn(),
      reject: vi.fn(),
    },
  }
})

import { adminCreatorsApi } from '@/modules/creators/api/creators.api'

import { mountCreatorPage } from '../../../../tests/unit/helpers/mountCreatorPage'
import CreatorDetailPage from './CreatorDetailPage.vue'

function buildCreator(overrides: Partial<CreatorResource['attributes']> = {}): CreatorResource {
  return {
    id: '01HQABCD',
    type: 'creators',
    attributes: {
      display_name: 'Jane Doe',
      bio: 'Old bio',
      country_code: 'IE',
      region: 'Dublin',
      primary_language: 'en',
      secondary_languages: ['pt'],
      categories: ['fashion', 'beauty'],
      avatar_path: null,
      cover_path: null,
      verification_level: 'unverified',
      application_status: 'pending',
      tier: null,
      kyc_status: 'none',
      kyc_verified_at: null,
      tax_profile_complete: false,
      payout_method_set: false,
      has_signed_master_contract: false,
      click_through_accepted_at: null,
      social_accounts: [],
      portfolio: [],
      profile_completeness_score: 60,
      submitted_at: null,
      approved_at: null,
      created_at: '2026-01-01T00:00:00Z',
      updated_at: '2026-01-01T00:00:00Z',
      ...overrides,
    },
    wizard: {
      next_step: 'profile',
      is_submitted: false,
      steps: [],
      weights: {},
      flags: {
        kyc_verification_enabled: true,
        creator_payout_method_enabled: true,
        contract_signing_enabled: true,
      },
    },
    admin_attributes: {
      rejection_reason: null,
      rejected_at: null,
      last_active_at: null,
      kyc_verifications: [],
    },
  }
}

function envelope(creator: CreatorResource): CreatorResourceEnvelope {
  return { data: creator }
}

describe('CreatorDetailPage — per-field edit (Sprint 3 Chunk 4 sub-step 9)', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    teardown?.()
    teardown = null
    document.body.innerHTML = ''
  })

  it('renders an EditFieldRow for each of the 7 editable fields', async () => {
    vi.mocked(adminCreatorsApi.show).mockResolvedValue(envelope(buildCreator()))
    const h = await mountCreatorPage(CreatorDetailPage)
    teardown = h.unmount
    await flushPromises()

    const expectedFields = [
      'display_name',
      'bio',
      'country_code',
      'region',
      'primary_language',
      'secondary_languages',
      'categories',
    ]
    for (const field of expectedFields) {
      expect(
        h.wrapper.find(`[data-testid="admin-creator-detail-row-${field}"]`).exists(),
        `EditFieldRow for ${field} should be present`,
      ).toBe(true)
      expect(
        h.wrapper.find(`[data-testid="admin-creator-detail-row-${field}-edit"]`).exists(),
        `Edit button for ${field} should be present`,
      ).toBe(true)
    }
  })

  it('opens the edit modal with the right field when an edit button is clicked', async () => {
    vi.mocked(adminCreatorsApi.show).mockResolvedValue(envelope(buildCreator()))
    const h = await mountCreatorPage(CreatorDetailPage)
    teardown = h.unmount
    await flushPromises()

    expect(document.body.querySelector('[data-testid="admin-creator-edit-modal"]')).toBeFalsy()

    await h.wrapper
      .find('[data-testid="admin-creator-detail-row-display_name-edit"]')
      .trigger('click')
    await flushPromises()

    const title = document.body.querySelector('[data-testid="admin-creator-edit-modal-title"]')
    expect(title?.textContent?.trim()).toBe('Edit Display name')
  })

  it('happy path: saves a field edit, closes the modal, and shows the snackbar', async () => {
    vi.mocked(adminCreatorsApi.show).mockResolvedValue(envelope(buildCreator()))
    vi.mocked(adminCreatorsApi.updateField).mockResolvedValue(
      envelope(buildCreator({ display_name: 'Jane Updated' })),
    )

    const h = await mountCreatorPage(CreatorDetailPage)
    teardown = h.unmount
    await flushPromises()

    await h.wrapper
      .find('[data-testid="admin-creator-detail-row-display_name-edit"]')
      .trigger('click')
    await flushPromises()

    const input = document.body.querySelector<HTMLInputElement>(
      '[data-testid="admin-creator-edit-modal-text"] input',
    )!
    input.value = 'Jane Updated'
    input.dispatchEvent(new Event('input'))
    await flushPromises()

    const save = document.body.querySelector<HTMLButtonElement>(
      '[data-testid="admin-creator-edit-modal-save"]',
    )!
    save.click()
    await flushPromises()

    expect(adminCreatorsApi.updateField).toHaveBeenCalledWith(
      '01HQABCD',
      'display_name',
      'Jane Updated',
      null,
    )

    expect(h.wrapper.find('[data-testid="admin-creator-detail-value-display_name"]').text()).toBe(
      'Jane Updated',
    )

    const snackbar = document.body.querySelector(
      '[data-testid="admin-creator-detail-saved-snackbar"]',
    )
    expect(snackbar?.textContent).toContain('Display name updated.')
  })

  it('error path: keeps the modal open and surfaces the API error code', async () => {
    vi.mocked(adminCreatorsApi.show).mockResolvedValue(envelope(buildCreator()))
    vi.mocked(adminCreatorsApi.updateField).mockRejectedValue(
      new ApiError({
        status: 422,
        code: 'admin.creators.detail.edit.save_failed',
        message: 'no',
      }),
    )

    const h = await mountCreatorPage(CreatorDetailPage)
    teardown = h.unmount
    await flushPromises()

    await h.wrapper
      .find('[data-testid="admin-creator-detail-row-display_name-edit"]')
      .trigger('click')
    await flushPromises()

    const input = document.body.querySelector<HTMLInputElement>(
      '[data-testid="admin-creator-edit-modal-text"] input',
    )!
    input.value = 'Jane'
    input.dispatchEvent(new Event('input'))
    await flushPromises()

    const save = document.body.querySelector<HTMLButtonElement>(
      '[data-testid="admin-creator-edit-modal-save"]',
    )!
    save.click()
    await flushPromises()

    const error = document.body.querySelector('[data-testid="admin-creator-edit-modal-error"]')
    expect(error?.textContent?.trim()).toBe("We couldn't save this change. Please try again.")
    expect(document.body.querySelector('[data-testid="admin-creator-edit-modal"]')).toBeTruthy()
  })

  it('does NOT show the Approve / Reject buttons when status is already approved', async () => {
    vi.mocked(adminCreatorsApi.show).mockResolvedValue(
      envelope(
        buildCreator({ application_status: 'approved', approved_at: '2026-01-01T00:00:00Z' }),
      ),
    )
    const h = await mountCreatorPage(CreatorDetailPage)
    teardown = h.unmount
    await flushPromises()
    expect(h.wrapper.find('[data-testid="admin-creator-detail-approve"]').exists()).toBe(false)
    expect(h.wrapper.find('[data-testid="admin-creator-detail-reject"]').exists()).toBe(false)
  })

  it('hides Reject (but keeps Approve) when status is already rejected', async () => {
    vi.mocked(adminCreatorsApi.show).mockResolvedValue(
      envelope(
        buildCreator({
          application_status: 'rejected',
        }),
      ),
    )
    const h = await mountCreatorPage(CreatorDetailPage)
    teardown = h.unmount
    await flushPromises()
    expect(h.wrapper.find('[data-testid="admin-creator-detail-approve"]').exists()).toBe(true)
    expect(h.wrapper.find('[data-testid="admin-creator-detail-reject"]').exists()).toBe(false)
  })

  it('happy path: approves the creator, refreshes the page, and shows the snackbar', async () => {
    vi.mocked(adminCreatorsApi.show).mockResolvedValue(envelope(buildCreator()))
    vi.mocked(adminCreatorsApi.approve).mockResolvedValue(
      envelope(
        buildCreator({ application_status: 'approved', approved_at: '2026-02-01T00:00:00Z' }),
      ),
    )
    const h = await mountCreatorPage(CreatorDetailPage)
    teardown = h.unmount
    await flushPromises()

    await h.wrapper.find('[data-testid="admin-creator-detail-approve"]').trigger('click')
    await flushPromises()

    const confirm = document.body.querySelector<HTMLButtonElement>(
      '[data-testid="admin-creator-approve-dialog-confirm"]',
    )!
    confirm.click()
    await flushPromises()

    expect(adminCreatorsApi.approve).toHaveBeenCalledWith('01HQABCD', null)
    expect(h.wrapper.find('[data-testid="admin-creator-detail-approve"]').exists()).toBe(false)
    const snackbar = document.body.querySelector(
      '[data-testid="admin-creator-detail-decision-snackbar"]',
    )
    expect(snackbar?.textContent).toContain('Creator approved.')
  })

  it('forwards the welcome message when provided to approve', async () => {
    vi.mocked(adminCreatorsApi.show).mockResolvedValue(envelope(buildCreator()))
    vi.mocked(adminCreatorsApi.approve).mockResolvedValue(
      envelope(buildCreator({ application_status: 'approved' })),
    )
    const h = await mountCreatorPage(CreatorDetailPage)
    teardown = h.unmount
    await flushPromises()

    await h.wrapper.find('[data-testid="admin-creator-detail-approve"]').trigger('click')
    await flushPromises()

    const textarea = document.body.querySelector<HTMLTextAreaElement>(
      '[data-testid="admin-creator-approve-dialog-welcome"] textarea',
    )!
    textarea.value = 'Welcome aboard!'
    textarea.dispatchEvent(new Event('input'))
    await flushPromises()

    document
      .querySelector<HTMLButtonElement>('[data-testid="admin-creator-approve-dialog-confirm"]')!
      .click()
    await flushPromises()

    expect(adminCreatorsApi.approve).toHaveBeenCalledWith('01HQABCD', 'Welcome aboard!')
  })

  it('surfaces creator.already_approved without closing the approve dialog', async () => {
    vi.mocked(adminCreatorsApi.show).mockResolvedValue(envelope(buildCreator()))
    vi.mocked(adminCreatorsApi.approve).mockRejectedValue(
      new ApiError({ status: 409, code: 'creator.already_approved', message: 'no' }),
    )
    const h = await mountCreatorPage(CreatorDetailPage)
    teardown = h.unmount
    await flushPromises()

    await h.wrapper.find('[data-testid="admin-creator-detail-approve"]').trigger('click')
    await flushPromises()
    document
      .querySelector<HTMLButtonElement>('[data-testid="admin-creator-approve-dialog-confirm"]')!
      .click()
    await flushPromises()

    const error = document.body.querySelector('[data-testid="admin-creator-approve-dialog-error"]')
    expect(error?.textContent?.trim()).toBe('This creator has already been approved.')
    expect(document.body.querySelector('[data-testid="admin-creator-approve-dialog"]')).toBeTruthy()
  })

  it('happy path: rejects the creator with a reason and shows the snackbar', async () => {
    vi.mocked(adminCreatorsApi.show).mockResolvedValue(envelope(buildCreator()))
    vi.mocked(adminCreatorsApi.reject).mockResolvedValue(
      envelope(buildCreator({ application_status: 'rejected' })),
    )
    const h = await mountCreatorPage(CreatorDetailPage)
    teardown = h.unmount
    await flushPromises()

    await h.wrapper.find('[data-testid="admin-creator-detail-reject"]').trigger('click')
    await flushPromises()

    const textarea = document.body.querySelector<HTMLTextAreaElement>(
      '[data-testid="admin-creator-reject-dialog-reason"] textarea',
    )!
    textarea.value = 'Insufficient evidence of identity.'
    textarea.dispatchEvent(new Event('input'))
    await flushPromises()

    document
      .querySelector<HTMLButtonElement>('[data-testid="admin-creator-reject-dialog-confirm"]')!
      .click()
    await flushPromises()

    expect(adminCreatorsApi.reject).toHaveBeenCalledWith(
      '01HQABCD',
      'Insufficient evidence of identity.',
    )
    expect(h.wrapper.find('[data-testid="admin-creator-detail-reject"]').exists()).toBe(false)
    const snackbar = document.body.querySelector(
      '[data-testid="admin-creator-detail-decision-snackbar"]',
    )
    expect(snackbar?.textContent).toContain('Creator rejected.')
  })

  it('forwards reason when editing a reason-required field (bio)', async () => {
    vi.mocked(adminCreatorsApi.show).mockResolvedValue(envelope(buildCreator()))
    vi.mocked(adminCreatorsApi.updateField).mockResolvedValue(
      envelope(buildCreator({ bio: 'new bio' })),
    )

    const h = await mountCreatorPage(CreatorDetailPage)
    teardown = h.unmount
    await flushPromises()

    await h.wrapper.find('[data-testid="admin-creator-detail-row-bio-edit"]').trigger('click')
    await flushPromises()

    const textarea = document.body.querySelector<HTMLTextAreaElement>(
      '[data-testid="admin-creator-edit-modal-textarea"] textarea',
    )!
    textarea.value = 'new bio'
    textarea.dispatchEvent(new Event('input'))

    const reason = document.body.querySelector<HTMLTextAreaElement>(
      '[data-testid="admin-creator-edit-modal-reason"] textarea',
    )!
    reason.value = 'cleanup outdated copy'
    reason.dispatchEvent(new Event('input'))
    await flushPromises()

    const save = document.body.querySelector<HTMLButtonElement>(
      '[data-testid="admin-creator-edit-modal-save"]',
    )!
    save.click()
    await flushPromises()

    expect(adminCreatorsApi.updateField).toHaveBeenCalledWith(
      '01HQABCD',
      'bio',
      'new bio',
      'cleanup outdated copy',
    )
  })
})
