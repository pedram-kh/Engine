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
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import AddCreatorsToPoolDialog from '../components/AddCreatorsToPoolDialog.vue'
import { talentPoolsApi } from '../api/talentPools.api'

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
      name: member.attributes.display_name ?? t('app.pools.detail.unnamed'),
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
  <div data-test="pool-detail-page">
    <v-btn
      variant="text"
      density="comfortable"
      prepend-icon="mdi-arrow-left"
      class="mb-2 px-0"
      data-test="pool-detail-back"
      @click="goBack"
    >
      {{ t('app.pools.actions.backToList') }}
    </v-btn>

    <v-alert
      v-if="errorMessage"
      type="error"
      variant="tonal"
      class="mb-4"
      data-test="pool-detail-error"
    >
      {{ errorMessage }}
    </v-alert>

    <v-skeleton-loader
      v-if="loading && pool === null"
      type="article, list-item-two-line"
      data-test="pool-detail-skeleton"
    />

    <template v-else-if="pool !== null">
      <header class="d-flex align-center justify-space-between mb-4">
        <div>
          <h1 class="text-h5 ma-0" data-test="pool-detail-name">{{ pool.attributes.name }}</h1>
          <div class="d-flex flex-wrap ga-2 mt-1 align-center">
            <v-chip v-if="pool.attributes.brand_name" size="small" variant="tonal">
              {{ pool.attributes.brand_name }}
            </v-chip>
            <span v-else class="text-caption text-medium-emphasis">
              {{ t('app.pools.agencyWide') }}
            </span>
            <span class="text-caption text-medium-emphasis" data-test="pool-detail-count">
              {{ t('app.pools.detail.memberCount', { count: pool.attributes.creators_count }) }}
            </span>
          </div>
        </div>
        <div v-if="canWrite" class="d-flex ga-2">
          <v-btn
            variant="tonal"
            color="primary"
            prepend-icon="mdi-account-plus-outline"
            data-test="pool-detail-add-creators"
            @click="addDialogOpen = true"
          >
            {{ t('app.pools.addCreators.open') }}
          </v-btn>
          <v-btn
            variant="text"
            prepend-icon="mdi-pencil-outline"
            :to="{ name: 'pools.edit', params: { ulid } }"
            data-test="pool-detail-edit"
          >
            {{ t('app.pools.actions.edit') }}
          </v-btn>
        </div>
      </header>

      <p
        v-if="pool.attributes.description"
        class="text-body-2 mb-4"
        data-test="pool-detail-description"
      >
        {{ pool.attributes.description }}
      </p>

      <h2 class="text-h6 mb-2">{{ t('app.pools.detail.membersHeading') }}</h2>

      <v-skeleton-loader
        v-if="membersLoading && members.length === 0"
        type="list-item-avatar-two-line@3"
        data-test="pool-members-skeleton"
      />

      <div
        v-else-if="members.length === 0"
        class="text-body-2 text-medium-emphasis py-4"
        data-test="pool-members-empty"
      >
        {{ t('app.pools.detail.membersEmpty') }}
      </div>

      <v-list v-else data-test="pool-members-list">
        <v-list-item
          v-for="member in members"
          :key="member.id"
          :data-test="`pool-member-${member.id}`"
        >
          <template #prepend>
            <v-avatar size="40" color="surface-variant">
              <v-img v-if="member.attributes.avatar_url" :src="member.attributes.avatar_url" />
              <span v-else class="text-caption">
                {{ (member.attributes.display_name ?? '?')[0]?.toUpperCase() }}
              </span>
            </v-avatar>
          </template>
          <v-list-item-title>
            {{ member.attributes.display_name ?? t('app.pools.detail.unnamed') }}
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
        class="mt-2"
        data-test="pool-members-pagination"
        @update:model-value="onPageChange"
      />
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
