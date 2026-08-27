<script setup lang="ts">
/**
 * AgencyMembersCard — the members drill-in on the agency detail page (AH-087).
 *
 * A THIRD stacked card rather than a tab: `v-tabs` appears nowhere in this
 * SPA, and every admin drill-in is stacked outlined cards (this page already
 * has overview + suspension). Server-paginated clone of the agency-self
 * AgencyUsersPage table shape (role filter, search, name/email/role/status/
 * joined) plus the MFA column and the two per-member operations.
 *
 * The MFA column renders exactly three states from two booleans: Exempt wins
 * the chip (with the reason and, when both are true, "also enrolled" in the
 * tooltip), otherwise Enrolled / Not enrolled. No secret material reaches this
 * component — the payload carries booleans and timestamps only.
 *
 * Every action re-renders the row from the server's response, so what the
 * table shows is always what the backend just committed.
 */

import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { ApiError, formatDateTime } from '@catalyst/api-client'

import {
  adminAgenciesApi,
  type AdminAgencyMember,
  type AdminAgencyMemberListParams,
} from '../api/agencies.api'
import MemberMfaActionDialog, { type MemberMfaOperation } from './MemberMfaActionDialog.vue'

defineOptions({ name: 'AgencyMembersCard' })

const props = defineProps<{ agencyUlid: string }>()

const { t, locale } = useI18n()

const items = ref<AdminAgencyMember[]>([])
const totalItems = ref(0)
const loading = ref(false)
const errorKey = ref<string | null>(null)
const search = ref('')
const roleFilter = ref<string>('all')
const tableOptions = ref({ page: 1, itemsPerPage: 25 })

const dialogOpen = ref(false)
const dialogOperation = ref<MemberMfaOperation>('reset')
const dialogMember = ref<AdminAgencyMember | null>(null)
const actionPending = ref(false)
const actionErrorKey = ref<string | null>(null)

const emit = defineEmits<{
  (e: 'acted', payload: { messageKey: string; color: string }): void
}>()

// The three role options reuse the same `roles.*` strings the table cell
// renders, so a role reads identically in the filter and in the row.
const roleFilterItems = [
  { label: t('admin.agencies.members.filters.all'), value: 'all' },
  ...(['agency_admin', 'agency_manager', 'agency_staff'] as const).map((role) => ({
    label: t(`admin.agencies.members.roles.${role}`),
    value: role,
  })),
]

const headers = [
  { title: t('admin.agencies.members.fields.name'), key: 'attributes.name', sortable: false },
  { title: t('admin.agencies.members.fields.email'), key: 'attributes.email', sortable: false },
  {
    title: t('admin.agencies.members.fields.role'),
    key: 'attributes.role',
    sortable: false,
    width: 150,
  },
  {
    title: t('admin.agencies.members.fields.status'),
    key: 'attributes.status',
    sortable: false,
    width: 110,
  },
  {
    title: t('admin.agencies.members.fields.joined'),
    key: 'attributes.joined_at',
    sortable: false,
    width: 140,
  },
  {
    title: t('admin.agencies.members.fields.mfa'),
    key: 'mfa',
    sortable: false,
    width: 150,
  },
  { title: '', key: 'actions', sortable: false, width: 60, align: 'end' as const },
]

async function load(): Promise<void> {
  loading.value = true
  errorKey.value = null
  try {
    // Vuetify's `clearable` text field sets the model to `null` (not '')
    // when the clear button is pressed, so coerce before trimming.
    const trimmedSearch = (search.value ?? '').trim()
    const params: AdminAgencyMemberListParams = {
      role: roleFilter.value === 'all' ? undefined : roleFilter.value,
      search: trimmedSearch === '' ? undefined : trimmedSearch,
      page: tableOptions.value.page,
      per_page: tableOptions.value.itemsPerPage,
    }
    const res = await adminAgenciesApi.members(props.agencyUlid, params)
    items.value = res.data
    totalItems.value = res.meta.total
  } catch (error) {
    errorKey.value = error instanceof ApiError ? error.code : 'admin.agencies.members.load_failed'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void load()
})

watch(roleFilter, () => {
  tableOptions.value.page = 1
  void load()
})

let searchDebounce: ReturnType<typeof setTimeout> | null = null
watch(search, () => {
  if (searchDebounce !== null) clearTimeout(searchDebounce)
  searchDebounce = setTimeout(() => {
    tableOptions.value.page = 1
    void load()
  }, 300)
})

function onTableUpdate(opts: { page: number; itemsPerPage: number }): void {
  tableOptions.value = opts
  void load()
}

function openDialog(member: AdminAgencyMember, operation: MemberMfaOperation): void {
  dialogMember.value = member
  dialogOperation.value = operation
  actionErrorKey.value = null
  dialogOpen.value = true
}

async function onConfirm(payload: { reason: string }): Promise<void> {
  const member = dialogMember.value
  if (member === null) return

  actionPending.value = true
  actionErrorKey.value = null
  const operation = dialogOperation.value

  try {
    const res = await runOperation(member.id, operation, payload.reason)
    replaceRow(res.data)
    dialogOpen.value = false
    emit('acted', {
      messageKey: `admin.agencies.members.actions.${operation}.success`,
      color: 'success',
    })
  } catch (error) {
    // The backend's semantic codes (mfa.nothing_to_reset, mfa.already_exempt,
    // mfa.not_exempt, mfa.platform_admin_not_exemptable) are surfaced in the
    // dialog rather than as a toast: they all mean "your assumption about this
    // row was wrong", which is worth reading before the dialog closes.
    actionErrorKey.value = resolveErrorKey(error, operation)
  } finally {
    actionPending.value = false
  }
}

function runOperation(userUlid: string, operation: MemberMfaOperation, reason: string) {
  if (operation === 'reset') {
    return adminAgenciesApi.resetMemberTwoFactor(props.agencyUlid, userUlid, reason)
  }
  if (operation === 'exempt') {
    return adminAgenciesApi.exemptMemberFromTwoFactor(props.agencyUlid, userUlid, reason)
  }
  return adminAgenciesApi.removeMemberTwoFactorExemption(props.agencyUlid, userUlid, reason)
}

const KNOWN_ERROR_CODES = new Set([
  'mfa.nothing_to_reset',
  'mfa.already_exempt',
  'mfa.not_exempt',
  'mfa.platform_admin_not_exemptable',
])

function resolveErrorKey(error: unknown, operation: MemberMfaOperation): string {
  if (error instanceof ApiError && KNOWN_ERROR_CODES.has(error.code)) {
    return `admin.agencies.members.errors.${error.code.replace('mfa.', '')}`
  }
  return `admin.agencies.members.actions.${operation}.failed`
}

function replaceRow(member: AdminAgencyMember): void {
  items.value = items.value.map((row) => (row.id === member.id ? member : row))
}

function mfaChipColor(member: AdminAgencyMember): string {
  if (member.attributes.is_mfa_exempt) return 'warning'
  return member.attributes.is_mfa_enrolled ? 'success' : 'default'
}

function mfaChipLabel(member: AdminAgencyMember): string {
  if (member.attributes.is_mfa_exempt) return t('admin.agencies.members.mfa_labels.exempt')
  return member.attributes.is_mfa_enrolled
    ? t('admin.agencies.members.mfa_labels.enrolled')
    : t('admin.agencies.members.mfa_labels.not_enrolled')
}

/**
 * The exempt chip's tooltip carries what the chip itself cannot: when the
 * exemption was granted, why, and — when the member is ALSO enrolled — that
 * their authenticator still works and they are still challenged at sign-in.
 */
function mfaTooltip(member: AdminAgencyMember): string | null {
  if (!member.attributes.is_mfa_exempt) return null

  const parts = [
    t('admin.agencies.members.mfa_tooltip.exempt_since', {
      date: formatTimestamp(member.attributes.mfa_exempted_at),
    }),
  ]

  if (member.attributes.is_mfa_enrolled) {
    parts.push(t('admin.agencies.members.mfa_tooltip.also_enrolled'))
  }

  if (member.attributes.mfa_exemption_reason !== null) {
    parts.push(
      t('admin.agencies.members.mfa_tooltip.reason', {
        reason: member.attributes.mfa_exemption_reason,
      }),
    )
  }

  return parts.join(' ')
}

function formatTimestamp(iso: string | null): string {
  return formatDateTime(iso, locale.value, { dateStyle: 'medium' })
}
</script>

<template>
  <v-card variant="outlined" class="mt-4" data-testid="admin-agency-members-card">
    <v-card-title class="text-subtitle-1">
      {{ t('admin.agencies.members.heading') }}
    </v-card-title>

    <v-card-text>
      <p class="text-body-2 text-medium-emphasis mb-4">
        {{ t('admin.agencies.members.boundary_note') }}
      </p>

      <div class="d-flex align-center ga-4 mb-4 flex-wrap">
        <v-chip-group v-model="roleFilter" mandatory data-testid="admin-agency-members-role-filter">
          <v-chip
            v-for="item in roleFilterItems"
            :key="item.value"
            :value="item.value"
            filter
            variant="outlined"
            :data-testid="`admin-agency-members-role-filter-${item.value}`"
          >
            {{ item.label }}
          </v-chip>
        </v-chip-group>

        <v-text-field
          v-model="search"
          :label="t('admin.agencies.members.search_label')"
          density="compact"
          variant="outlined"
          hide-details
          clearable
          prepend-inner-icon="mdi-magnify"
          style="max-width: 280px"
          data-testid="admin-agency-members-search"
        />
      </div>

      <v-alert
        v-if="errorKey"
        type="error"
        variant="tonal"
        class="mb-4"
        data-testid="admin-agency-members-error"
      >
        {{ t(errorKey) }}
      </v-alert>

      <v-data-table-server
        :headers="headers"
        :items="items"
        :items-length="totalItems"
        :loading="loading"
        :items-per-page="tableOptions.itemsPerPage"
        :page="tableOptions.page"
        item-value="id"
        :no-data-text="t('admin.agencies.members.empty')"
        data-testid="admin-agency-members-table"
        @update:options="onTableUpdate"
      >
        <template #item.attributes.role="{ item }">
          {{ t(`admin.agencies.members.roles.${item.attributes.role}`) }}
        </template>

        <template #item.attributes.status="{ item }">
          <v-chip
            size="small"
            variant="tonal"
            :color="item.attributes.status === 'active' ? 'success' : 'default'"
            :data-testid="`admin-agency-members-status-${item.id}`"
          >
            {{ t(`admin.agencies.members.status_labels.${item.attributes.status}`) }}
          </v-chip>
        </template>

        <template #item.attributes.joined_at="{ item }">
          {{ formatTimestamp(item.attributes.joined_at) }}
        </template>

        <template #item.mfa="{ item }">
          <v-tooltip
            :text="mfaTooltip(item) ?? ''"
            :disabled="mfaTooltip(item) === null"
            location="top"
          >
            <template #activator="{ props: tooltipProps }">
              <v-chip
                v-bind="tooltipProps"
                size="small"
                variant="tonal"
                :color="mfaChipColor(item)"
                :prepend-icon="item.attributes.is_mfa_exempt ? 'mdi-shield-off-outline' : undefined"
                :data-testid="`admin-agency-members-mfa-${item.id}`"
              >
                {{ mfaChipLabel(item) }}
              </v-chip>
            </template>
          </v-tooltip>
        </template>

        <template #item.actions="{ item }">
          <v-menu location="bottom end">
            <template #activator="{ props: menuProps }">
              <v-btn
                v-bind="menuProps"
                icon="mdi-dots-vertical"
                size="small"
                variant="text"
                :aria-label="t('admin.agencies.members.actions.menu_label')"
                :data-testid="`admin-agency-members-menu-${item.id}`"
              />
            </template>
            <v-list density="compact">
              <v-list-item
                prepend-icon="mdi-lock-reset"
                :title="t('admin.agencies.members.actions.reset.menu')"
                :data-testid="`admin-agency-members-reset-${item.id}`"
                @click="openDialog(item, 'reset')"
              />
              <v-list-item
                v-if="!item.attributes.is_mfa_exempt"
                prepend-icon="mdi-shield-off-outline"
                :title="t('admin.agencies.members.actions.exempt.menu')"
                :data-testid="`admin-agency-members-exempt-${item.id}`"
                @click="openDialog(item, 'exempt')"
              />
              <v-list-item
                v-else
                prepend-icon="mdi-shield-check-outline"
                :title="t('admin.agencies.members.actions.remove_exemption.menu')"
                :data-testid="`admin-agency-members-remove-exemption-${item.id}`"
                @click="openDialog(item, 'remove_exemption')"
              />
            </v-list>
          </v-menu>
        </template>
      </v-data-table-server>
    </v-card-text>

    <MemberMfaActionDialog
      v-model="dialogOpen"
      :operation="dialogOperation"
      :member-name="dialogMember?.attributes.name ?? ''"
      :is-saving="actionPending"
      :error-key="actionErrorKey"
      @confirm="onConfirm"
    />
  </v-card>
</template>
