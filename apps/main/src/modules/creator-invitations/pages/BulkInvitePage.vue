<script setup lang="ts">
/**
 * BulkInvitePage — agency-side bulk creator-invitation UI.
 *
 * Sprint 3 Chunk 4 sub-step 11. Single async path per Decision B
 * (reinterpreted at plan-pause-time, Q-pause-PC6 = (α)): submit a
 * CSV, then poll `/jobs/{id}` at 3s cadence until terminal status.
 *
 * UI states:
 *   - idle            — file picker + drop zone, validation help.
 *   - parsing         — client-side CSV parse in progress.
 *   - preview         — parsed rows + soft-warning banner (if >100 rows).
 *   - submitting      — POST in flight to /agencies/{ulid}/creators/invitations/bulk.
 *   - tracking        — polling /jobs/{id} (status: queued | processing).
 *   - complete        — terminal: result.stats + per-row failures.
 *   - failed          — terminal: failure_reason rendered.
 *
 * Access control: gated by `requireAuth` → `requireMfaEnrolled` →
 * `requireAgencyAdmin` (the backend enforces agency_admin role
 * inline via `BulkInviteController::authorizeAdmin`; the route guard
 * makes the affordance discoverable + ahead-of-time blocking).
 *
 * Tenancy: the agency ULID comes from `useAgencyStore.currentAgencyId`.
 * The backend re-validates membership on every request via the
 * authorizeAdmin() inline check.
 *
 * a11y: top-level <h1>, file picker labelled, each state has an
 * `aria-live="polite"` status region for the streaming progress
 * updates the poll loop produces.
 */

import { computed, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import {
  bulkInviteApi,
  type BulkInviteJobStatus,
  type BulkInviteResult,
} from '../api/bulk-invite.api'
import {
  parseCsvText,
  type BulkInviteCsvParseResult,
  type BulkInviteCsvRow,
} from '../composables/useBulkInviteCsv'

const { t } = useI18n()
const agencyStore = useAgencyStore()

type Phase = 'idle' | 'parsing' | 'preview' | 'submitting' | 'tracking' | 'complete' | 'failed'

const phase = ref<Phase>('idle')
const selectedFile = ref<File | null>(null)
const parseResult = ref<BulkInviteCsvParseResult | null>(null)
const submitError = ref<string | null>(null)
const jobUlid = ref<string | null>(null)
const jobStatus = ref<BulkInviteJobStatus | null>(null)
const jobProgress = ref(0)
const jobResult = ref<BulkInviteResult | null>(null)
const jobFailureReason = ref<string | null>(null)
const submitMetaErrors = ref<Array<{ row: number; code: string; detail: string }>>([])

let pollTimer: ReturnType<typeof setTimeout> | null = null

const POLL_INTERVAL_MS = 3000

const previewRows = computed<BulkInviteCsvRow[]>(() => parseResult.value?.rows ?? [])
const previewErrors = computed(() => parseResult.value?.errors ?? [])
const previewFatal = computed(() => parseResult.value?.fatal ?? null)
const exceedsSoftWarning = computed(() => parseResult.value?.exceedsSoftWarning ?? false)
const canSubmit = computed(
  () =>
    phase.value === 'preview' &&
    parseResult.value !== null &&
    parseResult.value.fatal === null &&
    parseResult.value.rowCount > 0,
)

async function onFileSelected(file: File | null): Promise<void> {
  resetForNewFile()
  if (file === null) return
  selectedFile.value = file
  phase.value = 'parsing'
  try {
    const text = await file.text()
    const result = parseCsvText(text, file.size)
    parseResult.value = result
    phase.value = 'preview'
  } catch {
    parseResult.value = {
      rows: [],
      errors: [],
      rowCount: 0,
      exceedsSoftWarning: false,
      fatal: {
        rowNumber: 0,
        code: 'csv.empty',
        detail: 'Could not read the selected file.',
      },
    }
    phase.value = 'preview'
  }
}

function resetForNewFile(): void {
  if (pollTimer !== null) {
    clearTimeout(pollTimer)
    pollTimer = null
  }
  parseResult.value = null
  submitError.value = null
  jobUlid.value = null
  jobStatus.value = null
  jobProgress.value = 0
  jobResult.value = null
  jobFailureReason.value = null
  submitMetaErrors.value = []
  selectedFile.value = null
  phase.value = 'idle'
}

async function onSubmit(): Promise<void> {
  if (!canSubmit.value) return
  const file = selectedFile.value
  const agencyId = agencyStore.currentAgencyId
  if (file === null || agencyId === null) return

  phase.value = 'submitting'
  submitError.value = null
  try {
    const res = await bulkInviteApi.submit(agencyId, file)
    submitMetaErrors.value = res.meta.errors
    jobUlid.value = res.data.id
    phase.value = 'tracking'
    void pollJob()
  } catch (err) {
    submitError.value =
      err instanceof Error
        ? `app.bulkInvite.errors.submitFailed`
        : `app.bulkInvite.errors.submitFailed`
    phase.value = 'preview'
  }
}

async function pollJob(): Promise<void> {
  if (jobUlid.value === null) return
  try {
    const res = await bulkInviteApi.getJob(jobUlid.value)
    jobStatus.value = res.data.status
    jobProgress.value = res.data.progress
    if (res.data.status === 'complete') {
      jobResult.value = res.data.result
      phase.value = 'complete'
      return
    }
    if (res.data.status === 'failed') {
      jobFailureReason.value = res.data.failure_reason
      jobResult.value = res.data.result
      phase.value = 'failed'
      return
    }
    pollTimer = setTimeout(() => {
      void pollJob()
    }, POLL_INTERVAL_MS)
  } catch {
    // Transient errors: keep polling. A permanent failure surfaces
    // via the terminal 'failed' status on the server side.
    pollTimer = setTimeout(() => {
      void pollJob()
    }, POLL_INTERVAL_MS)
  }
}

function startOver(): void {
  resetForNewFile()
}

onBeforeUnmount(() => {
  if (pollTimer !== null) {
    clearTimeout(pollTimer)
    pollTimer = null
  }
})

const progressPercent = computed(() => Math.round(jobProgress.value * 100))

const statusLabel = computed(() => {
  if (jobStatus.value === null) return ''
  return t(`app.bulkInvite.status.${jobStatus.value}`)
})

// Exposed for unit tests — Vuetify's VFileInput cannot be driven from
// vue-test-utils in JSDOM (its `update:modelValue` event is fired from
// internal native-input change handlers we can't simulate). Tests call
// onFileSelected directly to exercise the parse → preview → submit flow.
defineExpose({ onFileSelected })
</script>

<template>
  <div data-test="bulk-invite-page">
    <h1 class="text-h5 mb-2">{{ t('app.bulkInvite.title') }}</h1>
    <p class="text-body-2 text-medium-emphasis mb-6">
      {{ t('app.bulkInvite.description') }}
    </p>

    <!-- ── File picker / idle / parsing / preview ─────────────────── -->
    <template
      v-if="
        phase === 'idle' || phase === 'parsing' || phase === 'preview' || phase === 'submitting'
      "
    >
      <v-file-input
        :label="t('app.bulkInvite.file.label')"
        :hint="t('app.bulkInvite.file.hint')"
        persistent-hint
        accept=".csv,text/csv"
        show-size
        prepend-icon="mdi-file-upload"
        :disabled="phase === 'submitting' || phase === 'parsing'"
        data-test="bulk-invite-file-input"
        @update:model-value="
          (v: File | File[] | null) => onFileSelected(Array.isArray(v) ? (v[0] ?? null) : v)
        "
      />

      <v-alert
        v-if="previewFatal !== null"
        type="error"
        variant="tonal"
        class="mt-4"
        data-test="bulk-invite-fatal"
      >
        {{ previewFatal.detail }}
      </v-alert>

      <template v-else-if="parseResult !== null">
        <v-alert
          v-if="exceedsSoftWarning"
          type="warning"
          variant="tonal"
          class="mt-4"
          data-test="bulk-invite-soft-warning"
        >
          {{ t('app.bulkInvite.softWarning', { count: parseResult.rowCount }) }}
        </v-alert>

        <div class="d-flex align-center justify-space-between mt-4 mb-2">
          <h2 class="text-subtitle-1 ma-0" data-test="bulk-invite-preview-heading">
            {{ t('app.bulkInvite.preview.heading', { count: parseResult.rowCount }) }}
          </h2>
          <span class="text-body-2 text-medium-emphasis" data-test="bulk-invite-error-count">
            {{ t('app.bulkInvite.preview.errorCount', { count: previewErrors.length }) }}
          </span>
        </div>

        <v-table
          v-if="previewRows.length > 0"
          density="compact"
          class="mb-4"
          data-test="bulk-invite-preview-table"
        >
          <thead>
            <tr>
              <th>{{ t('app.bulkInvite.preview.row') }}</th>
              <th>{{ t('app.bulkInvite.preview.email') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in previewRows.slice(0, 10)"
              :key="row.rowNumber"
              :data-test="`bulk-invite-preview-row-${row.rowNumber}`"
            >
              <td>{{ row.rowNumber }}</td>
              <td>{{ row.email }}</td>
            </tr>
          </tbody>
        </v-table>

        <v-alert
          v-if="previewErrors.length > 0"
          type="warning"
          variant="tonal"
          class="mb-4"
          data-test="bulk-invite-preview-errors"
        >
          <p class="font-weight-medium mb-2">
            {{ t('app.bulkInvite.preview.errorHeading') }}
          </p>
          <ul class="bulk-invite__error-list">
            <li
              v-for="err in previewErrors.slice(0, 20)"
              :key="`${err.rowNumber}-${err.code}`"
              :data-test="`bulk-invite-preview-error-${err.rowNumber}`"
            >
              {{
                t('app.bulkInvite.preview.errorItem', { row: err.rowNumber, detail: err.detail })
              }}
            </li>
          </ul>
        </v-alert>

        <div class="d-flex justify-end ga-2">
          <v-btn variant="text" data-test="bulk-invite-reset" @click="startOver">
            {{ t('app.bulkInvite.actions.reset') }}
          </v-btn>
          <v-btn
            color="primary"
            :disabled="!canSubmit"
            :loading="phase === 'submitting'"
            data-test="bulk-invite-submit"
            @click="onSubmit"
          >
            {{ t('app.bulkInvite.actions.submit', { count: parseResult.rowCount }) }}
          </v-btn>
        </div>
      </template>

      <v-alert
        v-if="submitError !== null"
        type="error"
        variant="tonal"
        class="mt-4"
        data-test="bulk-invite-submit-error"
      >
        {{ t(submitError) }}
      </v-alert>
    </template>

    <!-- ── Tracking ──────────────────────────────────────────────── -->
    <template v-if="phase === 'tracking'">
      <v-card class="pa-6" data-test="bulk-invite-tracking">
        <div class="d-flex align-center mb-3">
          <v-progress-circular indeterminate color="primary" size="32" class="mr-4" />
          <div role="status" aria-live="polite">
            <p class="font-weight-medium ma-0">{{ statusLabel }}</p>
            <p
              class="text-body-2 text-medium-emphasis ma-0"
              data-test="bulk-invite-tracking-progress"
            >
              {{ t('app.bulkInvite.tracking.progress', { percent: progressPercent }) }}
            </p>
          </div>
        </div>
        <v-progress-linear :model-value="progressPercent" color="primary" rounded />
      </v-card>
    </template>

    <!-- ── Complete ──────────────────────────────────────────────── -->
    <template v-if="phase === 'complete' && jobResult !== null">
      <v-card class="pa-6 mb-4" data-test="bulk-invite-complete">
        <h2 class="text-subtitle-1 mb-3">{{ t('app.bulkInvite.complete.heading') }}</h2>
        <div class="d-flex ga-6 mb-4 flex-wrap">
          <div data-test="bulk-invite-stat-invited">
            <p class="text-caption text-medium-emphasis ma-0">
              {{ t('app.bulkInvite.complete.invited') }}
            </p>
            <p class="text-h6 ma-0">{{ jobResult.stats.invited }}</p>
          </div>
          <div data-test="bulk-invite-stat-already-invited">
            <p class="text-caption text-medium-emphasis ma-0">
              {{ t('app.bulkInvite.complete.alreadyInvited') }}
            </p>
            <p class="text-h6 ma-0">{{ jobResult.stats.already_invited }}</p>
          </div>
          <div data-test="bulk-invite-stat-failed">
            <p class="text-caption text-medium-emphasis ma-0">
              {{ t('app.bulkInvite.complete.failed') }}
            </p>
            <p class="text-h6 ma-0">{{ jobResult.stats.failed }}</p>
          </div>
        </div>

        <v-table
          v-if="jobResult.failures.length > 0"
          density="compact"
          data-test="bulk-invite-failures-table"
        >
          <thead>
            <tr>
              <th>{{ t('app.bulkInvite.preview.email') }}</th>
              <th>{{ t('app.bulkInvite.complete.failureReason') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="failure in jobResult.failures"
              :key="failure.email"
              :data-test="`bulk-invite-failure-${failure.email}`"
            >
              <td>{{ failure.email }}</td>
              <td>{{ failure.reason }}</td>
            </tr>
          </tbody>
        </v-table>
      </v-card>
      <v-btn color="primary" data-test="bulk-invite-start-over" @click="startOver">
        {{ t('app.bulkInvite.actions.startOver') }}
      </v-btn>
    </template>

    <!-- ── Failed ────────────────────────────────────────────────── -->
    <template v-if="phase === 'failed'">
      <v-alert type="error" variant="tonal" class="mb-4" data-test="bulk-invite-failed">
        <p class="font-weight-medium mb-2">{{ t('app.bulkInvite.failed.heading') }}</p>
        <p v-if="jobFailureReason !== null" class="ma-0">
          {{ jobFailureReason }}
        </p>
      </v-alert>
      <v-btn color="primary" data-test="bulk-invite-start-over" @click="startOver">
        {{ t('app.bulkInvite.actions.startOver') }}
      </v-btn>
    </template>
  </div>
</template>

<style scoped>
.bulk-invite__error-list {
  margin: 0;
  padding-left: 1.5em;
}
</style>
