<script setup lang="ts">
/**
 * Admin feature-flag toggle UI (Sprint 13, D-6).
 *
 * Lists every DB-backed Pennant flag with a live on/off switch. Flipping a
 * switch opens a reason dialog (the feature_flag.toggled verb requiresReason
 * — the backend rejects a reasonless flip), and on confirm the toggle hits
 * Feature::activate/deactivate. The list re-reads the authoritative state
 * from the server response so the rendered switch always matches the SOT.
 */

import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { ApiError } from '@catalyst/api-client'

import { adminFeatureFlagsApi, type AdminFeatureFlag } from '../api/feature-flags.api'

const { t } = useI18n()

const items = ref<AdminFeatureFlag[]>([])
const loading = ref(false)
const errorKey = ref<string | null>(null)

const dialog = ref(false)
const pending = ref(false)
const reason = ref('')
const target = ref<{ flag: string; label: string; enabled: boolean } | null>(null)

const snackbar = ref<{ show: boolean; messageKey: string; color: string }>({
  show: false,
  messageKey: '',
  color: 'success',
})

const MIN_REASON = 10
const reasonValid = computed(() => reason.value.trim().length >= MIN_REASON)

async function load(): Promise<void> {
  loading.value = true
  errorKey.value = null
  try {
    const res = await adminFeatureFlagsApi.list()
    items.value = res.data
  } catch (error) {
    errorKey.value = error instanceof ApiError ? error.code : 'admin.featureFlags.load_failed'
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

// The switch's @click fires BEFORE the model would flip; we intercept it,
// stash the intended next state, and gate the actual flip behind the reason
// dialog so the UI never drifts from the audited server state.
function requestToggle(flag: AdminFeatureFlag): void {
  target.value = {
    flag: flag.attributes.name,
    label: flag.attributes.label,
    enabled: !flag.attributes.enabled,
  }
  reason.value = ''
  dialog.value = true
}

async function confirmToggle(): Promise<void> {
  if (target.value === null || !reasonValid.value) return
  pending.value = true
  try {
    const res = await adminFeatureFlagsApi.toggle(
      target.value.flag,
      target.value.enabled,
      reason.value.trim(),
    )
    const idx = items.value.findIndex((f) => f.attributes.name === res.data.attributes.name)
    if (idx !== -1) items.value[idx] = res.data
    dialog.value = false
    target.value = null
    reason.value = ''
    notify('admin.featureFlags.toggle.success', 'success')
  } catch {
    notify('admin.featureFlags.toggle.failed', 'error')
  } finally {
    pending.value = false
  }
}

function cancelToggle(): void {
  dialog.value = false
  target.value = null
  reason.value = ''
}
</script>

<template>
  <section data-testid="admin-feature-flags">
    <header class="mb-4">
      <h1 class="text-h5 ma-0">{{ t('admin.featureFlags.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis">{{ t('admin.featureFlags.subtitle') }}</p>
    </header>

    <v-alert
      v-if="errorKey"
      type="error"
      variant="tonal"
      class="mb-4"
      data-testid="admin-feature-flags-error"
    >
      {{ t(errorKey) }}
    </v-alert>

    <v-card variant="outlined">
      <v-list lines="two">
        <v-list-item
          v-for="flag in items"
          :key="flag.attributes.name"
          :data-testid="`admin-feature-flag-${flag.attributes.name}`"
        >
          <v-list-item-title>{{ flag.attributes.label }}</v-list-item-title>
          <v-list-item-subtitle>{{ flag.attributes.description }}</v-list-item-subtitle>
          <template #append>
            <v-switch
              :model-value="flag.attributes.enabled"
              color="primary"
              hide-details
              density="compact"
              inset
              :disabled="pending"
              :data-testid="`admin-feature-flag-switch-${flag.attributes.name}`"
              @click.prevent="requestToggle(flag)"
            />
          </template>
        </v-list-item>
      </v-list>
    </v-card>

    <v-dialog v-model="dialog" max-width="520" data-testid="admin-feature-flag-dialog">
      <v-card v-if="target">
        <v-card-title>
          {{
            target.enabled
              ? t('admin.featureFlags.toggle.enable_title', { label: target.label })
              : t('admin.featureFlags.toggle.disable_title', { label: target.label })
          }}
        </v-card-title>
        <v-card-text>
          <p class="text-body-2 mb-4">{{ t('admin.featureFlags.toggle.description') }}</p>
          <v-textarea
            v-model="reason"
            :label="t('admin.featureFlags.toggle.reason_label')"
            :hint="t('admin.featureFlags.toggle.reason_hint', { count: MIN_REASON })"
            persistent-hint
            rows="3"
            variant="outlined"
            data-testid="admin-feature-flag-reason"
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" :disabled="pending" @click="cancelToggle">
            {{ t('admin.featureFlags.toggle.cancel') }}
          </v-btn>
          <v-btn
            color="primary"
            variant="flat"
            :disabled="!reasonValid || pending"
            :loading="pending"
            data-testid="admin-feature-flag-confirm"
            @click="confirmToggle"
          >
            {{ t('admin.featureFlags.toggle.confirm') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar
      v-model="snackbar.show"
      :color="snackbar.color"
      data-testid="admin-feature-flags-snackbar"
    >
      {{ t(snackbar.messageKey) }}
    </v-snackbar>
  </section>
</template>
