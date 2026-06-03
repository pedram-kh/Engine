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
 * Sprint 6 Chunk 1 adds: a debounced name/bio search box (→ `?q=` FTS, D-1).
 *
 * Sprint 6.5 (D-6) makes the availability filter REAL: a from/to date range
 * (two native date inputs) threaded to the backend as
 * `?available_from=&available_to=`. A creator is excluded when they have an
 * overlapping HARD availability block in the window (soft never excludes).
 * Both bounds are required — a one-sided range issues no availability param.
 *
 * The METRICS affordance (follower range + engagement) stays a DISABLED,
 * present-but-inert control (D-4): faded, span-wrapped so its tooltip still
 * fires on hover (a disabled control emits no hover events), issuing NO query
 * — blocked by missing DATA (social metrics are null until the adapters land),
 * not dead code.
 *
 * Still deferred: handle search (D-2), real follower/engagement filters,
 * talent pools, internal_rating editing (read-only stars here).
 */

import type {
  RosterCreatorListItem,
  RosterListParams,
  RosterRelationshipStatus,
} from '@catalyst/api-client'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import { CEmptyState } from '@catalyst/ui'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { COUNTRY_OPTIONS } from '@/modules/onboarding/data/countries'
import { rosterApi } from '../api/roster.api'

const { t } = useI18n()
const router = useRouter()

const agencyStore = useAgencyStore()

type StatusFilter = RosterRelationshipStatus | 'all'

const statusFilter = ref<StatusFilter>('all')
const countryFilter = ref<string | null>(null)
const languageFilter = ref<string | null>(null)
const categoryFilter = ref<string | null>(null)
const searchQuery = ref('')

// Availability range filter (Sprint 6.5, D-6). Two `'YYYY-MM-DD'` bounds;
// the filter is sent only when BOTH are set (a one-sided range is ignored).
// `clearable` sets the model to null on clear, so the refs are string | null
// and all reads use truthiness (empty string OR null = "unset").
const availableFrom = ref<string | null>('')
const availableTo = ref<string | null>('')

// Affordance driver (D-4). A static FE constant — there is no backend signal
// to drive it yet (no `kyc_vendor_available` equivalent). The METRICS filters
// (follower range + engagement) stay disabled: blocked by missing DATA
// (`creator_social_accounts.metrics` is null until the social adapters land).
// When the data lands, flip this to a backend-driven flag + wire the real
// control. (Availability is no longer here — Sprint 6.5 made it real.)
const METRICS_FILTERS_CONNECTED = false

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

// The two lifecycle-in-flight statuses (Sprint 6.6b, D-6) are EXCLUDED from
// the default index but get their own chips here, so the agency can
// deliberately filter to "my pending requests" / "who declined me".
const statusFilterItems: { label: string; value: StatusFilter }[] = [
  { label: t('app.roster.filters.status.all'), value: 'all' },
  { label: t('app.roster.filters.status.roster'), value: 'roster' },
  { label: t('app.roster.filters.status.prospect'), value: 'prospect' },
  { label: t('app.roster.filters.status.external'), value: 'external' },
  { label: t('app.roster.filters.status.pending_request'), value: 'pending_request' },
  { label: t('app.roster.filters.status.declined'), value: 'declined' },
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

// Both bounds present → a usable availability window (the only shape the
// backend acts on). Mirrors the api-client's both-required threading.
const hasAvailabilityWindow = computed(
  () => Boolean(availableFrom.value) && Boolean(availableTo.value),
)

const hasActiveFilters = computed(
  () =>
    statusFilter.value !== 'all' ||
    countryFilter.value !== null ||
    languageFilter.value !== null ||
    categoryFilter.value !== null ||
    searchQuery.value.trim() !== '' ||
    hasAvailabilityWindow.value,
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
    // Availability window — both-or-neither (the backend ignores a one-sided
    // range, so we only thread it when complete).
    const from = availableFrom.value
    const to = availableTo.value
    if (from !== null && from !== '' && to !== null && to !== '') {
      params.available_from = from
      params.available_to = to
    }

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
// The availability bounds are included: setting one side reloads without the
// param (it's incomplete), completing the range reloads with it, clearing a
// side drops it — all page-1 resets (mirrors the other filters).
watch(
  [statusFilter, countryFilter, languageFilter, categoryFilter, availableFrom, availableTo],
  () => {
    tableOptions.value.page = 1
    void loadRoster()
  },
)

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

// Row navigation (Sprint 6 Chunk 2a, D-2a-6 — the D-c5-4 reversal). The slim
// row carries the CREATOR ULID as `creator_id`; the detail route keys off it.
// A row without a creator_id (defensive — shouldn't happen) is non-navigable.
function onRowClick(_event: unknown, ctx: { item: RosterCreatorListItem }): void {
  const creatorUlid = ctx.item.attributes.creator_id
  if (creatorUlid === null) return
  void router.push({ name: 'roster.detail', params: { ulid: creatorUlid } })
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

    <!-- Availability range filter (Sprint 6.5, D-6) + the still-disabled
         metrics affordances (D-4). The availability control is now REAL: two
         native date inputs (from/to). Native date fields thread + test cleanly
         and are honestly better UX than a heavy picker for a range filter (the
         same jsdom discipline as the read-only star icons). The window is sent
         only when BOTH bounds are set (a one-sided range issues no param). The
         METRICS controls stay present-but-inert: faded, disabled, span-wrapped
         so the tooltip still fires (a disabled control emits no hover events —
         the Chunk-3 KYC idiom), with NO v-model + NO watcher, so they CANNOT
         query. They flip live when the social-metrics data lands. -->
    <v-row dense class="mb-2">
      <v-col cols="12" sm="3">
        <v-text-field
          v-model="availableFrom"
          type="date"
          :label="t('app.roster.filters.availability.from')"
          :max="availableTo || undefined"
          density="compact"
          variant="outlined"
          hide-details
          clearable
          data-test="roster-available-from"
        />
      </v-col>
      <v-col cols="12" sm="3">
        <v-text-field
          v-model="availableTo"
          type="date"
          :label="t('app.roster.filters.availability.to')"
          :min="availableFrom || undefined"
          density="compact"
          variant="outlined"
          hide-details
          clearable
          data-test="roster-available-to"
        />
      </v-col>
      <v-col cols="12" sm="3">
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
      <v-col cols="12" sm="3">
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
      hover
      data-test="roster-table"
      @update:options="onTableUpdate"
      @click:row="onRowClick"
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
