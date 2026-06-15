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
 * Read-only this chunk (D-9): NO send-request action — that's Sprint 6.6b.
 */

import type {
  DiscoveryConnectionState,
  DiscoveryCreatorListItem,
  DiscoveryListParams,
} from '@catalyst/api-client'
import { deriveConnectionState, euLanguageOptions, languageEndonym } from '@catalyst/api-client'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import { CEmptyState } from '@catalyst/ui'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { COUNTRY_OPTIONS } from '@/modules/onboarding/data/countries'
import { discoveryApi } from '../api/discovery.api'

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
  'other',
] as const

const searchQuery = ref('')
const countryFilter = ref<string | null>(null)
const languageFilter = ref<string | null>(null)
const categoryFilter = ref<string | null>(null)

const items = ref<DiscoveryCreatorListItem[]>([])
const totalItems = ref(0)
const lastPage = ref(1)
const page = ref(1)
const perPage = 24
const loading = ref(false)
const error = ref<string | null>(null)

const countryFilterItems = computed(() =>
  COUNTRY_OPTIONS.map((c) => ({ title: c.label, value: c.code })),
)
const languageFilterItems = computed(() =>
  euLanguageOptions().map((o) => ({ title: o.label, value: o.value })),
)
const categoryFilterItems = computed(() =>
  CATEGORY_FILTER_KEYS.map((key) => ({
    title: t(`creator.ui.wizard.categories.${key}`),
    value: key,
  })),
)

const hasActiveFilters = computed(
  () =>
    searchQuery.value.trim() !== '' ||
    countryFilter.value !== null ||
    languageFilter.value !== null ||
    categoryFilter.value !== null,
)

function countryLabel(code: string | null): string {
  if (code === null) return '—'
  return COUNTRY_OPTIONS.find((c) => c.code === code)?.label ?? code
}

function languageLabel(code: string | null): string | null {
  if (code === null) return null
  return languageEndonym(code)
}

function avatarInitial(name: string | null): string {
  return (name ?? '?').trim().charAt(0).toUpperCase() || '?'
}

// The three annotation states (Sprint 6.6b, D-5/D-11), derived from the
// calling-agency-only relationship_status alone (the boolean is_connected was
// removed). `connected` keys on `roster` specifically — NOT "has any relation".
const CONNECTION_CHIP: Record<
  Exclude<DiscoveryConnectionState, 'none'>,
  { color: string; icon: string; key: string }
> = {
  connected: {
    color: 'primary',
    icon: 'mdi-link-variant',
    key: 'app.discover.connection.connected',
  },
  pending: { color: 'info', icon: 'mdi-clock-outline', key: 'app.discover.connection.pending' },
  declined: {
    color: 'default',
    icon: 'mdi-close-circle-outline',
    key: 'app.discover.connection.declined',
  },
}

function connectionState(item: DiscoveryCreatorListItem): DiscoveryConnectionState {
  return deriveConnectionState(item.attributes.relationship_status)
}

// The chip descriptor for the item's state, or null for `none` (no chip). Kept
// in the script (not an inline template cast) so the `Exclude<…, 'none'>`
// generic never lands in a `{{ }}` expression — Prettier's HTML parser reads the
// `<` as an opening tag and chokes.
function connectionChip(
  item: DiscoveryCreatorListItem,
): { color: string; icon: string; key: string } | null {
  const state = connectionState(item)
  return state === 'none' ? null : CONNECTION_CHIP[state]
}

async function loadDiscovery(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  loading.value = true
  error.value = null

  try {
    const params: DiscoveryListParams = { page: page.value, per_page: perPage }
    const trimmed = searchQuery.value.trim()
    if (trimmed !== '') params.q = trimmed
    if (countryFilter.value !== null) params.country = countryFilter.value
    if (languageFilter.value !== null) params.language = languageFilter.value
    if (categoryFilter.value !== null) params.category = categoryFilter.value

    const res = await discoveryApi.list(agencyId, params)
    items.value = res.data
    totalItems.value = res.meta.total
    lastPage.value = res.meta.last_page
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
watch([countryFilter, languageFilter, categoryFilter], () => {
  page.value = 1
  void loadDiscovery()
})

// Free-text search is debounced — fires 300ms after the user stops typing
// (mirrors the roster search box).
let searchTimer: ReturnType<typeof setTimeout> | null = null
watch(searchQuery, () => {
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
  <div data-test="discover-page">
    <div class="d-flex flex-column mb-4">
      <h1 class="text-h5 ma-0" data-test="discover-heading">{{ t('app.discover.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis ma-0 mt-1">{{ t('app.discover.subtitle') }}</p>
    </div>

    <!-- Name/bio full-text search (reuses the Chunk-1 FTS → ?q=). -->
    <v-text-field
      v-model="searchQuery"
      :label="t('app.discover.search.label')"
      :placeholder="t('app.discover.search.placeholder')"
      prepend-inner-icon="mdi-magnify"
      density="compact"
      variant="outlined"
      hide-details
      clearable
      class="mb-3"
      data-test="discover-search"
    />

    <!-- Country / language / category selects -->
    <v-row dense class="mb-2">
      <v-col cols="12" sm="4">
        <v-select
          v-model="countryFilter"
          :items="countryFilterItems"
          :label="t('app.discover.filters.country')"
          density="compact"
          variant="outlined"
          hide-details
          clearable
          data-test="discover-country-filter"
        />
      </v-col>
      <v-col cols="12" sm="4">
        <v-select
          v-model="languageFilter"
          :items="languageFilterItems"
          :label="t('app.discover.filters.language')"
          density="compact"
          variant="outlined"
          hide-details
          clearable
          data-test="discover-language-filter"
        />
      </v-col>
      <v-col cols="12" sm="4">
        <v-select
          v-model="categoryFilter"
          :items="categoryFilterItems"
          :label="t('app.discover.filters.category')"
          density="compact"
          variant="outlined"
          hide-details
          clearable
          data-test="discover-category-filter"
        />
      </v-col>
    </v-row>

    <v-alert v-if="error" type="error" variant="tonal" class="mb-4" data-test="discover-error">
      {{ error }}
    </v-alert>

    <!-- Loading skeleton -->
    <v-row v-if="loading && items.length === 0" dense data-test="discover-skeleton">
      <v-col v-for="n in 8" :key="n" cols="12" sm="6" md="4" lg="3">
        <v-skeleton-loader type="card" />
      </v-col>
    </v-row>

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
        :body="t('app.discover.emptyFiltered.body')"
      >
        <template #icon>
          <v-icon icon="mdi-filter-remove-outline" size="48" color="medium-emphasis" />
        </template>
      </CEmptyState>
    </template>

    <!-- Card grid -->
    <template v-else>
      <v-row dense data-test="discover-grid">
        <v-col v-for="item in items" :key="item.id" cols="12" sm="6" md="4" lg="3">
          <v-card
            variant="outlined"
            class="discover-card h-100"
            hover
            :data-test="`discover-card-${item.id}`"
            @click="openProfile(item)"
          >
            <v-card-text class="d-flex flex-column align-center text-center">
              <v-avatar size="72" color="primary" class="mb-3">
                <v-img
                  v-if="item.attributes.avatar_url"
                  :src="item.attributes.avatar_url"
                  :alt="item.attributes.display_name ?? ''"
                />
                <span v-else class="text-h6 text-white">{{
                  avatarInitial(item.attributes.display_name)
                }}</span>
              </v-avatar>

              <span
                class="text-subtitle-1 font-weight-medium"
                :data-test="`discover-name-${item.id}`"
              >
                {{ item.attributes.display_name ?? t('app.discover.unnamed') }}
              </span>

              <div class="text-caption text-medium-emphasis mt-1">
                <span>{{ countryLabel(item.attributes.country_code) }}</span>
                <template v-if="languageLabel(item.attributes.primary_language)">
                  · {{ languageLabel(item.attributes.primary_language) }}
                </template>
              </div>

              <div class="d-flex flex-wrap justify-center ga-1 mt-2">
                <v-chip
                  v-for="cat in (item.attributes.categories ?? []).slice(0, 3)"
                  :key="cat"
                  size="x-small"
                  variant="outlined"
                >
                  {{ t(`creator.ui.wizard.categories.${cat}`, cat) }}
                </v-chip>
              </div>

              <!-- Calling-agency-only connection annotation, three states
                   (D-5/D-11): connected / pending / declined / not connected. -->
              <v-chip
                v-if="connectionChip(item)"
                size="small"
                :color="connectionChip(item)!.color"
                variant="tonal"
                class="mt-3"
                :prepend-icon="connectionChip(item)!.icon"
                :data-test="`discover-connection-${connectionState(item)}-${item.id}`"
              >
                {{ t(connectionChip(item)!.key) }}
              </v-chip>
              <span
                v-else
                class="text-caption text-disabled mt-3"
                :data-test="`discover-notconnected-${item.id}`"
              >
                {{ t('app.discover.connection.notConnected') }}
              </span>
            </v-card-text>
          </v-card>
        </v-col>
      </v-row>

      <div v-if="lastPage > 1" class="d-flex justify-center mt-4">
        <v-pagination
          v-model="page"
          :length="lastPage"
          :total-visible="7"
          density="comfortable"
          data-test="discover-pagination"
        />
      </div>
    </template>
  </div>
</template>

<style scoped>
.discover-card {
  cursor: pointer;
  transition: border-color 0.15s ease;
}
</style>
