<script setup lang="ts">
/**
 * ConnectToAgencyDialog — admin "Connect to agency" modal (AH-051 D-4/D-5/D-9).
 *
 * Two doors behind one dialog (the single mode-switched POST):
 *   - request → Door 1: sends the agency's usual connection request; the creator
 *     accepts/declines it. No reason.
 *   - direct  → Door 2: records an OFFLINE agreement, connecting the pair
 *     immediately. A reason is MANDATORY (min 10 — the consent paper-trail;
 *     mirrors `AdminCreateConnectionRequest`), and the creator is notified.
 *
 * Presentational by design (mirrors RejectCreatorDialog's decoupling): the agency
 * PICKER is fed via the `agencies` prop and a debounced `search` event — the
 * parent owns `adminAgenciesApi.list`, keeping this component API-free and unit-
 * testable. `approved` gating + `is_discoverable` bypass live on the backend;
 * this surface only assembles the payload.
 */

import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import type { AdminConnectionMode } from '../api/creators.api'

defineOptions({ name: 'ConnectToAgencyDialog' })

export interface AgencyOption {
  ulid: string
  name: string
}

const props = defineProps<{
  modelValue: boolean
  isSaving: boolean
  errorKey: string | null
  creatorDisplayName: string
  agencies: ReadonlyArray<AgencyOption>
  isSearching: boolean
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
  (e: 'search', query: string): void
  (
    e: 'confirm',
    payload: { agencyId: string; mode: AdminConnectionMode; reason: string | null },
  ): void
  (e: 'cancel'): void
}>()

const { t } = useI18n()

const REASON_MIN = 10
const REASON_MAX = 2000

const selectedAgencyId = ref<string | null>(null)
const mode = ref<AdminConnectionMode>('request')
const reason = ref('')
const searchQuery = ref('')

watch(
  () => props.modelValue,
  (open) => {
    if (open === true) {
      selectedAgencyId.value = null
      mode.value = 'request'
      reason.value = ''
      searchQuery.value = ''
    }
  },
)

// Vuetify's autocomplete fires update:search on every keystroke; the parent
// debounces the actual list call.
watch(searchQuery, (query) => {
  emit('search', query ?? '')
})

const agencyItems = computed(() =>
  props.agencies.map((agency) => ({ title: agency.name, value: agency.ulid })),
)

const isDirect = computed(() => mode.value === 'direct')
const trimmedReasonLength = computed(() => reason.value.trim().length)

const canConfirm = computed<boolean>(() => {
  if (props.isSaving) return false
  if (selectedAgencyId.value === null) return false
  if (isDirect.value) {
    if (trimmedReasonLength.value < REASON_MIN) return false
    if (reason.value.length > REASON_MAX) return false
  }
  return true
})

const errorText = computed(() => (props.errorKey === null ? null : t(props.errorKey)))

const reasonHint = computed(() =>
  t('admin.creators.detail.connections.connect.reason_hint', { count: REASON_MIN }),
)

function onConfirm(): void {
  if (!canConfirm.value || selectedAgencyId.value === null) return
  emit('confirm', {
    agencyId: selectedAgencyId.value,
    mode: mode.value,
    reason: isDirect.value ? reason.value.trim() : null,
  })
}

function onCancel(): void {
  emit('cancel')
  emit('update:modelValue', false)
}

// Test seam: the agency PICKER is a Vuetify autocomplete whose selection is
// impractical to drive through the DOM in jsdom, so the reactive form state is
// exposed for unit assertions of the two-door gate logic.
defineExpose({ selectedAgencyId, mode, reason, searchQuery })
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="600"
    persistent
    data-testid="admin-creator-connect-dialog"
    @update:model-value="(v: boolean) => emit('update:modelValue', v)"
  >
    <v-card>
      <v-card-title class="text-h6" data-testid="admin-creator-connect-dialog-title">
        {{ t('admin.creators.detail.connections.connect.title', { name: creatorDisplayName }) }}
      </v-card-title>

      <v-card-text>
        <v-autocomplete
          v-model="selectedAgencyId"
          v-model:search="searchQuery"
          :items="agencyItems"
          :loading="isSearching"
          :label="t('admin.creators.detail.connections.connect.agency_label')"
          :placeholder="t('admin.creators.detail.connections.connect.agency_placeholder')"
          :no-data-text="t('admin.creators.detail.connections.connect.no_agencies')"
          item-title="title"
          item-value="value"
          no-filter
          clearable
          autocomplete="off"
          data-testid="admin-creator-connect-dialog-agency"
        />

        <p class="text-subtitle-2 mt-2 mb-1">
          {{ t('admin.creators.detail.connections.connect.mode_label') }}
        </p>
        <v-radio-group
          v-model="mode"
          density="compact"
          hide-details
          data-testid="admin-creator-connect-dialog-mode"
        >
          <v-radio value="request" data-testid="admin-creator-connect-dialog-mode-request">
            <template #label>
              <div>
                <div>{{ t('admin.creators.detail.connections.connect.mode_request') }}</div>
                <div class="text-caption text-medium-emphasis">
                  {{ t('admin.creators.detail.connections.connect.mode_request_hint') }}
                </div>
              </div>
            </template>
          </v-radio>
          <v-radio value="direct" data-testid="admin-creator-connect-dialog-mode-direct">
            <template #label>
              <div>
                <div>{{ t('admin.creators.detail.connections.connect.mode_direct') }}</div>
                <div class="text-caption text-medium-emphasis">
                  {{ t('admin.creators.detail.connections.connect.mode_direct_hint') }}
                </div>
              </div>
            </template>
          </v-radio>
        </v-radio-group>

        <v-textarea
          v-if="isDirect"
          v-model="reason"
          class="mt-3"
          :label="t('admin.creators.detail.connections.connect.reason_label')"
          :hint="reasonHint"
          persistent-hint
          rows="3"
          auto-grow
          :counter="REASON_MAX"
          :maxlength="REASON_MAX"
          data-testid="admin-creator-connect-dialog-reason"
          required
        />

        <div
          v-if="errorText !== null"
          role="alert"
          class="connect-dialog__error"
          data-testid="admin-creator-connect-dialog-error"
        >
          {{ errorText }}
        </div>
      </v-card-text>

      <v-card-actions>
        <v-spacer />
        <v-btn
          variant="text"
          :disabled="isSaving"
          data-testid="admin-creator-connect-dialog-cancel"
          @click="onCancel"
        >
          {{ t('admin.creators.detail.connections.connect.cancel') }}
        </v-btn>
        <v-btn
          color="primary"
          :loading="isSaving"
          :disabled="!canConfirm"
          data-testid="admin-creator-connect-dialog-confirm"
          @click="onConfirm"
        >
          {{ t('admin.creators.detail.connections.connect.confirm') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.connect-dialog__error {
  margin-top: 8px;
  color: rgb(var(--v-theme-error));
  font-size: 0.875rem;
}
</style>
