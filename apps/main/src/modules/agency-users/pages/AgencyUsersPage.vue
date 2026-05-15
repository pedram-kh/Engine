<script setup lang="ts">
/**
 * Agency users page — Sprint 3 Chunk 4 sub-step 7.
 *
 * Replaces the Sprint 2 placeholder which rendered the bootstrapped
 * user's own agency_memberships (i.e. "agencies I belong to"), NOT
 * the current agency's members. The page now consumes two new
 * paginated endpoints:
 *
 *   GET /api/v1/agencies/{agency}/members      — listed for any member
 *   GET /api/v1/agencies/{agency}/invitations  — admin-only history
 *
 * Layout follows the BrandListPage v-data-table-server precedent:
 *
 *   - Filter chip group (members: role; invitations: status)
 *   - v-data-table-server with page / per-page / sort wiring
 *   - Empty state for "no rows" and "no rows matching filter"
 *
 * Existing surfaces preserved:
 *   - "Invite user" button + InviteUserModal (Sprint 2 chunk 1)
 *   - Success toast on invite (Sprint 2 chunk 1)
 *
 * Visibility:
 *   - Members table: any agency member can read.
 *   - Invitations table + Invite button: agency_admin only.
 *
 * The route guard (`requireAuth` → `requireMfaEnrolled` →
 * `requireAgencyAdmin`) already enforces admin entry, so the
 * admin-only UI gates here are belt-and-suspenders (#40 defence in
 * depth — a future change that opens the route to non-admins still
 * keeps the management surfaces hidden).
 */

import type {
  AgencyInvitationResource,
  AgencyInvitationStatus,
  AgencyMembershipResource,
  AgencyRole,
} from '@catalyst/api-client'
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { invitationsApi } from '../api/invitations.api'
import { membersApi } from '../api/members.api'
import InviteUserModal from '../components/InviteUserModal.vue'

const { t } = useI18n()
const agencyStore = useAgencyStore()

// ---------------------------------------------------------------------------
// Members table state
// ---------------------------------------------------------------------------
type RoleFilter = AgencyRole | 'all'

const memberItems = ref<AgencyMembershipResource[]>([])
const memberTotal = ref(0)
const memberLoading = ref(false)
const memberError = ref<string | null>(null)
const memberRoleFilter = ref<RoleFilter>('all')
const memberSearch = ref('')
const memberTableOptions = ref({ page: 1, itemsPerPage: 25 })

const memberHeaders = [
  { title: t('app.agencyUsers.columns.name'), key: 'attributes.name', sortable: false },
  { title: t('app.agencyUsers.columns.email'), key: 'attributes.email', sortable: false },
  { title: t('app.agencyUsers.columns.role'), key: 'attributes.role', sortable: false },
  { title: t('app.agencyUsers.columns.status'), key: 'attributes.status', sortable: false },
  { title: t('app.agencyUsers.columns.joinedAt'), key: 'attributes.created_at', sortable: false },
]

const memberRoleFilterItems: { label: string; value: RoleFilter }[] = [
  { label: t('app.agencyUsers.filters.allRoles'), value: 'all' },
  { label: t('app.agencyUsers.roles.agency_admin'), value: 'agency_admin' },
  { label: t('app.agencyUsers.roles.agency_manager'), value: 'agency_manager' },
  { label: t('app.agencyUsers.roles.agency_staff'), value: 'agency_staff' },
]

async function loadMembers(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  memberLoading.value = true
  memberError.value = null

  try {
    const res = await membersApi.list(agencyId, {
      page: memberTableOptions.value.page,
      per_page: memberTableOptions.value.itemsPerPage,
      role: memberRoleFilter.value === 'all' ? undefined : memberRoleFilter.value,
      search: memberSearch.value.trim() === '' ? undefined : memberSearch.value,
    })
    memberItems.value = res.data
    memberTotal.value = res.meta.total
  } catch {
    memberError.value = t('app.agencyUsers.errors.membersLoadFailed')
  } finally {
    memberLoading.value = false
  }
}

function onMembersTableUpdate(opts: { page: number; itemsPerPage: number }): void {
  memberTableOptions.value = opts
  void loadMembers()
}

watch(memberRoleFilter, () => {
  memberTableOptions.value.page = 1
  void loadMembers()
})

// Debounced search re-fetch — fires 300ms after the user stops typing.
let searchTimer: ReturnType<typeof setTimeout> | null = null
watch(memberSearch, () => {
  if (searchTimer !== null) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    memberTableOptions.value.page = 1
    void loadMembers()
  }, 300)
})

// ---------------------------------------------------------------------------
// Invitation history state — admin only
// ---------------------------------------------------------------------------
type InvitationStatusFilter = AgencyInvitationStatus | 'all'

const invitationItems = ref<AgencyInvitationResource[]>([])
const invitationTotal = ref(0)
const invitationLoading = ref(false)
const invitationError = ref<string | null>(null)
const invitationStatusFilter = ref<InvitationStatusFilter>('all')
const invitationTableOptions = ref({ page: 1, itemsPerPage: 25 })

const invitationHeaders = [
  {
    title: t('app.agencyUsers.invitations.columns.email'),
    key: 'attributes.email',
    sortable: false,
  },
  { title: t('app.agencyUsers.columns.role'), key: 'attributes.role', sortable: false },
  { title: t('app.agencyUsers.columns.status'), key: 'attributes.status', sortable: false },
  {
    title: t('app.agencyUsers.invitations.columns.invitedAt'),
    key: 'attributes.invited_at',
    sortable: false,
  },
  {
    title: t('app.agencyUsers.invitations.columns.invitedBy'),
    key: 'attributes.invited_by_user_name',
    sortable: false,
  },
]

const invitationStatusFilterItems: { label: string; value: InvitationStatusFilter }[] = [
  { label: t('app.agencyUsers.filters.allStatuses'), value: 'all' },
  { label: t('app.agencyUsers.invitations.status.pending'), value: 'pending' },
  { label: t('app.agencyUsers.invitations.status.accepted'), value: 'accepted' },
  { label: t('app.agencyUsers.invitations.status.expired'), value: 'expired' },
]

async function loadInvitations(): Promise<void> {
  if (!agencyStore.isAdmin) return
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  invitationLoading.value = true
  invitationError.value = null

  try {
    const res = await invitationsApi.list(agencyId, {
      page: invitationTableOptions.value.page,
      per_page: invitationTableOptions.value.itemsPerPage,
      status: invitationStatusFilter.value === 'all' ? undefined : invitationStatusFilter.value,
    })
    invitationItems.value = res.data
    invitationTotal.value = res.meta.total
  } catch {
    invitationError.value = t('app.agencyUsers.errors.invitationsLoadFailed')
  } finally {
    invitationLoading.value = false
  }
}

function onInvitationsTableUpdate(opts: { page: number; itemsPerPage: number }): void {
  invitationTableOptions.value = opts
  void loadInvitations()
}

watch(invitationStatusFilter, () => {
  invitationTableOptions.value.page = 1
  void loadInvitations()
})

// ---------------------------------------------------------------------------
// Initial load + agency-switch re-fetch
// ---------------------------------------------------------------------------
onMounted(() => {
  void loadMembers()
  void loadInvitations()
})

watch(
  () => agencyStore.currentAgencyId,
  (id) => {
    if (id !== null) {
      void loadMembers()
      void loadInvitations()
    }
  },
)

// ---------------------------------------------------------------------------
// Invite flow (existing)
// ---------------------------------------------------------------------------
const inviteModalOpen = ref(false)
const successMessage = ref<string | null>(null)

function onInvited(email: string): void {
  successMessage.value = t('app.agencyUsers.invite.success', { email })
  setTimeout(() => {
    successMessage.value = null
  }, 5000)
  // Refresh the invitation history so the new pending invite surfaces
  // immediately.
  void loadInvitations()
}

function formatDate(iso: string | null): string {
  return iso !== null ? new Date(iso).toLocaleDateString() : '—'
}
</script>

<template>
  <div data-test="agency-users-page">
    <div class="d-flex align-center justify-space-between mb-6">
      <h1 class="text-h5 ma-0" data-test="agency-users-heading">
        {{ t('app.agencyUsers.title') }}
      </h1>
      <div v-if="agencyStore.isAdmin" class="d-flex ga-2">
        <v-btn
          variant="outlined"
          prepend-icon="mdi-account-multiple-plus"
          data-test="bulk-invite-creators-btn"
          :to="{ name: 'creator-invitations.bulk' }"
        >
          {{ t('app.agencyUsers.bulkInviteCreators.button') }}
        </v-btn>
        <v-btn
          color="primary"
          prepend-icon="mdi-account-plus"
          data-test="invite-user-btn"
          @click="inviteModalOpen = true"
        >
          {{ t('app.agencyUsers.invite.button') }}
        </v-btn>
      </div>
    </div>

    <v-alert
      v-if="successMessage"
      type="success"
      variant="tonal"
      closable
      class="mb-4"
      data-test="invite-success-alert"
      @click:close="successMessage = null"
    >
      {{ successMessage }}
    </v-alert>

    <!-- ─── Members section ─────────────────────────────────────── -->
    <h2 class="text-subtitle-1 font-weight-semibold mb-3" data-test="members-heading">
      {{ t('app.agencyUsers.members.heading') }}
    </h2>

    <div class="d-flex align-center ga-4 mb-3 flex-wrap">
      <v-chip-group v-model="memberRoleFilter" mandatory data-test="member-role-filter">
        <v-chip
          v-for="item in memberRoleFilterItems"
          :key="item.value"
          :value="item.value"
          filter
          variant="outlined"
          :data-test="`member-role-filter-${item.value}`"
        >
          {{ item.label }}
        </v-chip>
      </v-chip-group>

      <v-text-field
        v-model="memberSearch"
        :label="t('app.agencyUsers.search.label')"
        :placeholder="t('app.agencyUsers.search.placeholder')"
        prepend-inner-icon="mdi-magnify"
        density="compact"
        variant="outlined"
        hide-details
        clearable
        class="member-search"
        data-test="member-search"
      />
    </div>

    <v-alert v-if="memberError" type="error" variant="tonal" class="mb-4" data-test="members-error">
      {{ memberError }}
    </v-alert>

    <template v-if="memberLoading && memberItems.length === 0">
      <v-skeleton-loader type="table" data-test="members-skeleton" />
    </template>
    <template v-else-if="!memberLoading && memberItems.length === 0 && !memberError">
      <div
        v-if="memberRoleFilter === 'all' && memberSearch.trim() === ''"
        class="d-flex flex-column align-center pa-8"
        data-test="members-empty-state"
      >
        <v-icon icon="mdi-account-group-outline" size="48" color="medium-emphasis" class="mb-3" />
        <p class="text-body-2 text-medium-emphasis">{{ t('app.agencyUsers.empty.body') }}</p>
      </div>
      <div v-else class="d-flex flex-column align-center pa-8" data-test="members-empty-filtered">
        <v-icon icon="mdi-filter-remove-outline" size="48" color="medium-emphasis" class="mb-3" />
        <p class="text-body-2 text-medium-emphasis">
          {{ t('app.agencyUsers.emptyFiltered') }}
        </p>
      </div>
    </template>

    <v-data-table-server
      v-else
      :headers="memberHeaders"
      :items="memberItems"
      :items-length="memberTotal"
      :loading="memberLoading"
      :items-per-page="memberTableOptions.itemsPerPage"
      :page="memberTableOptions.page"
      item-value="id"
      class="mb-8"
      data-test="members-table"
      @update:options="onMembersTableUpdate"
    >
      <template #item.attributes.role="{ item }">
        {{ t(`app.agencyUsers.roles.${item.attributes.role}`) }}
      </template>
      <template #item.attributes.status="{ item }">
        <v-chip
          :color="item.attributes.status === 'active' ? 'success' : 'warning'"
          size="small"
          variant="tonal"
          :data-test="`member-status-${item.id}`"
        >
          {{ t(`app.agencyUsers.status.${item.attributes.status}`) }}
        </v-chip>
      </template>
      <template #item.attributes.created_at="{ item }">
        {{ formatDate(item.attributes.created_at) }}
      </template>
    </v-data-table-server>

    <!-- ─── Invitation history (admin-only) ────────────────────── -->
    <template v-if="agencyStore.isAdmin">
      <h2 class="text-subtitle-1 font-weight-semibold mb-3" data-test="invitations-heading">
        {{ t('app.agencyUsers.invitations.heading') }}
      </h2>

      <v-chip-group
        v-model="invitationStatusFilter"
        mandatory
        class="mb-3"
        data-test="invitation-status-filter"
      >
        <v-chip
          v-for="item in invitationStatusFilterItems"
          :key="item.value"
          :value="item.value"
          filter
          variant="outlined"
          :data-test="`invitation-status-filter-${item.value}`"
        >
          {{ item.label }}
        </v-chip>
      </v-chip-group>

      <v-alert
        v-if="invitationError"
        type="error"
        variant="tonal"
        class="mb-4"
        data-test="invitations-error"
      >
        {{ invitationError }}
      </v-alert>

      <template v-if="invitationLoading && invitationItems.length === 0">
        <v-skeleton-loader type="table" data-test="invitations-skeleton" />
      </template>
      <template v-else-if="!invitationLoading && invitationItems.length === 0 && !invitationError">
        <div class="d-flex flex-column align-center pa-8" data-test="invitations-empty-state">
          <v-icon icon="mdi-email-outline" size="48" color="medium-emphasis" class="mb-3" />
          <p class="text-body-2 text-medium-emphasis">
            {{ t('app.agencyUsers.invitations.empty') }}
          </p>
        </div>
      </template>

      <v-data-table-server
        v-else
        :headers="invitationHeaders"
        :items="invitationItems"
        :items-length="invitationTotal"
        :loading="invitationLoading"
        :items-per-page="invitationTableOptions.itemsPerPage"
        :page="invitationTableOptions.page"
        item-value="id"
        data-test="invitations-table"
        @update:options="onInvitationsTableUpdate"
      >
        <template #item.attributes.role="{ item }">
          {{ t(`app.agencyUsers.roles.${item.attributes.role}`) }}
        </template>
        <template #item.attributes.status="{ item }">
          <v-chip
            :color="
              item.attributes.status === 'accepted'
                ? 'success'
                : item.attributes.status === 'pending'
                  ? 'warning'
                  : 'default'
            "
            size="small"
            variant="tonal"
            :data-test="`invitation-status-${item.id}`"
          >
            {{ t(`app.agencyUsers.invitations.status.${item.attributes.status}`) }}
          </v-chip>
        </template>
        <template #item.attributes.invited_at="{ item }">
          {{ formatDate(item.attributes.invited_at) }}
        </template>
        <template #item.attributes.invited_by_user_name="{ item }">
          {{ item.attributes.invited_by_user_name ?? '—' }}
        </template>
      </v-data-table-server>
    </template>

    <InviteUserModal v-model="inviteModalOpen" @invited="onInvited" />
  </div>
</template>

<style scoped>
.member-search {
  min-width: 240px;
  max-width: 320px;
}
</style>
