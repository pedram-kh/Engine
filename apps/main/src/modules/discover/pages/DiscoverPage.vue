<script setup lang="ts">
/**
 * Creator discovery — the global pool (Sprint 6.6a, D-8).
 *
 * A SEPARATE surface from the roster (NOT a tab): the roster is "creators I
 * have a relationship with" (relation-gated); Discover is "the global pool"
 * (the public resource). Conflating them would muddy the cross-agency privacy
 * boundary D-7 protects, so they are distinct routes + distinct resources.
 *
 * Reuses the Chunk-1 FTS/filter UI (debounced `?q=` search + country / language
 * / category selects) pointed at the discovery endpoint. Renders a CARD GRID
 * (avatar + name + country/language/categories + the calling-agency-only
 * "already-connected" status); clicking a card opens the public profile.
 *
 * Browse context (page + search + the filters) lives in the URL via
 * `useListQueryState`, NOT in local refs: opening a card unmounts this page, so
 * local state would drop the operator back on page 1 with cleared filters the
 * moment they came back from a profile. See that composable for the rest.
 *
 * Read-only this chunk (D-9): NO send-request action — that's Sprint 6.6b.
 */

import type {
  DiscoveryConnectionState,
  DiscoveryCreatorListItem,
  DiscoveryListParams,
} from '@catalyst/api-client'
import { deriveConnectionState, languageEndonym, worldLanguageOptions } from '@catalyst/api-client'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import { CEmptyState } from '@catalyst/ui'

import {
  boundedIntParam,
  oneOfParam,
  pageParam,
  textParam,
  useListQueryState,
} from '@/composables/useListQueryState'
import { useAgencyStore } from '@/core/stores/useAgencyStore'
import AgencyCreatorCard from '@/modules/agency/components/AgencyCreatorCard.vue'
import AgencyFilterPill from '@/modules/agency/components/AgencyFilterPill.vue'
import AgencyPageHeader from '@/modules/agency/components/AgencyPageHeader.vue'
import { COUNTRY_OPTIONS } from '@/modules/onboarding/data/countries'
import { discoveryApi } from '../api/discovery.api'
import {
  creatorAvatarAlt,
  creatorAvatarUrl,
  creatorInitial,
  creatorLabel,
} from '@/core/creators/creatorLabel'

const { t } = useI18n()
const router = useRouter()
const agencyStore = useAgencyStore()

// Bounded filter vocabularies — shared with the roster (Chunk 1): country reuses
// the launch-market picker; language draws from the shared 24-language EU
// registry (endonym labels); category mirrors the wizard vocabulary.
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
  'pets',
  'photography',
  'home',
  'health',
  'finance',
  'automotive',
  'entertainment',
  'design',
  'dance',
  'sustainability',
  'news',
  'science',
  'other',
] as const

// The age filter's clamp bounds, identical to the roster's and to the backend's
// FiltersCreatorColumns::AGE_FILTER_MIN/MAX, so a URL survives the round trip unchanged.
const AGE_FILTER_MIN = 0
const AGE_FILTER_MAX = 120

// URL-backed browse context. The filters are validated against the SAME
// bounded vocabularies the selects offer, so a hand-typed `?country=ZZ` reads
// as unset instead of reaching the API.
const {
  page,
  q: searchQuery,
  country: countryFilter,
  language: languageFilter,
  category: categoryFilter,
  // AH-116 — the age range. Like the roster, the two bounds are INDEPENDENT.
  age_min: ageMin,
  age_max: ageMax,
} = useListQueryState({
  page: pageParam,
  q: textParam,
  country: oneOfParam(COUNTRY_OPTIONS.map((c) => c.code)),
  language: oneOfParam(worldLanguageOptions().map((o) => o.value)),
  category: oneOfParam(CATEGORY_FILTER_KEYS),
  age_min: boundedIntParam(AGE_FILTER_MIN, AGE_FILTER_MAX),
  age_max: boundedIntParam(AGE_FILTER_MIN, AGE_FILTER_MAX),
})

// `clearable` writes null on clear, so every read of the search box goes
// through the trimmed term (the roster's idiom).
const searchTerm = computed(() => (searchQuery.value ?? '').trim())

const items = ref<DiscoveryCreatorListItem[]>([])
const totalItems = ref(0)
const lastPage = ref(1)
const perPage = 24
const loading = ref(false)
const error = ref<string | null>(null)

// AH-114 (S3.3) — the result count and the footer read `meta.total`; neither is drawn before
// the first response (no zero, no placeholder) or after a failed one.
const hasLoaded = ref(false)
const resultCount = computed(() =>
  hasLoaded.value && error.value === null
    ? t('app.discover.resultCount', { count: totalItems.value }, totalItems.value)
    : null,
)
/** How far down the pool the current page reaches. */
const shownCount = computed(() =>
  Math.min((page.value - 1) * perPage + items.value.length, totalItems.value),
)
const canGoPrevious = computed(() => page.value > 1)
const canGoNext = computed(() => page.value < lastPage.value)

const countryFilterItems = computed(() =>
  COUNTRY_OPTIONS.map((c) => ({ title: c.label, value: c.code })),
)
const languageFilterItems = computed(() =>
  worldLanguageOptions().map((o) => ({ title: o.label, value: o.value })),
)
const categoryFilterItems = computed(() =>
  CATEGORY_FILTER_KEYS.map((key) => ({
    title: t(`creator.ui.wizard.categories.${key}`),
    value: key,
  })),
)

const hasActiveFilters = computed(
  () =>
    searchTerm.value !== '' ||
    countryFilter.value !== null ||
    languageFilter.value !== null ||
    categoryFilter.value !== null ||
    ageMin.value !== null ||
    ageMax.value !== null,
)

// Creators with no birthday never match an age filter, so say so once, under the
// filtered-empty message, rather than leaving the operator to guess why a pool shrank.
const ageFilterActive = computed(() => ageMin.value !== null || ageMax.value !== null)
const emptyFilteredBody = computed(() =>
  ageFilterActive.value
    ? `${t('app.discover.emptyFiltered.body')} ${t('app.discover.emptyFiltered.ageNote')}`
    : t('app.discover.emptyFiltered.body'),
)

function countryLabel(code: string | null): string {
  if (code === null) return '—'
  return COUNTRY_OPTIONS.find((c) => c.code === code)?.label ?? code
}

function languageLabel(code: string | null, accent?: string | null): string | null {
  if (code === null) return null
  const label = languageEndonym(code)
  return accent != null && accent !== '' ? `${label} · ${accent}` : label
}

// The annotation states (Sprint 6.6b, D-5/D-11), derived from the calling-agency-only
// relationship_status alone (the boolean is_connected was removed). `connected` keys on
// `roster` specifically — NOT "has any relation". The card draws the shared status pill
// (`kind="discovery"`) for the four states and its own "Not connected" word for none.
function connectionState(item: DiscoveryCreatorListItem): DiscoveryConnectionState {
  return deriveConnectionState(item.attributes.relationship_status)
}

/** The page props of one card, localised here so the card holds no data of its own. */
function categoryLabels(item: DiscoveryCreatorListItem): string[] {
  return (item.attributes.categories ?? []).map((cat) =>
    t(`creator.ui.wizard.categories.${cat}`, cat),
  )
}

async function loadDiscovery(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  loading.value = true
  error.value = null

  try {
    const params: DiscoveryListParams = { page: page.value, per_page: perPage }
    if (searchTerm.value !== '') params.q = searchTerm.value
    if (countryFilter.value !== null) params.country = countryFilter.value
    if (languageFilter.value !== null) params.language = languageFilter.value
    if (categoryFilter.value !== null) params.category = categoryFilter.value
    if (ageMin.value !== null) params.age_min = ageMin.value
    if (ageMax.value !== null) params.age_max = ageMax.value

    const res = await discoveryApi.list(agencyId, params)
    items.value = res.data
    totalItems.value = res.meta.total
    lastPage.value = res.meta.last_page
    hasLoaded.value = true
  } catch {
    error.value = t('app.discover.errors.loadFailed')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void loadDiscovery()
})

watch(
  () => agencyStore.currentAgencyId,
  (id) => {
    if (id !== null) void loadDiscovery()
  },
)

// Structured filters reset to page 1 and re-query immediately.
watch([countryFilter, languageFilter, categoryFilter, ageMin, ageMax], () => {
  page.value = 1
  void loadDiscovery()
})

// Free-text search is debounced — fires 300ms after the user stops typing
// (mirrors the roster search box).
let searchTimer: ReturnType<typeof setTimeout> | null = null
watch(searchTerm, () => {
  if (searchTimer !== null) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    page.value = 1
    void loadDiscovery()
  }, 300)
})

watch(page, () => {
  void loadDiscovery()
})

function openProfile(item: DiscoveryCreatorListItem): void {
  void router.push({ name: 'discover.detail', params: { ulid: item.id } })
}
</script>

<template>
  <div class="agency-page agency-page--snug" data-test="discover-page">
    <AgencyPageHeader
      :title="t('app.discover.title')"
      :subtitle="t('app.discover.subtitle')"
      subtitle-size="md"
      title-test="discover-heading"
    />

    <!-- Name/bio full-text search (reuses the Chunk-1 FTS → ?q=). -->
    <v-text-field
      v-model="searchQuery"
      :aria-label="t('app.discover.search.label')"
      :placeholder="t('app.discover.search.placeholder')"
      persistent-placeholder
      prepend-inner-icon="mdi-magnify"
      density="compact"
      variant="outlined"
      hide-details
      clearable
      class="agency-search"
      data-test="discover-search"
    />

    <!-- Country / language / category filters, as the pills the frame draws, then the age
         range (two number inputs, each bound works ALONE; the roster draws the same two). -->
    <div class="discover-filters">
      <AgencyFilterPill
        v-model="countryFilter"
        :label="t('app.discover.filters.country')"
        :items="countryFilterItems"
        data-test="discover-country-filter"
      />
      <AgencyFilterPill
        v-model="languageFilter"
        :label="t('app.discover.filters.language')"
        :items="languageFilterItems"
        data-test="discover-language-filter"
      />
      <AgencyFilterPill
        v-model="categoryFilter"
        :label="t('app.discover.filters.category')"
        :items="categoryFilterItems"
        data-test="discover-category-filter"
      />
      <v-text-field
        v-model.number="ageMin"
        type="number"
        :label="t('app.roster.filters.age.min')"
        :min="AGE_FILTER_MIN"
        :max="AGE_FILTER_MAX"
        density="compact"
        variant="outlined"
        hide-details
        clearable
        active
        class="agency-filter"
        :class="{ 'agency-filter--applied': ageMin !== null }"
        style="max-width: 150px"
        data-test="discover-age-min"
      />
      <v-text-field
        v-model.number="ageMax"
        type="number"
        :label="t('app.roster.filters.age.max')"
        :min="AGE_FILTER_MIN"
        :max="AGE_FILTER_MAX"
        density="compact"
        variant="outlined"
        hide-details
        clearable
        active
        class="agency-filter"
        :class="{ 'agency-filter--applied': ageMax !== null }"
        style="max-width: 150px"
        data-test="discover-age-max"
      />
      <p v-if="resultCount" class="discover-filters__count" data-test="discover-result-count">
        {{ resultCount }}
      </p>
    </div>

    <v-alert v-if="error" type="error" variant="tonal" data-test="discover-error">
      {{ error }}
    </v-alert>

    <!-- Loading skeleton -->
    <div v-if="loading && items.length === 0" class="discover-grid" data-test="discover-skeleton">
      <v-skeleton-loader v-for="n in 8" :key="n" type="card" />
    </div>

    <!-- Empty states -->
    <template v-else-if="!loading && items.length === 0 && !error">
      <CEmptyState
        v-if="!hasActiveFilters"
        data-test="discover-empty-state"
        title-tag="h2"
        :title="t('app.discover.empty.heading')"
        :body="t('app.discover.empty.body')"
      >
        <template #icon>
          <v-icon icon="mdi-account-search-outline" size="64" color="medium-emphasis" />
        </template>
      </CEmptyState>
      <CEmptyState
        v-else
        data-test="discover-empty-filtered"
        title-tag="h2"
        :title="t('app.discover.emptyFiltered.heading')"
        :body="emptyFilteredBody"
      >
        <template #icon>
          <v-icon icon="mdi-filter-remove-outline" size="48" color="medium-emphasis" />
        </template>
      </CEmptyState>
    </template>

    <!-- Card grid: four columns, 20 apart (fewer on a narrow window). -->
    <template v-else>
      <div class="discover-grid" data-test="discover-grid">
        <AgencyCreatorCard
          v-for="item in items"
          :id="item.id"
          :key="item.id"
          :name="creatorLabel(item.attributes, t, t('app.discover.unnamed'))"
          :avatar-url="creatorAvatarUrl(item.attributes)"
          :avatar-alt="creatorAvatarAlt(item.attributes)"
          :initial="creatorInitial(item.attributes)"
          :country="
            item.attributes.country_code === null
              ? null
              : countryLabel(item.attributes.country_code)
          "
          :age="item.attributes.age"
          :language="languageLabel(item.attributes.primary_language, item.attributes.accent)"
          :categories="categoryLabels(item)"
          :connection="connectionState(item)"
          :not-connected-label="t('app.discover.connection.notConnected')"
          @open="openProfile(item)"
        />
      </div>

      <div v-if="lastPage > 1" class="agency-list-footer" data-test="discover-pagination">
        <p class="agency-list-footer__count" data-test="discover-showing">
          {{ t('app.pagination.showing', { shown: shownCount, total: totalItems }) }}
        </p>
        <div class="agency-list-footer__pages">
          <v-btn
            variant="outlined"
            size="small"
            :disabled="!canGoPrevious || loading"
            data-test="discover-page-previous"
            @click="page = page - 1"
          >
            {{ t('app.pagination.previous') }}
          </v-btn>
          <v-btn
            variant="outlined"
            size="small"
            :disabled="!canGoNext || loading"
            data-test="discover-page-next"
            @click="page = page + 1"
          >
            {{ t('app.pagination.next') }}
          </v-btn>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* The filter pills 8 apart, the result count at the end (Figma 17:441). */
.discover-filters {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

.discover-filters__count {
  margin: 0;
  margin-inline-start: auto;
  font-size: var(--catalyst-typography-body-sm-size);
  line-height: var(--catalyst-typography-body-sm-line-height);
  color: rgb(var(--v-theme-text-tertiary));
}

/* Four equal columns 20 apart; three, two and one as the window narrows. */
.discover-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 20px;
}

@media (max-width: 1099px) {
  .discover-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

@media (max-width: 799px) {
  .discover-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 519px) {
  .discover-grid {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
