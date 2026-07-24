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
import { deriveConnectionState, languageEndonym, worldLanguageOptions } from '@catalyst/api-client'
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
    searchQuery.value.trim() !== '' ||
    countryFilter.value !== null ||
    languageFilter.value !== null ||
    categoryFilter.value !== null,
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
  // AH-051 (D-3) — a severed prior connection reads as "Previously connected",
  // NOT the "never connected" empty state (which is the `none` no-chip case).
  ended: {
    color: 'default',
    icon: 'mdi-link-off',
    key: 'app.discover.connection.ended',
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
        <!-- ~30% smaller cards: tighter breakpoints pack more per row (2→3,
             3→4, 4→6), shrinking each card proportionally (hero keeps its
             concept-matched 5:4 landscape ratio). -->
        <v-col v-for="item in items" :key="item.id" cols="6" sm="4" md="3" lg="2">
          <v-card
            variant="outlined"
            class="discover-card h-100 d-flex flex-column"
            hover
            :data-test="`discover-card-${item.id}`"
            @click="openProfile(item)"
          >
            <!-- Photo-forward hero (concept-inspired): a full-bleed portrait,
                 falling back to a tinted initial when no avatar is set. -->
            <div class="discover-card__hero">
              <v-img
                v-if="item.attributes.avatar_url"
                :src="item.attributes.avatar_url"
                :alt="item.attributes.display_name ?? ''"
                cover
                class="discover-card__img"
              />
              <div v-else class="discover-card__fallback">
                <span class="discover-card__initial text-medium-emphasis">{{
                  avatarInitial(item.attributes.display_name)
                }}</span>
              </div>
            </div>

            <div class="discover-card__body d-flex flex-column flex-grow-1">
              <div class="discover-card__namerow d-flex align-center">
                <span
                  class="discover-card__name font-weight-bold text-truncate"
                  :data-test="`discover-name-${item.id}`"
                >
                  {{ item.attributes.display_name ?? t('app.discover.unnamed') }}
                </span>
                <!-- Connected → a verified-style check by the name (the
                     concept's badge position); the footer chip carries the
                     label + the annotation the specs assert. -->
                <v-icon
                  v-if="connectionState(item) === 'connected'"
                  icon="mdi-check-decagram"
                  size="16"
                  color="primary"
                  :data-test="`discover-verified-${item.id}`"
                />
              </div>

              <!-- Icon meta row, concept-style (pin · language) with our data. -->
              <div class="discover-card__meta d-flex flex-wrap align-center text-medium-emphasis">
                <span class="d-inline-flex align-center ga-1">
                  <v-icon icon="mdi-map-marker-outline" size="14" />
                  {{ countryLabel(item.attributes.country_code) }}
                </span>
                <span
                  v-if="languageLabel(item.attributes.primary_language)"
                  class="d-inline-flex align-center ga-1"
                >
                  <v-icon icon="mdi-translate" size="14" />
                  {{ languageLabel(item.attributes.primary_language, item.attributes.accent) }}
                </span>
              </div>

              <!-- Single-line chip row (concept keeps categories on one row):
                   show at most 2, collapse the rest into a "+N" chip so the
                   body height — and thus the content-to-image proportion —
                   stays identical across breakpoints. -->
              <div class="discover-card__cats d-flex">
                <v-chip
                  v-for="cat in (item.attributes.categories ?? []).slice(0, 2)"
                  :key="cat"
                  size="x-small"
                  variant="outlined"
                  class="flex-shrink-0"
                >
                  {{ t(`creator.ui.wizard.categories.${cat}`, cat) }}
                </v-chip>
                <v-chip
                  v-if="(item.attributes.categories?.length ?? 0) > 2"
                  size="x-small"
                  variant="tonal"
                  class="flex-shrink-0"
                  :data-test="`discover-cats-more-${item.id}`"
                >
                  +{{ (item.attributes.categories?.length ?? 0) - 2 }}
                </v-chip>
              </div>

              <!-- Footer strip — calling-agency-only connection annotation,
                   three states (D-5/D-11), pinned to the bottom of the card. -->
              <div class="discover-card__footer">
                <v-chip
                  v-if="connectionChip(item)"
                  size="small"
                  :color="connectionChip(item)!.color"
                  variant="tonal"
                  :prepend-icon="connectionChip(item)!.icon"
                  :data-test="`discover-connection-${connectionState(item)}-${item.id}`"
                >
                  {{ t(connectionChip(item)!.key) }}
                </v-chip>
                <span
                  v-else
                  class="text-caption text-disabled"
                  :data-test="`discover-notconnected-${item.id}`"
                >
                  {{ t('app.discover.connection.notConnected') }}
                </span>
              </div>
            </div>
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
  overflow: hidden;
  /* The card is its own query container: everything inside sizes off the
     card's width (cqi units), so the whole card scales as one unit. */
  container-type: inline-size;
  transition:
    border-color 0.15s ease,
    box-shadow 0.15s ease;
}
.discover-card:hover {
  box-shadow: 0 2px 12px rgba(var(--v-theme-on-surface), 0.14);
}
.discover-card__hero {
  position: relative;
  aspect-ratio: 5 / 4;
  overflow: hidden;
  background: rgba(var(--v-theme-on-surface), 0.06);
}
.discover-card__img {
  height: 100%;
  width: 100%;
}
.discover-card__fallback {
  height: 100%;
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(
    135deg,
    rgba(var(--v-theme-primary), 0.18),
    rgba(var(--v-theme-surface), 0.4)
  );
}
.discover-card__initial {
  font-size: clamp(1.5rem, 22cqi, 3rem);
  font-weight: 300;
}

/* Base type tracks the card width (clamped so it never gets illegible, nor
   larger than the big-monitor reference the design was tuned at). Every inner
   size is expressed in em/cqi off this base, so text, icons, chips, gaps and
   padding all shrink and grow together with the card. */
.discover-card__body {
  font-size: clamp(0.6rem, 6.2cqi, 0.8rem);
  padding: 1.15em;
}
.discover-card__namerow {
  column-gap: 0.35em;
}
.discover-card__name {
  min-width: 0;
  font-size: 1.3em;
  line-height: 1.25;
}
.discover-card__namerow :deep(.v-icon) {
  font-size: 1.25em;
  width: 1.25em;
  height: 1.25em;
}
.discover-card__meta {
  margin-top: 0.4em;
  column-gap: 0.9em;
  row-gap: 0.2em;
}
.discover-card__meta > span {
  column-gap: 0.3em;
}
.discover-card__meta :deep(.v-icon) {
  font-size: 1.05em;
  width: 1.05em;
  height: 1.05em;
}
.discover-card__cats {
  flex-wrap: nowrap;
  overflow: hidden;
  gap: 0.35em;
  margin-top: 0.6em;
  min-height: 1.7em;
}
.discover-card__footer {
  margin-top: auto;
  padding-top: 0.8em;
  border-top: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}

/* Chips (categories + connection annotation) scale off the same em base. */
.discover-card :deep(.v-chip) {
  height: auto;
  min-height: 0;
  font-size: 0.85em;
  padding-inline: 0.6em;
  border-radius: 0.5em;
}
.discover-card :deep(.v-chip__content) {
  padding-block: 0.24em;
  line-height: 1.2;
}
.discover-card :deep(.v-chip .v-icon) {
  font-size: 1.1em;
  width: 1.1em;
  height: 1.1em;
}
</style>
