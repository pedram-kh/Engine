<script setup lang="ts">
/**
 * Talent-pool DETAIL page (Sprint 6 Chunk 2b). Shows the pool's metadata plus
 * its MEMBERS — the member roster lives here (counts on the list, D-2b-7), and
 * the members endpoint paginates so the signed-avatar minting is bounded.
 *
 * Admin/manager can remove a member inline. Staff see the roster read-only.
 *
 * AH-116: the members list takes the roster's country and language filters. Page, country and
 * language live in the URL (`useListQueryState`), so a member's profile can be left and the view
 * re-entered as it was; a filter change returns to page 1. The API applies the filters (A2), so
 * against an API that predates it the list would simply come back unfiltered.
 */

import type { TalentPoolMemberResource, TalentPoolResource } from '@catalyst/api-client'
import { ApiError } from '@catalyst/api-client'
import { worldLanguageOptions } from '@catalyst/api-client'
import { BlacklistBadge, CEmptyState } from '@catalyst/ui'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import { oneOfParam, pageParam, useListQueryState } from '@/composables/useListQueryState'
import { useAgencyStore } from '@/core/stores/useAgencyStore'
import AgencyFilterPill from '@/modules/agency/components/AgencyFilterPill.vue'
import AgencyPageHeader from '@/modules/agency/components/AgencyPageHeader.vue'
import AddCreatorsToPoolDialog from '../components/AddCreatorsToPoolDialog.vue'
import { COUNTRY_OPTIONS } from '@/modules/onboarding/data/countries'
import { talentPoolsApi, type TalentPoolMembersParams } from '../api/talentPools.api'
import {
  creatorAvatarUrl,
  creatorInitial,
  creatorIsErased,
  creatorLabel,
} from '@/core/creators/creatorLabel'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const agencyStore = useAgencyStore()

const ulid = computed(() => String(route.params.ulid ?? ''))

const pool = ref<TalentPoolResource | null>(null)
const members = ref<TalentPoolMemberResource[]>([])
const totalMembers = ref(0)
const loading = ref(false)
const membersLoading = ref(false)
const errorMessage = ref<string | null>(null)
const removingUlid = ref<string | null>(null)
const snackbar = ref<string | null>(null)
const addDialogOpen = ref(false)

const perPage = 25

// URL-backed browse context. The filters are validated against the SAME bounded vocabularies the
// pills offer, so a hand-typed `?country=ZZ` reads as unset instead of reaching the API.
const {
  page,
  country: countryFilter,
  language: languageFilter,
} = useListQueryState({
  page: pageParam,
  country: oneOfParam(COUNTRY_OPTIONS.map((c) => c.code)),
  language: oneOfParam(worldLanguageOptions().map((o) => o.value)),
})

const countryFilterItems = computed(() =>
  COUNTRY_OPTIONS.map((c) => ({ title: c.label, value: c.code })),
)
const languageFilterItems = computed(() =>
  worldLanguageOptions().map((o) => ({ title: o.label, value: o.value })),
)
const hasActiveFilters = computed(
  () => countryFilter.value !== null || languageFilter.value !== null,
)

const canWrite = computed(() => agencyStore.isAdmin || agencyStore.currentRole === 'agency_manager')

async function loadPool(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null || ulid.value === '') return

  loading.value = true
  errorMessage.value = null
  try {
    const res = await talentPoolsApi.show(agencyId, ulid.value)
    pool.value = res.data
  } catch (error) {
    errorMessage.value =
      error instanceof ApiError && error.status === 404
        ? t('app.pools.detail.notFound')
        : t('app.pools.errors.loadFailed')
  } finally {
    loading.value = false
  }
}

async function loadMembers(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null || ulid.value === '') return

  membersLoading.value = true
  try {
    const params: TalentPoolMembersParams = { page: page.value, per_page: perPage }
    if (countryFilter.value !== null) params.country = countryFilter.value
    if (languageFilter.value !== null) params.language = languageFilter.value
    const res = await talentPoolsApi.members(agencyId, ulid.value, params)
    members.value = res.data
    totalMembers.value = res.meta.total
  } catch {
    errorMessage.value = t('app.pools.errors.membersLoadFailed')
  } finally {
    membersLoading.value = false
  }
}

/** Under the title: the brand (or "Agency-wide") and the member count, as before. */
const headerLine = computed<string | null>(() => {
  if (pool.value === null) return null
  const scope = pool.value.attributes.brand_name ?? t('app.pools.agencyWide')
  return `${scope} · ${t('app.pools.detail.memberCount', { count: pool.value.attributes.creators_count })}`
})

const pageCount = computed(() => Math.max(1, Math.ceil(totalMembers.value / perPage)))

async function onPageChange(next: number): Promise<void> {
  page.value = next
  await loadMembers()
}

async function removeMember(member: TalentPoolMemberResource): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  removingUlid.value = member.id
  try {
    const res = await talentPoolsApi.removeCreator(agencyId, ulid.value, member.id)
    pool.value = res.data
    snackbar.value = t('app.pools.detail.removed', {
      name: creatorLabel(member.attributes, t, t('app.pools.detail.unnamed')),
    })
    // Reload the current page (it may now have one fewer row).
    await loadMembers()
  } catch {
    errorMessage.value = t('app.pools.errors.removeFailed')
  } finally {
    removingUlid.value = null
  }
}

async function onCreatorsAdded(message: string): Promise<void> {
  snackbar.value = message
  // Reload the pool (refreshes `creators_count`) + the member roster. The
  // single-add `store` returns the refreshed count, but a multi-add loop +
  // client-side exclusion is simplest to reconcile with a fresh fetch.
  page.value = 1
  await Promise.all([loadPool(), loadMembers()])
}

// A filter change returns to page 1 and re-queries (the pager's own loads go through
// `onPageChange`, so this watcher does not fire a second request for a page move).
watch([countryFilter, languageFilter], () => {
  page.value = 1
  void loadMembers()
})

function goBack(): void {
  void router.push({ name: 'pools.list' })
}

onMounted(() => {
  void loadPool()
  void loadMembers()
})
</script>

<template>
  <div class="agency-page agency-page--snug" data-test="pool-detail-page">
    <AgencyPageHeader
      :title="pool?.attributes.name ?? t('app.pools.detail.title')"
      :subtitle="headerLine"
      title-test="pool-detail-name"
      subtitle-test="pool-detail-count"
    >
      <!-- A brand-scoped pool names its brand as a link (AH-116); the member count stays text.
           An agency-wide pool has no brand, so it keeps the plain `subtitle` line above. -->
      <template
        v-if="pool !== null && pool.attributes.brand_id && pool.attributes.brand_name"
        #subtitle
      >
        <router-link
          :to="{ name: 'brands.detail', params: { ulid: pool.attributes.brand_id } }"
          class="agency-entity-link"
          data-test="pool-detail-brand-link"
          >{{ pool.attributes.brand_name }}</router-link
        >
        {{ ' · ' + t('app.pools.detail.memberCount', { count: pool.attributes.creators_count }) }}
      </template>
      <template #leading>
        <v-btn
          icon="mdi-arrow-left"
          variant="text"
          class="agency-icon-square"
          :aria-label="t('app.pools.actions.backToList')"
          data-test="pool-detail-back"
          @click="goBack"
        />
      </template>
      <template v-if="pool !== null && canWrite" #actions>
        <div class="d-flex ga-2">
          <v-btn
            variant="outlined"
            prepend-icon="mdi-pencil-outline"
            :to="{ name: 'pools.edit', params: { ulid } }"
            data-test="pool-detail-edit"
          >
            {{ t('app.pools.actions.edit') }}
          </v-btn>
          <v-btn
            color="primary"
            variant="flat"
            prepend-icon="mdi-account-plus-outline"
            data-test="pool-detail-add-creators"
            @click="addDialogOpen = true"
          >
            {{ t('app.pools.addCreators.open') }}
          </v-btn>
        </div>
      </template>
    </AgencyPageHeader>

    <v-alert v-if="errorMessage" type="error" variant="tonal" data-test="pool-detail-error">
      {{ errorMessage }}
    </v-alert>

    <v-skeleton-loader
      v-if="loading && pool === null"
      type="article, list-item-two-line"
      data-test="pool-detail-skeleton"
    />

    <template v-else-if="pool !== null">
      <section v-if="pool.attributes.description" class="agency-card">
        <div class="agency-field" data-test="pool-detail-description">
          <h2 class="agency-field__label">{{ t('app.pools.fields.description') }}</h2>
          <p class="agency-field__text ma-0">{{ pool.attributes.description }}</p>
        </div>
      </section>

      <section class="agency-card" data-test="pool-members-card">
        <h2 class="agency-card__title">{{ t('app.pools.detail.membersHeading') }}</h2>

        <!-- Country / language, as the roster draws them. -->
        <div class="pool-members-filters" data-test="pool-members-filters">
          <AgencyFilterPill
            v-model="countryFilter"
            :label="t('app.roster.filters.country')"
            :items="countryFilterItems"
            data-test="pool-members-country-filter"
          />
          <AgencyFilterPill
            v-model="languageFilter"
            :label="t('app.roster.filters.language')"
            :items="languageFilterItems"
            data-test="pool-members-language-filter"
          />
        </div>

        <v-skeleton-loader
          v-if="membersLoading && members.length === 0"
          type="list-item-avatar-two-line@3"
          data-test="pool-members-skeleton"
        />

        <CEmptyState
          v-else-if="members.length === 0 && hasActiveFilters"
          data-test="pool-members-empty-filtered"
          title-tag="h3"
          :title="t('app.roster.emptyFiltered.heading')"
          :body="t('app.roster.emptyFiltered.body')"
        >
          <template #icon>
            <v-icon icon="mdi-filter-remove-outline" size="48" color="medium-emphasis" />
          </template>
        </CEmptyState>

        <div
          v-else-if="members.length === 0"
          class="text-body-2 text-medium-emphasis"
          data-test="pool-members-empty"
        >
          {{ t('app.pools.detail.membersEmpty') }}
        </div>

        <v-list v-else data-test="pool-members-list" class="pa-0">
          <v-list-item
            v-for="member in members"
            :key="member.id"
            class="px-0"
            :data-test="`pool-member-${member.id}`"
          >
            <template #prepend>
              <!-- AH-116: avatar and name open the creator. A pool member always has a relation
                   with this agency, so the roster page resolves. The avatar link is the same
                   target as the name beside it, so it leaves the tab order and the reading order.
                   An erased member is never linked. -->
              <component
                :is="creatorIsErased(member.attributes) ? 'span' : RouterLink"
                v-bind="
                  creatorIsErased(member.attributes)
                    ? {}
                    : {
                        to: { name: 'roster.detail', params: { ulid: member.id } },
                        class: 'agency-entity-link',
                        tabindex: -1,
                        'aria-hidden': 'true',
                        'data-test': `pool-member-avatar-link-${member.id}`,
                      }
                "
              >
                <v-avatar size="40" color="surface-variant">
                  <v-img
                    v-if="creatorAvatarUrl(member.attributes)"
                    :src="creatorAvatarUrl(member.attributes) ?? undefined"
                  />
                  <span v-else class="text-caption">
                    {{ creatorInitial(member.attributes) }}
                  </span>
                </v-avatar>
              </component>
            </template>
            <v-list-item-title>
              <router-link
                v-if="!creatorIsErased(member.attributes)"
                :to="{ name: 'roster.detail', params: { ulid: member.id } }"
                class="agency-entity-link"
                :data-test="`pool-member-name-link-${member.id}`"
                >{{
                  creatorLabel(member.attributes, t, t('app.pools.detail.unnamed'))
                }}</router-link
              >
              <template v-else>{{
                creatorLabel(member.attributes, t, t('app.pools.detail.unnamed'))
              }}</template>
              <!-- Blacklist badge (D-5): shows for BOTH hard + soft (informational
                   — warn-don't-remove). The status reflects THIS agency's own
                   blacklist of the member (the backend join is agency-scoped). -->
              <BlacklistBadge
                v-if="member.attributes.is_blacklisted"
                :type="member.attributes.blacklist_type ?? 'hard'"
                :label="
                  t(`app.roster.blacklist.badge.${member.attributes.blacklist_type ?? 'hard'}`)
                "
                size="x-small"
                class="ml-2"
                :data-test="`pool-member-blacklist-${member.id}`"
              />
            </v-list-item-title>
            <v-list-item-subtitle>
              {{ member.attributes.country_code ?? '' }}
            </v-list-item-subtitle>
            <template v-if="canWrite" #append>
              <v-btn
                icon="mdi-close"
                size="small"
                variant="text"
                color="error"
                :loading="removingUlid === member.id"
                :aria-label="t('app.pools.detail.remove')"
                :data-test="`pool-member-remove-${member.id}`"
                @click="removeMember(member)"
              />
            </template>
          </v-list-item>
        </v-list>

        <v-pagination
          v-if="pageCount > 1"
          :model-value="page"
          :length="pageCount"
          density="comfortable"
          data-test="pool-members-pagination"
          @update:model-value="onPageChange"
        />
      </section>
    </template>

    <AddCreatorsToPoolDialog
      v-if="canWrite && agencyStore.currentAgencyId !== null"
      v-model="addDialogOpen"
      :agency-id="agencyStore.currentAgencyId"
      :pool-id="ulid"
      @added="onCreatorsAdded"
    />

    <v-snackbar
      :model-value="snackbar !== null"
      :timeout="3000"
      color="success"
      data-test="pool-detail-snackbar"
      @update:model-value="
        (v) => {
          if (!v) snackbar = null
        }
      "
    >
      {{ snackbar }}
    </v-snackbar>
  </div>
</template>

<style scoped>
/* The two pills 8 apart, between the heading and the list (the roster's filter row, narrower). */
.pool-members-filters {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}
</style>
