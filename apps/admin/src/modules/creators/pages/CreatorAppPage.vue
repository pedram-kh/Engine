<script setup lang="ts">
/**
 * Admin "Creator app" usage list (AH-102b, Half B).
 *
 * One row per creator: Active / Signed out / Never signed in, platforms, last
 * seen, app version and push state. Derived entirely from data that already
 * exists (creator-app tokens, audited app logins, push registrations) — the
 * page writes nothing.
 *
 * Patterned on AllCreatorsPage (chip groups + `v-data-table-server`), with a
 * debounced search. Any filter or search change resets paging to page 1. A row
 * opens the sign-ins dialog.
 */

import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { ApiError, formatDateTime } from '@catalyst/api-client'

import { creatorEmail, creatorLabel } from '@/core/creators/creatorLabel'
import DeletionRequestedChip from '@/core/creators/DeletionRequestedChip.vue'

import {
  adminCreatorAppApi,
  type AdminCreatorAppUser,
  type CreatorAppPlatform,
  type CreatorAppPushFilter,
  type CreatorAppStatus,
} from '../api/creatorApp.api'
import CreatorAppSignInsDialog from '../components/CreatorAppSignInsDialog.vue'

type StatusFilter = CreatorAppStatus | 'all'
type PlatformFilter = CreatorAppPlatform | 'all'
type PushFilter = CreatorAppPushFilter | 'all'

const SEARCH_DEBOUNCE_MS = 300

const { t, te, locale } = useI18n()

const statusFilter = ref<StatusFilter>('all')
const platformFilter = ref<PlatformFilter>('all')
const pushFilter = ref<PushFilter>('all')
const search = ref('')

const items = ref<AdminCreatorAppUser[]>([])
const totalItems = ref(0)
const loading = ref(false)
const errorKey = ref<string | null>(null)
const tableOptions = ref({ page: 1, itemsPerPage: 25 })

// Unknown / transport error codes have no translation: fall back to the page's
// own message instead of rendering the raw key.
const errorText = computed(() =>
  errorKey.value !== null && te(errorKey.value)
    ? t(errorKey.value)
    : t('admin.creators.app.load_failed'),
)

const dialogOpen = ref(false)
const dialogCreator = ref<{ ulid: string; name: string | null } | null>(null)

let requestSeq = 0
let searchTimer: ReturnType<typeof setTimeout> | null = null

const STATUS_VALUES: StatusFilter[] = ['all', 'active', 'signed_out', 'never']
const PLATFORM_VALUES: PlatformFilter[] = ['all', 'ios', 'android']
const PUSH_VALUES: PushFilter[] = ['all', 'registered', 'dead', 'none']

const headers = [
  { title: t('admin.creators.app.columns.creator'), key: 'creator', sortable: false },
  { title: t('admin.creators.app.columns.status'), key: 'status', sortable: false },
  { title: t('admin.creators.app.columns.sign_ins'), key: 'sign_ins', sortable: false },
  { title: t('admin.creators.app.columns.platforms'), key: 'platforms', sortable: false },
  { title: t('admin.creators.app.columns.last_seen'), key: 'last_seen', sortable: false },
  { title: t('admin.creators.app.columns.last_sign_in'), key: 'last_sign_in', sortable: false },
  { title: t('admin.creators.app.columns.app_version'), key: 'app_version', sortable: false },
  { title: t('admin.creators.app.columns.push'), key: 'push', sortable: false },
  { title: '', key: 'actions', sortable: false, width: 60, align: 'end' as const },
]

async function load(): Promise<void> {
  const seq = ++requestSeq
  loading.value = true
  errorKey.value = null
  try {
    const res = await adminCreatorAppApi.users({
      app_status: statusFilter.value === 'all' ? undefined : statusFilter.value,
      platform: platformFilter.value === 'all' ? undefined : platformFilter.value,
      push: pushFilter.value === 'all' ? undefined : pushFilter.value,
      search: search.value.trim() === '' ? undefined : search.value,
      page: tableOptions.value.page,
      per_page: tableOptions.value.itemsPerPage,
    })
    if (seq !== requestSeq) return
    items.value = res.data
    totalItems.value = res.meta.total
  } catch (error) {
    if (seq !== requestSeq) return
    errorKey.value = error instanceof ApiError ? error.code : 'admin.creators.app.load_failed'
  } finally {
    if (seq === requestSeq) loading.value = false
  }
}

onMounted(() => {
  void load()
})

onBeforeUnmount(() => {
  if (searchTimer !== null) clearTimeout(searchTimer)
})

watch([statusFilter, platformFilter, pushFilter], () => {
  tableOptions.value.page = 1
  void load()
})

// One request per pause in typing, not one per keystroke.
watch(search, () => {
  if (searchTimer !== null) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    searchTimer = null
    tableOptions.value.page = 1
    void load()
  }, SEARCH_DEBOUNCE_MS)
})

function onTableUpdate(opts: { page: number; itemsPerPage: number }): void {
  // Vuetify echoes our own `page`/`items-per-page` props back through this
  // event (on mount and after a filter resets the page) — only a REAL change
  // is a new request, otherwise every filter click would fetch twice.
  const changed =
    opts.page !== tableOptions.value.page || opts.itemsPerPage !== tableOptions.value.itemsPerPage
  tableOptions.value = { page: opts.page, itemsPerPage: opts.itemsPerPage }
  if (changed) void load()
}

function openSignIns(row: AdminCreatorAppUser): void {
  dialogCreator.value = { ulid: row.id, name: creatorLabel(row.attributes.creator, t) }
  dialogOpen.value = true
}

function when(iso: string | null): string {
  return iso === null ? '—' : formatDateTime(iso, locale.value)
}

function statusColor(status: CreatorAppStatus): string | undefined {
  if (status === 'active') return 'success'
  return undefined
}
</script>

<template>
  <section data-testid="admin-creator-app">
    <header class="d-flex align-center justify-space-between mb-4">
      <h1 class="text-h5 ma-0">{{ t('admin.creators.app.title') }}</h1>
    </header>

    <div class="mb-2">
      <v-chip-group v-model="statusFilter" mandatory data-testid="admin-creator-app-filter-status">
        <v-chip
          v-for="value in STATUS_VALUES"
          :key="value"
          :value="value"
          filter
          variant="outlined"
          :data-testid="`admin-creator-app-filter-status-${value}`"
        >
          {{ t(`admin.creators.app.filters.status.${value}`) }}
        </v-chip>
      </v-chip-group>

      <v-chip-group
        v-model="platformFilter"
        mandatory
        data-testid="admin-creator-app-filter-platform"
      >
        <v-chip
          v-for="value in PLATFORM_VALUES"
          :key="value"
          :value="value"
          filter
          variant="outlined"
          :data-testid="`admin-creator-app-filter-platform-${value}`"
        >
          <template v-if="value === 'all'">{{
            t('admin.creators.app.filters.platform_all')
          }}</template>
          <template v-else>{{ value === 'ios' ? 'iOS' : 'Android' }}</template>
        </v-chip>
      </v-chip-group>

      <v-chip-group v-model="pushFilter" mandatory data-testid="admin-creator-app-filter-push">
        <v-chip
          v-for="value in PUSH_VALUES"
          :key="value"
          :value="value"
          filter
          variant="outlined"
          :data-testid="`admin-creator-app-filter-push-${value}`"
        >
          {{ t(`admin.creators.app.filters.push.${value}`) }}
        </v-chip>
      </v-chip-group>
    </div>

    <v-text-field
      v-model="search"
      :label="t('admin.creators.app.filters.search')"
      prepend-inner-icon="mdi-magnify"
      density="compact"
      variant="outlined"
      hide-details
      clearable
      class="mb-4"
      style="max-width: 360px"
      data-testid="admin-creator-app-search"
    />

    <v-alert
      v-if="errorKey"
      type="error"
      variant="tonal"
      class="mb-4"
      data-testid="admin-creator-app-error"
    >
      {{ errorText }}
    </v-alert>

    <v-data-table-server
      :headers="headers"
      :items="items"
      :items-length="totalItems"
      :loading="loading"
      :items-per-page="tableOptions.itemsPerPage"
      :page="tableOptions.page"
      item-value="id"
      :no-data-text="errorKey ? '' : t('admin.creators.app.empty')"
      data-testid="admin-creator-app-table"
      @update:options="onTableUpdate"
    >
      <template #item.creator="{ item }">
        <div>
          {{ creatorLabel(item.attributes.creator, t, '—') }}
          <DeletionRequestedChip :account="item.attributes.creator" />
        </div>
        <div class="text-caption text-medium-emphasis">
          {{ creatorEmail(item.attributes.creator) ?? '' }}
        </div>
      </template>

      <template #item.status="{ item }">
        <v-chip
          size="small"
          variant="tonal"
          :color="statusColor(item.attributes.app_status)"
          :data-testid="`admin-creator-app-status-${item.id}`"
        >
          {{ t(`admin.creators.app.filters.status.${item.attributes.app_status}`) }}
        </v-chip>
      </template>

      <template #item.sign_ins="{ item }">
        {{ item.attributes.live_sign_ins }}
      </template>

      <template #item.platforms="{ item }">
        <template v-if="item.attributes.platforms.length === 0">—</template>
        <v-chip
          v-for="p in item.attributes.platforms"
          :key="p"
          size="x-small"
          variant="outlined"
          class="mr-1"
        >
          {{ p === 'ios' ? 'iOS' : 'Android' }}
        </v-chip>
      </template>

      <template #item.last_seen="{ item }">{{ when(item.attributes.last_seen_at) }}</template>

      <template #item.last_sign_in="{ item }">
        {{ when(item.attributes.last_app_sign_in_at) }}
      </template>

      <template #item.app_version="{ item }">{{ item.attributes.app_version ?? '—' }}</template>

      <template #item.push="{ item }">
        <div :data-testid="`admin-creator-app-push-${item.id}`">
          {{ t(`admin.creators.app.filters.push.${item.attributes.push_state}`) }}
        </div>
        <div
          v-if="item.attributes.push_invalidation_reason"
          class="text-caption text-medium-emphasis"
        >
          {{ item.attributes.push_invalidation_reason }}
        </div>
      </template>

      <template #item.actions="{ item }">
        <v-btn
          icon="mdi-eye-outline"
          size="small"
          variant="text"
          :aria-label="t('admin.creators.app.view_sign_ins')"
          :data-testid="`admin-creator-app-view-${item.id}`"
          @click="openSignIns(item)"
        />
      </template>
    </v-data-table-server>

    <CreatorAppSignInsDialog
      v-model="dialogOpen"
      :creator-ulid="dialogCreator?.ulid ?? null"
      :creator-name="dialogCreator?.name ?? null"
    />
  </section>
</template>
