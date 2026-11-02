<script setup lang="ts">
/**
 * Talent-pool DETAIL page (Sprint 6 Chunk 2b). Shows the pool's metadata plus
 * its MEMBERS — the member roster lives here (counts on the list, D-2b-7), and
 * the members endpoint paginates so the signed-avatar minting is bounded.
 *
 * Admin/manager can remove a member inline. Staff see the roster read-only.
 */

import type { TalentPoolMemberResource, TalentPoolResource } from '@catalyst/api-client'
import { ApiError } from '@catalyst/api-client'
import { BlacklistBadge } from '@catalyst/ui'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import AgencyPageHeader from '@/modules/agency/components/AgencyPageHeader.vue'
import AddCreatorsToPoolDialog from '../components/AddCreatorsToPoolDialog.vue'
import { talentPoolsApi } from '../api/talentPools.api'
import { creatorAvatarUrl, creatorInitial, creatorLabel } from '@/core/creators/creatorLabel'

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

const page = ref(1)
const perPage = 25

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
    const res = await talentPoolsApi.members(agencyId, ulid.value, {
      page: page.value,
      per_page: perPage,
    })
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

        <v-skeleton-loader
          v-if="membersLoading && members.length === 0"
          type="list-item-avatar-two-line@3"
          data-test="pool-members-skeleton"
        />

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
              <v-avatar size="40" color="surface-variant">
                <v-img
                  v-if="creatorAvatarUrl(member.attributes)"
                  :src="creatorAvatarUrl(member.attributes) ?? undefined"
                />
                <span v-else class="text-caption">
                  {{ creatorInitial(member.attributes) }}
                </span>
              </v-avatar>
            </template>
            <v-list-item-title>
              {{ creatorLabel(member.attributes, t, t('app.pools.detail.unnamed')) }}
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
