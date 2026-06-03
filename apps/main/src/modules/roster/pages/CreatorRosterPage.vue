<script setup lang="ts">
/**
 * Agency creator roster ("my creators") — Sprint 4 Chunk 5 (D-c5-1).
 *
 * Server-side paginated table consuming GET /api/v1/agencies/{agency}/creators.
 * Lists the agency's relations across ALL relationship statuses
 * (roster / prospect / external) with the four backed filters: status
 * (chip-group) + country / language / category (selects). Tenancy via
 * `useAgencyStore().currentAgencyId`, mirroring BrandListPage.
 *
 * Sprint 6 Chunk 1 adds: a debounced name/bio search box (→ `?q=` FTS, D-1)
 * and two DISABLED filter affordances — metrics (follower range + engagement)
 * and availability (D-4). The affordances are present-but-inert: faded,
 * disabled, span-wrapped so their tooltip still fires on hover (a disabled
 * control emits no hover events), and they issue NO query — a "0 results"
 * from an empty-data query would read as broken. They're blocked by missing
 * infrastructure (social metrics are null until adapters land; a real
 * availability filter needs a cheap roster-wide signal — its own chunk, D-5),
 * not dead code.
 *
 * Still deferred: handle search (D-2), real metrics/availability filters,
 * talent pools, internal_rating editing (read-only stars here), and — rows do
 * NOT navigate to a creator detail (no agency-side detail surface yet → Chunk 2).
 */

import type {
  RosterCreatorListItem,
  RosterListParams,
  RosterRelationshipStatus,
} from '@catalyst/api-client'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { CEmptyState } from '@catalyst/ui'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { COUNTRY_OPTIONS } from '@/modules/onboarding/data/countries'
import { rosterApi } from '../api/roster.api'

const { t } = useI18n()

const agencyStore = useAgencyStore()

type StatusFilter = RosterRelationshipStatus | 'all'

const statusFilter = ref<StatusFilter>('all')
const countryFilter = ref<string | null>(null)
const languageFilter = ref<string | null>(null)
const categoryFilter = ref<string | null>(null)
const searchQuery = ref('')

// Disabled-affordance drivers (D-4). Both are static FE constants this chunk —
// there is no backend signal to drive them yet (no `kyc_vendor_available`
// equivalent). When the blocking infrastructure lands, flip these to a
// backend-driven flag and wire the real control behind the `v-else`:
//   - metrics (follower range + engagement): blocked by missing DATA —
//     `creator_social_accounts.metrics` is null until the social adapters land.
//   - availability: blocked by missing cheap-query INFRASTRUCTURE — there is no
//     stored availability status; a real filter needs a denormalized
//     roster-wide signal or N RRULE expansions per page (a design problem, D-5).
const METRICS_FILTERS_CONNECTED = false
const AVAILABILITY_FILTER_CONNECTED = false

const items = ref<RosterCreatorListItem[]>([])
const totalItems = ref(0)
const loading = ref(false)
const error = ref<string | null>(null)

const tableOptions = ref({ page: 1, itemsPerPage: 25 })

// Bounded filter option sets. Country reuses the shared launch-market
// picker; language + category mirror the wizard's bounded vocabularies
// (category labels reuse the canonical `creator.ui.wizard.categories.*`
// keys so they stay translated in all three locales without duplication).
const LANGUAGE_FILTER_CODES = ['en', 'pt', 'it', 'es', 'fr', 'de'] as const

const CATEGORY_FILTER_KEYS = [
  'fashion',
  'beauty',
  'lifestyle',
  'fitness',
  'travel',
  'food',
  'tech',
  'gaming',
  'music',
  'comedy',
  'education',
  'parenting',
  'business',
  'art',
  'sports',
  'other',
] as const

const statusFilterItems: { label: string; value: StatusFilter }[] = [
  { label: t('app.roster.filters.status.all'), value: 'all' },
  { label: t('app.roster.filters.status.roster'), value: 'roster' },
  { label: t('app.roster.filters.status.prospect'), value: 'prospect' },
  { label: t('app.roster.filters.status.external'), value: 'external' },
]

const countryFilterItems = computed(() =>
  COUNTRY_OPTIONS.map((c) => ({ title: c.label, value: c.code })),
)

const languageFilterItems = computed(() =>
  LANGUAGE_FILTER_CODES.map((code) => ({
    title: t(`app.roster.languages.${code}`),
    value: code,
  })),
)

const categoryFilterItems = computed(() =>
  CATEGORY_FILTER_KEYS.map((key) => ({
    title: t(`creator.ui.wizard.categories.${key}`),
    value: key,
  })),
)

const headers = [
  { title: t('app.roster.fields.name'), key: 'attributes.display_name', sortable: false },
  {
    title: t('app.roster.fields.status'),
    key: 'attributes.relationship_status',
    sortable: false,
    width: 130,
  },
  {
    title: t('app.roster.fields.applicationStatus'),
    key: 'attributes.application_status',
    sortable: false,
    width: 140,
  },
  {
    title: t('app.roster.fields.country'),
    key: 'attributes.country_code',
    sortable: false,
    width: 120,
  },
  {
    title: t('app.roster.fields.language'),
    key: 'attributes.primary_language',
    sortable: false,
    width: 120,
  },
  { title: t('app.roster.fields.categories'), key: 'attributes.categories', sortable: false },
  {
    title: t('app.roster.fields.rating'),
    key: 'attributes.internal_rating',
    sortable: false,
    width: 150,
  },
  {
    title: t('app.roster.fields.campaigns'),
    key: 'attributes.total_campaigns_completed',
    sortable: false,
    width: 120,
    align: 'end' as const,
  },
]

const hasActiveFilters = computed(
  () =>
    statusFilter.value !== 'all' ||
    countryFilter.value !== null ||
    languageFilter.value !== null ||
    categoryFilter.value !== null ||
    searchQuery.value.trim() !== '',
)

function countryLabel(code: string | null): string {
  if (code === null) return '—'
  return COUNTRY_OPTIONS.find((c) => c.code === code)?.label ?? code
}

function languageLabel(code: string | null): string {
  if (code === null) return '—'
  return (LANGUAGE_FILTER_CODES as readonly string[]).includes(code)
    ? t(`app.roster.languages.${code}`)
    : code
}

// Semantic colour per application state — deliberately a different visual
// register from the neutral tonal relationship-status chip (Chunk 5b: the
// two status axes must not read as interchangeable). approved=usable (green),
// pending=awaiting review (amber), rejected=declined (red), incomplete=
// not-yet-submitted (neutral grey).
type RosterApplicationStatus = RosterCreatorListItem['attributes']['application_status']

function applicationStatusColor(status: RosterApplicationStatus): string {
  switch (status) {
    case 'approved':
      return 'success'
    case 'pending':
      return 'warning'
    case 'rejected':
      return 'error'
    case 'incomplete':
    default:
      return 'grey'
  }
}

async function loadRoster(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  loading.value = true
  error.value = null

  try {
    const params: RosterListParams = {
      page: tableOptions.value.page,
      per_page: tableOptions.value.itemsPerPage,
    }
    if (statusFilter.value !== 'all') params.status = statusFilter.value
    if (countryFilter.value !== null) params.country = countryFilter.value
    if (languageFilter.value !== null) params.language = languageFilter.value
    if (categoryFilter.value !== null) params.category = categoryFilter.value
    const trimmedQuery = searchQuery.value.trim()
    if (trimmedQuery !== '') params.q = trimmedQuery

    const res = await rosterApi.list(agencyId, params)
    items.value = res.data
    totalItems.value = res.meta.total
  } catch {
    error.value = t('app.roster.errors.loadFailed')
  } finally {
    loading.value = false
  }
}

// Explicit load on mount — the v-data-table-server sits inside a v-else
// that only renders once items are populated, so @update:options cannot
// drive the initial load. Mirrors BrandListPage.
onMounted(() => {
  void loadRoster()
})

// Re-load when the active agency resolves / changes (currentAgencyId may
// be null on first mount during async store init).
watch(
  () => agencyStore.currentAgencyId,
  (id) => {
    if (id !== null) void loadRoster()
  },
)

// Any structured-filter change resets to page 1 and re-queries immediately.
watch([statusFilter, countryFilter, languageFilter, categoryFilter], () => {
  tableOptions.value.page = 1
  void loadRoster()
})

// Free-text search is debounced — fires 300ms after the user stops typing
// (mirrors AgencyUsersPage). No existing list-search idiom to mirror beyond
// the debounce shape itself, so this is the page's first search box.
let searchTimer: ReturnType<typeof setTimeout> | null = null
watch(searchQuery, () => {
  if (searchTimer !== null) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    tableOptions.value.page = 1
    void loadRoster()
  }, 300)
})

function onTableUpdate(opts: { page: number; itemsPerPage: number }): void {
  tableOptions.value = opts
  void loadRoster()
}
</script>

<template>
  <div data-test="roster-page">
    <div class="d-flex align-center justify-space-between mb-4">
      <h1 class="text-h5 ma-0" data-test="roster-heading">{{ t('app.roster.title') }}</h1>
    </div>

    <!-- Name/bio full-text search (D-1). Debounced → ?q=. -->
    <v-text-field
      v-model="searchQuery"
      :label="t('app.roster.search.label')"
      :placeholder="t('app.roster.search.placeholder')"
      prepend-inner-icon="mdi-magnify"
      density="compact"
      variant="outlined"
      hide-details
      clearable
      class="mb-3"
      data-test="roster-search"
    />

    <!-- Status filter chips -->
    <v-chip-group v-model="statusFilter" mandatory class="mb-2" data-test="roster-status-filter">
      <v-chip
        v-for="item in statusFilterItems"
        :key="item.value"
        :value="item.value"
        filter
        variant="outlined"
        :data-test="`roster-filter-${item.value}`"
      >
        {{ item.label }}
      </v-chip>
    </v-chip-group>

    <!-- Country / language / category selects -->
    <v-row dense class="mb-2">
      <v-col cols="12" sm="4">
        <v-select
          v-model="countryFilter"
          :items="countryFilterItems"
          :label="t('app.roster.filters.country')"
          density="compact"
          variant="outlined"
          hide-details
          clearable
          data-test="roster-country-filter"
        />
      </v-col>
      <v-col cols="12" sm="4">
        <v-select
          v-model="languageFilter"
          :items="languageFilterItems"
          :label="t('app.roster.filters.language')"
          density="compact"
          variant="outlined"
          hide-details
          clearable
          data-test="roster-language-filter"
        />
      </v-col>
      <v-col cols="12" sm="4">
        <v-select
          v-model="categoryFilter"
          :items="categoryFilterItems"
          :label="t('app.roster.filters.category')"
          density="compact"
          variant="outlined"
          hide-details
          clearable
          data-test="roster-category-filter"
        />
      </v-col>
    </v-row>

    <!-- Disabled filter affordances (D-4). Present-but-inert: faded, disabled,
         and span-wrapped so the tooltip still fires (a disabled control emits
         no hover events — the Chunk-3 KYC idiom). They have NO v-model and NO
         watcher, so they CANNOT issue a query. They flip live when the blocking
         infrastructure lands (METRICS_FILTERS_CONNECTED / AVAILABILITY_FILTER_CONNECTED). -->
    <v-row dense class="mb-2">
      <v-col cols="12" sm="4">
        <v-tooltip
          v-if="!METRICS_FILTERS_CONNECTED"
          location="top"
          :text="t('app.roster.affordances.metrics.tooltip')"
        >
          <template #activator="{ props: tooltipProps }">
            <span v-bind="tooltipProps" data-test="roster-followers-affordance">
              <v-select
                :label="t('app.roster.affordances.followers.label')"
                :items="[]"
                density="compact"
                variant="outlined"
                hide-details
                disabled
                data-test="roster-followers-filter"
              />
            </span>
          </template>
        </v-tooltip>
      </v-col>
      <v-col cols="12" sm="4">
        <v-tooltip
          v-if="!METRICS_FILTERS_CONNECTED"
          location="top"
          :text="t('app.roster.affordances.metrics.tooltip')"
        >
          <template #activator="{ props: tooltipProps }">
            <span v-bind="tooltipProps" data-test="roster-engagement-affordance">
              <v-select
                :label="t('app.roster.affordances.engagement.label')"
                :items="[]"
                density="compact"
                variant="outlined"
                hide-details
                disabled
                data-test="roster-engagement-filter"
              />
            </span>
          </template>
        </v-tooltip>
      </v-col>
      <v-col cols="12" sm="4">
        <v-tooltip
          v-if="!AVAILABILITY_FILTER_CONNECTED"
          location="top"
          :text="t('app.roster.affordances.availability.tooltip')"
        >
          <template #activator="{ props: tooltipProps }">
            <span v-bind="tooltipProps" data-test="roster-availability-affordance">
              <v-select
                :label="t('app.roster.affordances.availability.label')"
                :items="[]"
                density="compact"
                variant="outlined"
                hide-details
                disabled
                data-test="roster-availability-filter"
              />
            </span>
          </template>
        </v-tooltip>
      </v-col>
    </v-row>

    <!-- Error alert -->
    <v-alert v-if="error" type="error" variant="tonal" class="mb-4" data-test="roster-error">
      {{ error }}
    </v-alert>

    <!-- Loading skeleton -->
    <template v-if="loading && items.length === 0">
      <v-skeleton-loader type="table" data-test="roster-skeleton" />
    </template>

    <!-- Empty states -->
    <template v-else-if="!loading && items.length === 0 && !error">
      <CEmptyState
        v-if="!hasActiveFilters"
        data-test="roster-empty-state"
        title-tag="h2"
        :title="t('app.roster.empty.heading')"
        :body="t('app.roster.empty.body')"
      >
        <template #icon>
          <v-icon icon="mdi-account-multiple-outline" size="64" color="medium-emphasis" />
        </template>
      </CEmptyState>
      <CEmptyState
        v-else
        data-test="roster-empty-filtered"
        title-tag="h2"
        :title="t('app.roster.emptyFiltered.heading')"
        :body="t('app.roster.emptyFiltered.body')"
      >
        <template #icon>
          <v-icon icon="mdi-filter-remove-outline" size="48" color="medium-emphasis" />
        </template>
      </CEmptyState>
    </template>

    <!-- Data table -->
    <v-data-table-server
      v-else
      :headers="headers"
      :items="items"
      :items-length="totalItems"
      :loading="loading"
      :items-per-page="tableOptions.itemsPerPage"
      :page="tableOptions.page"
      item-value="id"
      data-test="roster-table"
      @update:options="onTableUpdate"
    >
      <template #item.attributes.display_name="{ item }">
        <span :data-test="`roster-name-${item.id}`">
          {{ item.attributes.display_name ?? t('app.roster.unnamed') }}
        </span>
        <v-chip
          v-if="item.attributes.is_blacklisted"
          size="x-small"
          color="error"
          variant="tonal"
          class="ml-2"
          :data-test="`roster-blacklist-${item.id}`"
        >
          {{ t('app.roster.blacklisted') }}
        </v-chip>
      </template>

      <template #item.attributes.relationship_status="{ item }">
        <v-chip size="small" variant="tonal" :data-test="`roster-status-${item.id}`">
          {{ t(`app.roster.status.${item.attributes.relationship_status}`) }}
        </v-chip>
      </template>

      <!-- Application status (Chunk 5b): display-only, NOT filterable. Solid
           colour-coded chip — visually distinct from the neutral tonal
           relationship chip above so the two axes don't read as the same. -->
      <template #item.attributes.application_status="{ item }">
        <v-chip
          size="small"
          variant="flat"
          :color="applicationStatusColor(item.attributes.application_status)"
          :data-test="`roster-app-status-${item.id}`"
        >
          {{ t(`app.roster.applicationStatus.${item.attributes.application_status}`) }}
        </v-chip>
      </template>

      <template #item.attributes.country_code="{ item }">
        {{ countryLabel(item.attributes.country_code) }}
      </template>

      <template #item.attributes.primary_language="{ item }">
        {{ languageLabel(item.attributes.primary_language) }}
      </template>

      <template #item.attributes.categories="{ item }">
        <div class="d-flex flex-wrap ga-1">
          <v-chip
            v-for="cat in item.attributes.categories ?? []"
            :key="cat"
            size="x-small"
            variant="outlined"
          >
            {{ t(`creator.ui.wizard.categories.${cat}`) }}
          </v-chip>
          <span v-if="(item.attributes.categories ?? []).length === 0">—</span>
        </div>
      </template>

      <template #item.attributes.internal_rating="{ item }">
        <!-- Read-only (D-c5-3): rating editing is Sprint 6 roster management.
             Rendered as lightweight star icons rather than v-rating (which
             leaks heavily under jsdom) — display-only, no interaction. -->
        <span
          v-if="item.attributes.internal_rating !== null"
          class="d-inline-flex align-center"
          :data-test="`roster-rating-${item.id}`"
          :aria-label="t('app.roster.fields.rating') + ': ' + item.attributes.internal_rating"
        >
          <v-icon
            v-for="star in 5"
            :key="star"
            size="x-small"
            color="amber-darken-2"
            :icon="star <= (item.attributes.internal_rating ?? 0) ? 'mdi-star' : 'mdi-star-outline'"
          />
        </span>
        <span v-else :data-test="`roster-rating-${item.id}`">—</span>
      </template>

      <template #item.attributes.total_campaigns_completed="{ item }">
        {{ item.attributes.total_campaigns_completed }}
      </template>
    </v-data-table-server>
  </div>
</template>
