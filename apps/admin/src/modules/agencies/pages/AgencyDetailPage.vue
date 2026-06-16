<script setup lang="ts">
/**
 * Admin agency detail (Sprint 13, D-3).
 *
 * Shows the agency overview + suspension state and hosts the
 * suspend / reactivate actions. Suspend requires a reason (the backend
 * enforces min length + the agency.suspended audit verb) and blocks every
 * agency user's login; reactivate restores it. Both refresh the page from
 * the server response so the rendered state always matches the SOT.
 */

import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import { formatDateTime, ApiError } from '@catalyst/api-client'

import { adminAgenciesApi, type AdminAgency } from '../api/agencies.api'

const { t, locale } = useI18n()
const route = useRoute()

const ulid = computed(() => String(route.params.ulid))
const agency = ref<AdminAgency | null>(null)
const loading = ref(false)
const errorKey = ref<string | null>(null)

const suspendDialog = ref(false)
const suspendReason = ref('')
const reactivateDialog = ref(false)
const actionPending = ref(false)
const snackbar = ref<{ show: boolean; messageKey: string; color: string }>({
  show: false,
  messageKey: '',
  color: 'success',
})

const MIN_REASON = 10
const reasonValid = computed(() => suspendReason.value.trim().length >= MIN_REASON)

async function load(): Promise<void> {
  loading.value = true
  errorKey.value = null
  try {
    const res = await adminAgenciesApi.show(ulid.value)
    agency.value = res.data
  } catch (error) {
    errorKey.value = error instanceof ApiError ? error.code : 'admin.agencies.detail.load_failed'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void load()
})

function notify(messageKey: string, color: string): void {
  snackbar.value = { show: true, messageKey, color }
}

async function confirmSuspend(): Promise<void> {
  if (!reasonValid.value) return
  actionPending.value = true
  try {
    const res = await adminAgenciesApi.suspend(ulid.value, suspendReason.value.trim())
    agency.value = res.data
    suspendDialog.value = false
    suspendReason.value = ''
    notify('admin.agencies.suspend.success', 'success')
  } catch {
    notify('admin.agencies.suspend.failed', 'error')
  } finally {
    actionPending.value = false
  }
}

async function confirmReactivate(): Promise<void> {
  actionPending.value = true
  try {
    const res = await adminAgenciesApi.reactivate(ulid.value)
    agency.value = res.data
    reactivateDialog.value = false
    notify('admin.agencies.reactivate.success', 'success')
  } catch {
    notify('admin.agencies.reactivate.failed', 'error')
  } finally {
    actionPending.value = false
  }
}

function formatTimestamp(iso: string | null): string {
  return formatDateTime(iso, locale.value)
}
</script>

<template>
  <section data-testid="admin-agency-detail">
    <v-alert
      v-if="errorKey"
      type="error"
      variant="tonal"
      class="mb-4"
      data-testid="admin-agency-detail-error"
    >
      {{ t(errorKey) }}
    </v-alert>

    <template v-if="agency">
      <header class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
        <div class="d-flex align-center ga-3">
          <h1 class="text-h5 ma-0">{{ agency.attributes.name }}</h1>
          <v-chip
            size="small"
            variant="tonal"
            :color="agency.attributes.is_suspended ? 'error' : 'success'"
            data-testid="admin-agency-detail-status"
          >
            {{
              agency.attributes.is_suspended
                ? t('admin.agencies.list.status_labels.suspended')
                : t('admin.agencies.list.status_labels.active')
            }}
          </v-chip>
        </div>

        <v-btn
          v-if="!agency.attributes.is_suspended"
          color="error"
          variant="flat"
          prepend-icon="mdi-cancel"
          data-testid="admin-agency-suspend-btn"
          @click="suspendDialog = true"
        >
          {{ t('admin.agencies.suspend.button') }}
        </v-btn>
        <v-btn
          v-else
          color="success"
          variant="flat"
          prepend-icon="mdi-restore"
          data-testid="admin-agency-reactivate-btn"
          @click="reactivateDialog = true"
        >
          {{ t('admin.agencies.reactivate.button') }}
        </v-btn>
      </header>

      <v-card variant="outlined" class="mb-4">
        <v-card-title class="text-subtitle-1">
          {{ t('admin.agencies.detail.overview_heading') }}
        </v-card-title>
        <v-card-text>
          <v-row dense>
            <v-col cols="12" sm="6">
              <div class="text-caption text-medium-emphasis">
                {{ t('admin.agencies.detail.fields.slug') }}
              </div>
              <div class="text-body-2">{{ agency.attributes.slug }}</div>
            </v-col>
            <v-col cols="12" sm="6">
              <div class="text-caption text-medium-emphasis">
                {{ t('admin.agencies.detail.fields.country') }}
              </div>
              <div class="text-body-2">{{ agency.attributes.country_code }}</div>
            </v-col>
            <v-col cols="12" sm="6">
              <div class="text-caption text-medium-emphasis">
                {{ t('admin.agencies.detail.fields.tier') }}
              </div>
              <div class="text-body-2">{{ agency.attributes.subscription_tier }}</div>
            </v-col>
            <v-col cols="12" sm="6">
              <div class="text-caption text-medium-emphasis">
                {{ t('admin.agencies.detail.fields.members') }}
              </div>
              <div class="text-body-2" data-testid="admin-agency-detail-members">
                {{ agency.attributes.member_count }}
              </div>
            </v-col>
            <v-col cols="12" sm="6">
              <div class="text-caption text-medium-emphasis">
                {{ t('admin.agencies.detail.fields.created_at') }}
              </div>
              <div class="text-body-2">{{ formatTimestamp(agency.attributes.created_at) }}</div>
            </v-col>
          </v-row>
        </v-card-text>
      </v-card>

      <v-card variant="outlined">
        <v-card-title class="text-subtitle-1">
          {{ t('admin.agencies.detail.suspension_heading') }}
        </v-card-title>
        <v-card-text>
          <p class="text-body-2 mb-3" data-testid="admin-agency-detail-suspension-state">
            {{
              agency.attributes.is_suspended
                ? t('admin.agencies.detail.suspended_state')
                : t('admin.agencies.detail.active_state')
            }}
          </p>
          <template v-if="agency.attributes.is_suspended">
            <div class="text-caption text-medium-emphasis">
              {{ t('admin.agencies.detail.fields.suspended_at') }}
            </div>
            <div class="text-body-2 mb-2">
              {{ formatTimestamp(agency.attributes.suspended_at) }}
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ t('admin.agencies.detail.fields.suspended_reason') }}
            </div>
            <div class="text-body-2" data-testid="admin-agency-detail-reason">
              {{ agency.attributes.suspended_reason }}
            </div>
          </template>
        </v-card-text>
      </v-card>
    </template>

    <!-- Suspend dialog -->
    <v-dialog v-model="suspendDialog" max-width="520" data-testid="admin-agency-suspend-dialog">
      <v-card v-if="agency">
        <v-card-title>{{
          t('admin.agencies.suspend.title', { name: agency.attributes.name })
        }}</v-card-title>
        <v-card-text>
          <p class="text-body-2 mb-4">{{ t('admin.agencies.suspend.description') }}</p>
          <v-textarea
            v-model="suspendReason"
            :label="t('admin.agencies.suspend.reason_label')"
            :hint="t('admin.agencies.suspend.reason_hint', { count: MIN_REASON })"
            persistent-hint
            rows="3"
            variant="outlined"
            data-testid="admin-agency-suspend-reason"
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" :disabled="actionPending" @click="suspendDialog = false">
            {{ t('admin.agencies.suspend.cancel') }}
          </v-btn>
          <v-btn
            color="error"
            variant="flat"
            :disabled="!reasonValid || actionPending"
            :loading="actionPending"
            data-testid="admin-agency-suspend-confirm"
            @click="confirmSuspend"
          >
            {{ t('admin.agencies.suspend.confirm') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Reactivate dialog -->
    <v-dialog
      v-model="reactivateDialog"
      max-width="520"
      data-testid="admin-agency-reactivate-dialog"
    >
      <v-card v-if="agency">
        <v-card-title>{{
          t('admin.agencies.reactivate.title', { name: agency.attributes.name })
        }}</v-card-title>
        <v-card-text>
          <p class="text-body-2">{{ t('admin.agencies.reactivate.description') }}</p>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" :disabled="actionPending" @click="reactivateDialog = false">
            {{ t('admin.agencies.reactivate.cancel') }}
          </v-btn>
          <v-btn
            color="success"
            variant="flat"
            :disabled="actionPending"
            :loading="actionPending"
            data-testid="admin-agency-reactivate-confirm"
            @click="confirmReactivate"
          >
            {{ t('admin.agencies.reactivate.confirm') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snackbar.show" :color="snackbar.color" data-testid="admin-agency-snackbar">
      {{ t(snackbar.messageKey) }}
    </v-snackbar>
  </section>
</template>
