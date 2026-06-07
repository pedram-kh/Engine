<script setup lang="ts">
/**
 * Admin user-search + impersonation-start surface (Sprint 13, D-9).
 *
 * The security-critical action: search a non-admin user, capture a
 * MANDATORY reason, and start an impersonation. Start mints a one-time
 * hand-off token; the SPA opens the MAIN app in a new tab with the token
 * in the URL fragment, where the claim establishes the impersonated
 * `web` session. The admin stays signed in as themselves here throughout
 * (the dual-session model). Platform admins are not searchable as
 * targets — the backend enforces no-escalation; this page reflects it.
 *
 * The reason gate is a deliberate friction point, not a formality: it is
 * the incident-review record of WHY support assumed a user's identity,
 * and the backend re-validates it (min length) as the source of truth.
 */

import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { ApiError } from '@catalyst/api-client'

import {
  buildHandoffUrl,
  impersonationApi,
  type ImpersonationCandidate,
} from '../api/impersonation.api'

const REASON_MIN_LENGTH = 10

const { t } = useI18n()

const search = ref('')
const results = ref<ImpersonationCandidate[]>([])
const searching = ref(false)
const searched = ref(false)
const errorKey = ref<string | null>(null)

const dialogOpen = ref(false)
const target = ref<ImpersonationCandidate | null>(null)
const reason = ref('')
const starting = ref(false)
const startErrorKey = ref<string | null>(null)

const snackbar = ref(false)
const snackbarUser = ref('')

let searchDebounce: ReturnType<typeof setTimeout> | null = null

async function runSearch(): Promise<void> {
  searching.value = true
  errorKey.value = null
  try {
    const res = await impersonationApi.searchUsers(search.value)
    results.value = res.data
    searched.value = true
  } catch (error) {
    errorKey.value = error instanceof ApiError ? error.code : 'admin.support.search.load_failed'
  } finally {
    searching.value = false
  }
}

function onSearchInput(): void {
  if (searchDebounce !== null) clearTimeout(searchDebounce)
  searchDebounce = setTimeout(() => {
    void runSearch()
  }, 300)
}

function openImpersonateDialog(candidate: ImpersonationCandidate): void {
  target.value = candidate
  reason.value = ''
  startErrorKey.value = null
  dialogOpen.value = true
}

function closeDialog(): void {
  dialogOpen.value = false
  target.value = null
  reason.value = ''
  startErrorKey.value = null
}

async function confirmImpersonate(): Promise<void> {
  if (target.value === null || reason.value.trim().length < REASON_MIN_LENGTH) {
    return
  }
  starting.value = true
  startErrorKey.value = null
  try {
    const res = await impersonationApi.start(target.value.id, reason.value.trim())
    const { handoff_token, main_spa_url, impersonated_user_name } = res.data.attributes
    // Open the main SPA in a NEW tab so the admin's own session (this tab)
    // is left intact — the hand-off lands the impersonated session on the
    // other origin's cookie.
    window.open(buildHandoffUrl(main_spa_url, handoff_token), '_blank', 'noopener')
    snackbarUser.value = impersonated_user_name
    snackbar.value = true
    closeDialog()
  } catch (error) {
    startErrorKey.value =
      error instanceof ApiError ? error.code : 'admin.support.impersonate.failed'
  } finally {
    starting.value = false
  }
}
</script>

<template>
  <section data-testid="admin-user-search">
    <header class="mb-4">
      <h1 class="text-h5 ma-0">{{ t('admin.support.search.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mt-1 mb-0">
        {{ t('admin.support.search.subtitle') }}
      </p>
    </header>

    <v-text-field
      v-model="search"
      :label="t('admin.support.search.label')"
      density="comfortable"
      variant="outlined"
      clearable
      prepend-inner-icon="mdi-account-search"
      :loading="searching"
      style="max-width: 420px"
      data-testid="admin-user-search-input"
      @update:model-value="onSearchInput"
    />

    <v-alert
      v-if="errorKey"
      type="error"
      variant="tonal"
      class="mb-4"
      data-testid="admin-user-search-error"
    >
      {{ t(errorKey) }}
    </v-alert>

    <v-list v-if="results.length > 0" lines="two" data-testid="admin-user-search-results">
      <v-list-item
        v-for="user in results"
        :key="user.id"
        :title="user.attributes.name"
        :subtitle="user.attributes.email"
        :data-testid="`admin-user-search-row-${user.id}`"
      >
        <template #append>
          <v-chip size="small" variant="tonal" class="mr-3">
            {{ user.attributes.user_type }}
          </v-chip>
          <v-btn
            color="warning"
            variant="tonal"
            size="small"
            prepend-icon="mdi-account-switch"
            :data-testid="`admin-user-search-impersonate-${user.id}`"
            @click="openImpersonateDialog(user)"
          >
            {{ t('admin.support.impersonate.button') }}
          </v-btn>
        </template>
      </v-list-item>
    </v-list>

    <v-alert
      v-else-if="searched && !searching"
      type="info"
      variant="tonal"
      data-testid="admin-user-search-empty"
    >
      {{ t('admin.support.search.empty') }}
    </v-alert>

    <v-dialog v-model="dialogOpen" max-width="520" data-testid="admin-impersonate-dialog">
      <v-card v-if="target">
        <v-card-title>
          {{ t('admin.support.impersonate.title', { name: target.attributes.name }) }}
        </v-card-title>
        <v-card-text>
          <v-alert type="warning" variant="tonal" density="compact" class="mb-4">
            {{ t('admin.support.impersonate.warning') }}
          </v-alert>
          <v-textarea
            v-model="reason"
            :label="t('admin.support.impersonate.reason_label')"
            :hint="t('admin.support.impersonate.reason_hint', { count: REASON_MIN_LENGTH })"
            persistent-hint
            variant="outlined"
            rows="3"
            counter
            data-testid="admin-impersonate-reason"
          />
          <v-alert
            v-if="startErrorKey"
            type="error"
            variant="tonal"
            density="compact"
            class="mt-3"
            data-testid="admin-impersonate-error"
          >
            {{ t(startErrorKey) }}
          </v-alert>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" data-testid="admin-impersonate-cancel" @click="closeDialog">
            {{ t('admin.support.impersonate.cancel') }}
          </v-btn>
          <v-btn
            color="warning"
            variant="flat"
            :loading="starting"
            :disabled="reason.trim().length < REASON_MIN_LENGTH"
            data-testid="admin-impersonate-confirm"
            @click="confirmImpersonate"
          >
            {{ t('admin.support.impersonate.confirm') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snackbar" color="success" data-testid="admin-impersonate-success">
      {{ t('admin.support.impersonate.success', { name: snackbarUser }) }}
    </v-snackbar>
  </section>
</template>
