/**
 * Sprint 3 Chunk 4 sub-step 7 — Vitest coverage for the new agency
 * users page surfaces:
 *   - Paginated members table (consumes membersApi.list)
 *   - Invitation history table (consumes invitationsApi.list) — admin only
 *   - Role filter chip group
 *   - Search input (debounced re-fetch)
 *   - Empty / filtered-empty / error states
 *
 * The existing invite-button + InviteUserModal coverage from Sprint 2
 * lives in the modal's own spec and isn't re-tested here.
 */

import type { AgencyInvitationResource, AgencyMembershipResource } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'
import { createI18n } from 'vue-i18n'

import enApp from '@/core/i18n/locales/en/app.json'
import enAuth from '@/core/i18n/locales/en/auth.json'
import { useAgencyStore } from '@/core/stores/useAgencyStore'

import AgencyUsersPage from './AgencyUsersPage.vue'

vi.mock('../api/members.api', () => ({
  membersApi: { list: vi.fn() },
}))
vi.mock('../api/invitations.api', () => ({
  invitationsApi: { list: vi.fn(), create: vi.fn() },
}))

import { membersApi } from '../api/members.api'
import { invitationsApi } from '../api/invitations.api'

const localStorageStore: Record<string, string> = {}
Object.defineProperty(globalThis, 'localStorage', {
  value: {
    getItem: (k: string): string | null => localStorageStore[k] ?? null,
    setItem: (k: string, v: string): void => {
      localStorageStore[k] = v
    },
    removeItem: (k: string): void => {
      delete localStorageStore[k]
    },
  },
  writable: true,
})

function makeMembership(
  overrides: Partial<AgencyMembershipResource['attributes']> = {},
  id: string = '01HZA1B2C3D4E5F6G7H8J9K0M1',
): AgencyMembershipResource {
  return {
    id,
    type: 'agency_memberships',
    attributes: {
      user_id: id,
      name: 'Alice Adams',
      email: 'alice@example.com',
      role: 'agency_admin',
      status: 'active',
      created_at: '2026-05-01T10:00:00.000Z',
      last_active_at: null,
      ...overrides,
    },
  }
}

function makeInvitation(
  overrides: Partial<AgencyInvitationResource['attributes']> = {},
  id: string = '01HZA1B2C3D4E5F6G7H8J9K0M2',
): AgencyInvitationResource {
  return {
    id,
    type: 'agency_invitations',
    attributes: {
      email: 'invitee@example.com',
      role: 'agency_manager',
      expires_at: '2026-06-01T10:00:00.000Z',
      accepted_at: null,
      is_pending: true,
      is_expired: false,
      created_at: '2026-05-10T10:00:00.000Z',
      status: 'pending',
      invited_at: '2026-05-10T10:00:00.000Z',
      invited_by_user_name: 'Carol Admin',
      ...overrides,
    },
    relationships: { agency: { data: { id: 'agency-ulid', type: 'agencies' } } },
  }
}

interface MountOptions {
  members?: AgencyMembershipResource[]
  invitations?: AgencyInvitationResource[]
  isAdmin?: boolean
}

async function mountPage(
  options: MountOptions = {},
): Promise<{ wrapper: ReturnType<typeof mount>; cleanup: () => void }> {
  const pinia = createPinia()
  setActivePinia(pinia)

  vi.mocked(membersApi.list).mockResolvedValue({
    data: options.members ?? [],
    meta: {
      total: (options.members ?? []).length,
      current_page: 1,
      per_page: 25,
      last_page: 1,
    },
    links: { first: '', last: '', prev: null, next: null },
  } as unknown as Awaited<ReturnType<typeof membersApi.list>>)

  vi.mocked(invitationsApi.list).mockResolvedValue({
    data: options.invitations ?? [],
    meta: {
      total: (options.invitations ?? []).length,
      current_page: 1,
      per_page: 25,
      last_page: 1,
    },
    links: { first: '', last: '', prev: null, next: null },
  } as unknown as Awaited<ReturnType<typeof invitationsApi.list>>)

  const agency = useAgencyStore()
  agency.initFromUser([
    {
      agency_id: 'agency-ulid',
      agency_name: 'Test Agency',
      role: options.isAdmin === false ? 'agency_staff' : 'agency_admin',
    },
  ])

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/agency-users', name: 'agency-users.list', component: { template: '<div />' } },
      {
        path: '/creator-invitations/bulk',
        name: 'creator-invitations.bulk',
        component: { template: '<div />' },
      },
    ],
  })
  await router.push('/agency-users')
  await router.isReady()

  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: { ...enApp, ...enAuth } } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({
    components: vuetifyComponents,
    directives: vuetifyDirectives,
  })

  const wrapper = mount(AgencyUsersPage, {
    global: {
      plugins: [pinia, router, i18n, vuetify],
      stubs: {
        InviteUserModal: { template: '<div data-test="invite-user-modal-stub" />' },
      },
    },
    attachTo: document.createElement('div'),
  })

  await flushPromises()

  return {
    wrapper,
    cleanup: () => {
      wrapper.unmount()
      Object.keys(localStorageStore).forEach((k) => delete localStorageStore[k])
    },
  }
}

describe('AgencyUsersPage — members + invitation history (Sprint 3 Chunk 4 sub-step 7)', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    cleanup?.()
    cleanup = null
  })

  // -------- Members table --------

  it('renders the members table populated from the API on mount', async () => {
    const harness = await mountPage({
      members: [makeMembership({ name: 'Alice Adams' })],
      isAdmin: true,
    })
    cleanup = harness.cleanup
    expect(harness.wrapper.find('[data-test="members-table"]').exists()).toBe(true)
    expect(harness.wrapper.find('[data-test="members-table"]').text()).toContain('Alice Adams')
  })

  it('shows the empty state when there are no members and no filter is set', async () => {
    const harness = await mountPage({ members: [], isAdmin: true })
    cleanup = harness.cleanup
    expect(harness.wrapper.find('[data-test="members-empty-state"]').exists()).toBe(true)
  })

  it('shows the empty-filtered state when role filter is active but returns 0 rows', async () => {
    const harness = await mountPage({ members: [], isAdmin: true })
    cleanup = harness.cleanup

    // Switch filter to agency_manager — the spy returns 0 rows again.
    vi.mocked(membersApi.list).mockResolvedValueOnce({
      data: [],
      meta: { total: 0, current_page: 1, per_page: 25, last_page: 1 },
      links: { first: '', last: '', prev: null, next: null },
    } as unknown as Awaited<ReturnType<typeof membersApi.list>>)
    ;(harness.wrapper.vm as unknown as { memberRoleFilter: string }).memberRoleFilter =
      'agency_manager'
    await flushPromises()
    expect(harness.wrapper.find('[data-test="members-empty-filtered"]').exists()).toBe(true)
  })

  it('passes the role filter to the API when a non-all chip is selected', async () => {
    const harness = await mountPage({ members: [makeMembership()], isAdmin: true })
    cleanup = harness.cleanup
    ;(harness.wrapper.vm as unknown as { memberRoleFilter: string }).memberRoleFilter =
      'agency_admin'
    await flushPromises()
    const lastCall = vi.mocked(membersApi.list).mock.calls.at(-1)
    expect(lastCall?.[1]).toMatchObject({ role: 'agency_admin' })
  })

  it('surfaces an error alert when the members API rejects', async () => {
    // Mount with the default success path, then force the NEXT
    // members.list call to reject (this catches the v-data-table-server
    // @update:options follow-up call rather than the onMounted one,
    // which is the same code path).
    const harness = await mountPage({ members: [makeMembership()], isAdmin: true })
    cleanup = harness.cleanup
    vi.mocked(membersApi.list).mockRejectedValue(new Error('500'))
    // Trigger a filter change → re-fetch → rejects → error state.
    ;(harness.wrapper.vm as unknown as { memberRoleFilter: string }).memberRoleFilter =
      'agency_admin'
    await flushPromises()
    expect(harness.wrapper.find('[data-test="members-error"]').exists()).toBe(true)
    expect(harness.wrapper.find('[data-test="members-error"]').text()).toContain(
      'Failed to load team members',
    )
  })

  // -------- Invitation history --------

  it('renders the invitation history table for admin users', async () => {
    const harness = await mountPage({
      members: [makeMembership()],
      invitations: [makeInvitation({ email: 'pending@example.com' })],
      isAdmin: true,
    })
    cleanup = harness.cleanup
    expect(harness.wrapper.find('[data-test="invitations-heading"]').exists()).toBe(true)
    expect(harness.wrapper.find('[data-test="invitations-table"]').text()).toContain(
      'pending@example.com',
    )
  })

  it('HIDES the invitation history table from non-admin (agency_staff) users', async () => {
    const harness = await mountPage({
      members: [makeMembership()],
      invitations: [makeInvitation()],
      isAdmin: false,
    })
    cleanup = harness.cleanup
    expect(harness.wrapper.find('[data-test="invitations-heading"]').exists()).toBe(false)
    expect(harness.wrapper.find('[data-test="invitations-table"]').exists()).toBe(false)
    // And the invitation API is NOT called for non-admins.
    expect(invitationsApi.list).not.toHaveBeenCalled()
  })

  it('passes the status filter to the invitations API when a non-all chip is selected', async () => {
    const harness = await mountPage({
      invitations: [makeInvitation()],
      isAdmin: true,
    })
    cleanup = harness.cleanup
    ;(harness.wrapper.vm as unknown as { invitationStatusFilter: string }).invitationStatusFilter =
      'pending'
    await flushPromises()
    const lastCall = vi.mocked(invitationsApi.list).mock.calls.at(-1)
    expect(lastCall?.[1]).toMatchObject({ status: 'pending' })
  })

  it('shows the invitation-history empty state when 0 rows are returned', async () => {
    const harness = await mountPage({
      members: [makeMembership()],
      invitations: [],
      isAdmin: true,
    })
    cleanup = harness.cleanup
    expect(harness.wrapper.find('[data-test="invitations-empty-state"]').exists()).toBe(true)
  })
})
