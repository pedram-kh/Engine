/**
 * AH-119 — `ThreadActionsMenu`: the "⋯" menu in the agency thread header.
 *
 * Pins the owner line, Reassign being admin / manager only, the dialog listing ACTIVE members
 * only, the confirm call (member ULID, not the membership id), `changed`, the failure path that
 * keeps the dialog open, and confirm being off while the selection is the current owner.
 * Menus and dialogs teleport to `document.body`.
 *
 * Break-revert: stop filtering `status === 'active'` → the active-only case fails; pass the
 * membership id instead of `user_id` → the confirm case fails; close the dialog in `catch` → the
 * failure case fails.
 */

import { ApiError } from '@catalyst/api-client'
import type { AgencyMembershipResource } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

vi.mock('@/modules/agency-users/api/members.api', () => ({
  membersApi: { list: vi.fn() },
}))
vi.mock('@/modules/messaging/api/messageInbox.api', () => ({
  messageInboxApi: { list: vi.fn(), reassignOwner: vi.fn() },
}))

import { membersApi } from '@/modules/agency-users/api/members.api'
import ThreadActionsMenu from '@/modules/agency/components/ThreadActionsMenu.vue'
import { messageInboxApi } from '@/modules/messaging/api/messageInbox.api'

function member(
  membershipId: string,
  userId: string,
  name: string,
  status: 'active' | 'pending',
): AgencyMembershipResource {
  return {
    id: membershipId,
    type: 'agency_memberships',
    attributes: {
      user_id: userId,
      name,
      email: `${userId}@example.com`,
      role: 'agency_manager',
      status,
      created_at: '2026-01-01T00:00:00Z',
      last_active_at: null,
    },
  }
}

const MEMBERS = [
  member('m-1', 'user-ana', 'Ana Active', 'active'),
  member('m-2', 'user-pete', 'Pete Pending', 'pending'),
  member('m-3', 'user-bo', 'Bo Active', 'active'),
]

function mountMenu(props: Record<string, unknown> = {}) {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  return mount(ThreadActionsMenu, {
    props: {
      agencyId: 'ag1',
      creatorUlid: 'cr1',
      owner: { id: 'user-ana', name: 'Ana Active' },
      canReassign: true,
      ...props,
    },
    global: { plugins: [i18n, vuetify] },
    attachTo: document.body,
  })
}

type W = ReturnType<typeof mountMenu>

function inBody<T extends HTMLElement = HTMLElement>(testId: string): T | null {
  return document.body.querySelector<T>(`[data-test="${testId}"]`)
}

async function openMenu(w: W): Promise<void> {
  await w.get('[data-test="thread-more"]').trigger('click')
  await flushPromises()
}

async function openDialog(w: W): Promise<void> {
  await openMenu(w)
  inBody('thread-reassign')!.click()
  await flushPromises()
}

function dialogOpen(w: W): boolean {
  return w.findComponent(vuetifyComponents.VDialog).props('modelValue') === true
}

function select(w: W) {
  return w.findComponent(vuetifyComponents.VSelect)
}

async function choose(w: W, userId: string): Promise<void> {
  select(w).vm.$emit('update:modelValue', userId)
  await flushPromises()
}

describe('ThreadActionsMenu', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(membersApi.list).mockResolvedValue({
      data: MEMBERS,
      meta: { total: 3, current_page: 1, per_page: 100, last_page: 1 },
    } as never)
    vi.mocked(messageInboxApi.reassignOwner).mockResolvedValue({})
  })
  afterEach(() => {
    document.body.innerHTML = ''
  })

  describe('menu', () => {
    it('has a labelled more button and no menu content until opened', () => {
      const w = mountMenu()
      expect(w.get('[data-test="thread-more"]').attributes('aria-label')).toBe('More actions')
      expect(inBody('thread-more-menu')).toBeNull()
      w.unmount()
    })

    it('shows "Owner: X" when there is an owner', async () => {
      const w = mountMenu()
      await openMenu(w)
      expect(inBody('thread-owner-line')?.textContent).toContain('Owner: Ana Active')
      w.unmount()
    })

    it('shows "No owner" when there is none', async () => {
      const w = mountMenu({ owner: null })
      await openMenu(w)
      expect(inBody('thread-owner-line')?.textContent).toContain('No owner')
      expect(inBody('thread-owner-line')?.textContent).not.toContain('Owner:')
      w.unmount()
    })

    it('offers Reassign owner only when canReassign', async () => {
      const yes = mountMenu({ canReassign: true })
      await openMenu(yes)
      expect(inBody('thread-reassign')?.textContent).toContain('Reassign owner')
      yes.unmount()
      document.body.innerHTML = ''

      const no = mountMenu({ canReassign: false })
      await openMenu(no)
      expect(inBody('thread-more-menu')).not.toBeNull()
      expect(inBody('thread-owner-line')).not.toBeNull()
      expect(inBody('thread-reassign')).toBeNull()
      no.unmount()
    })

    it('does not load members until Reassign is chosen', async () => {
      const w = mountMenu()
      await openMenu(w)
      expect(membersApi.list).not.toHaveBeenCalled()
      w.unmount()
    })
  })

  describe('reassign dialog', () => {
    it('loads the agency members and lists ACTIVE ones only, by user id', async () => {
      const w = mountMenu()
      await openDialog(w)

      expect(membersApi.list).toHaveBeenCalledWith('ag1', {
        per_page: 100,
        sort: 'name',
        order: 'asc',
      })
      expect(dialogOpen(w)).toBe(true)
      expect(select(w).props('items')).toEqual([
        { id: 'user-ana', name: 'Ana Active' },
        { id: 'user-bo', name: 'Bo Active' },
      ])
      w.unmount()
    })

    it('preselects the current owner', async () => {
      const w = mountMenu()
      await openDialog(w)
      expect(select(w).props('modelValue')).toBe('user-ana')
      w.unmount()
    })

    it('has nothing preselected when there is no owner', async () => {
      const w = mountMenu({ owner: null })
      await openDialog(w)
      expect(select(w).props('modelValue')).toBeNull()
      w.unmount()
    })

    it('Cancel closes the dialog without calling the API', async () => {
      const w = mountMenu()
      await openDialog(w)
      inBody('thread-reassign-cancel')!.click()
      await flushPromises()
      expect(dialogOpen(w)).toBe(false)
      expect(messageInboxApi.reassignOwner).not.toHaveBeenCalled()
      w.unmount()
    })
  })

  describe('confirm', () => {
    it('is disabled while the selection is the current owner', async () => {
      const w = mountMenu()
      await openDialog(w)
      expect(inBody<HTMLButtonElement>('thread-reassign-confirm')?.disabled).toBe(true)
      inBody('thread-reassign-confirm')!.click()
      await flushPromises()
      expect(messageInboxApi.reassignOwner).not.toHaveBeenCalled()
      w.unmount()
    })

    it('is disabled with no selection at all (no owner, nothing chosen)', async () => {
      const w = mountMenu({ owner: null })
      await openDialog(w)
      expect(inBody<HTMLButtonElement>('thread-reassign-confirm')?.disabled).toBe(true)
      w.unmount()
    })

    it('enables once another member is picked', async () => {
      const w = mountMenu()
      await openDialog(w)
      await choose(w, 'user-bo')
      expect(inBody<HTMLButtonElement>('thread-reassign-confirm')?.disabled).toBe(false)
      w.unmount()
    })

    it('calls reassignOwner with the agency, the creator and the member USER ULID', async () => {
      const w = mountMenu()
      await openDialog(w)
      await choose(w, 'user-bo')
      inBody('thread-reassign-confirm')!.click()
      await flushPromises()

      expect(messageInboxApi.reassignOwner).toHaveBeenCalledTimes(1)
      expect(messageInboxApi.reassignOwner).toHaveBeenCalledWith('ag1', 'cr1', 'user-bo')
      w.unmount()
    })

    it('emits changed with the new owner, closes the dialog and confirms with a snackbar', async () => {
      const w = mountMenu()
      await openDialog(w)
      await choose(w, 'user-bo')
      inBody('thread-reassign-confirm')!.click()
      await flushPromises()

      expect(w.emitted('changed')).toEqual([[{ id: 'user-bo', name: 'Bo Active' }]])
      expect(dialogOpen(w)).toBe(false)
      expect(document.body.textContent).toContain('Owner changed to Bo Active.')
      w.unmount()
    })

    it('with no owner, picking a member enables confirm and assigns them', async () => {
      const w = mountMenu({ owner: null })
      await openDialog(w)
      await choose(w, 'user-ana')
      inBody('thread-reassign-confirm')!.click()
      await flushPromises()
      expect(messageInboxApi.reassignOwner).toHaveBeenCalledWith('ag1', 'cr1', 'user-ana')
      expect(w.emitted('changed')).toEqual([[{ id: 'user-ana', name: 'Ana Active' }]])
      w.unmount()
    })
  })

  describe('failure', () => {
    it('shows the API message, keeps the dialog open and emits nothing', async () => {
      vi.mocked(messageInboxApi.reassignOwner).mockRejectedValue(
        new ApiError({
          status: 403,
          code: 'auth.forbidden',
          message: 'Only a manager may do that.',
        }),
      )
      const w = mountMenu()
      await openDialog(w)
      await choose(w, 'user-bo')
      inBody('thread-reassign-confirm')!.click()
      await flushPromises()

      expect(inBody('thread-reassign-error')?.textContent).toContain('Only a manager may do that.')
      expect(dialogOpen(w)).toBe(true)
      expect(w.emitted('changed')).toBeUndefined()
      w.unmount()
    })

    it('maps owner.not_a_member to its own localised line (AH-119 Stage B), not the raw server text', async () => {
      vi.mocked(messageInboxApi.reassignOwner).mockRejectedValue(
        new ApiError({
          status: 422,
          code: 'owner.not_a_member',
          message: 'raw server text',
        }),
      )
      const w = mountMenu()
      await openDialog(w)
      await choose(w, 'user-bo')
      inBody('thread-reassign-confirm')!.click()
      await flushPromises()

      const text = inBody('thread-reassign-error')?.textContent ?? ''
      expect(text).toContain('That person is not a member of this agency.')
      expect(text).not.toContain('raw server text')
      expect(dialogOpen(w)).toBe(true)
      w.unmount()
    })

    it('shows the generic error for a non-API failure, and a retry can succeed', async () => {
      vi.mocked(messageInboxApi.reassignOwner)
        .mockRejectedValueOnce(new Error('network'))
        .mockResolvedValueOnce({})
      const w = mountMenu()
      await openDialog(w)
      await choose(w, 'user-bo')
      inBody('thread-reassign-confirm')!.click()
      await flushPromises()
      expect(inBody('thread-reassign-error')?.textContent).toContain(
        "We couldn't change the owner.",
      )
      expect(dialogOpen(w)).toBe(true)

      inBody('thread-reassign-confirm')!.click()
      await flushPromises()
      expect(inBody('thread-reassign-error')).toBeNull()
      expect(dialogOpen(w)).toBe(false)
      expect(w.emitted('changed')).toEqual([[{ id: 'user-bo', name: 'Bo Active' }]])
      w.unmount()
    })

    it('shows the generic error when the member list fails to load', async () => {
      vi.mocked(membersApi.list).mockRejectedValue(new Error('boom'))
      const w = mountMenu()
      await openDialog(w)
      expect(inBody('thread-reassign-error')?.textContent).toContain(
        "We couldn't change the owner.",
      )
      expect(select(w).props('items')).toEqual([])
      w.unmount()
    })
  })
})
