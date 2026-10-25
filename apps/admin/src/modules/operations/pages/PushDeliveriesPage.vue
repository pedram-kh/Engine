<script setup lang="ts">
/**
 * Admin push-delivery log (AH-102b, Half A).
 *
 * Read-only, cross-agency view of every push SEND ATTEMPT that reached the
 * provider and what it said. Outcome tiles (terminal attempts only — a retried
 * push is one outcome, not three) over a 24h/7d/30d window, an outcome chip
 * group with a `Failures` preset, filters, and a CURSOR-paginated attempt table
 * patterned on the audit-log viewer.
 *
 * The page is deliberately explicit about what it does NOT count (the
 * "What this counts" panel) and about the channel it does not cover (email).
 * An admin reading zero failures must know whether that means "all good" or
 * "nothing was attempted".
 *
 * Deep link: `?creator=<ulid>&creator_name=<display name>` (from the creator
 * app drawer). `creator_name` is DISPLAY-ONLY — it is never sent to the API.
 */

import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import { ApiError, formatDateTime } from '@catalyst/api-client'

import { creatorEmail, creatorLabel } from '@/core/creators/creatorLabel'
import DeletionRequestedChip from '@/core/creators/DeletionRequestedChip.vue'

import {
  adminPushDeliveriesApi,
  PUSH_NOTIFICATION_TYPES,
  pushNotificationTypeLabelKey,
  type AdminPushDeliveriesSummary,
  type AdminPushDelivery,
  type PushOutcome,
  type PushOutcomeFilter,
  type PushWindow,
} from '../api/pushDeliveries.api'

type OutcomeChip = PushOutcomeFilter | 'all'

const { t, te, locale } = useI18n()
const route = useRoute()

const PER_PAGE = 50
const WINDOWS: PushWindow[] = ['24h', '7d', '30d']
const OUTCOME_CHIPS: OutcomeChip[] = [
  'all',
  'failures',
  'delivered',
  'token_invalid',
  'retryable',
  'config_error',
  'rejected',
]

// The type filter is a select over the known push-eligible types: the label
// is readable, the payload is the stored type value (never typed by hand).
const typeItems = computed(() =>
  PUSH_NOTIFICATION_TYPES.map((value) => ({
    value,
    title: t(pushNotificationTypeLabelKey(value)),
  })),
)

const windowChoice = ref<PushWindow>('7d')
const summary = ref<AdminPushDeliveriesSummary | null>(null)

const outcome = ref<OutcomeChip>('all')
const filters = ref<{
  notification_type: string | null
  creator: string
  date_from: string
  date_to: string
}>({ notification_type: '', creator: '', date_from: '', date_to: '' })
// Display-only (deep link). Never sent to the API.
const creatorName = ref<string | null>(null)

const items = ref<AdminPushDelivery[]>([])
const loading = ref(false)
const errorKey = ref<string | null>(null)
const nextCursor = ref<string | null>(null)
// Cursor stack so "previous" walks back through visited pages.
const cursorHistory = ref<string[]>([])
let requestSeq = 0

// An unknown / transport error code (`http.unknown_error`, `network.error`)
// has no translation: fall back to the page's own message instead of
// rendering the raw key at an admin.
const errorText = computed(() =>
  errorKey.value !== null && te(errorKey.value)
    ? t(errorKey.value)
    : t('admin.operations.push_deliveries.load_failed'),
)

const configErrorCount = computed(() => summary.value?.counts.config_error ?? 0)
const configErrorProminent = computed(() => configErrorCount.value > 0)

const tiles = computed(() => {
  const counts = summary.value?.counts
  return [
    {
      key: 'delivered',
      label: t('admin.operations.push_deliveries.outcomes.delivered'),
      n: counts?.delivered,
    },
    {
      key: 'token_invalid',
      label: t('admin.operations.push_deliveries.outcomes.token_invalid'),
      n: counts?.token_invalid,
    },
    {
      key: 'retryable_final',
      label: t('admin.operations.push_deliveries.tiles.retryable_final'),
      n: counts?.retryable_final,
    },
    {
      key: 'config_error',
      label: t('admin.operations.push_deliveries.outcomes.config_error'),
      n: counts?.config_error,
    },
    {
      key: 'rejected',
      label: t('admin.operations.push_deliveries.outcomes.rejected'),
      n: counts?.rejected,
    },
  ]
})

// `null` while the summary is unavailable (loading or failed): saying "no push
// attempts stored yet" then would be a claim we cannot back.
const footerText = computed<string | null>(() => {
  const table = summary.value?.table
  if (table === undefined) return null
  if (table.total_rows === 0 || table.oldest_at === null) {
    return t('admin.operations.push_deliveries.footer.empty')
  }
  return t('admin.operations.push_deliveries.footer.rows', {
    count: table.total_rows,
    date: formatDateTime(table.oldest_at, locale.value),
  })
})

async function loadSummary(): Promise<void> {
  try {
    summary.value = await adminPushDeliveriesApi.summary(windowChoice.value)
  } catch (error) {
    errorKey.value =
      error instanceof ApiError ? error.code : 'admin.operations.push_deliveries.load_failed'
  }
}

async function fetchPage(cursor: string | null): Promise<void> {
  const seq = ++requestSeq
  loading.value = true
  errorKey.value = null
  try {
    const res = await adminPushDeliveriesApi.list({
      outcome: outcome.value === 'all' ? undefined : outcome.value,
      notification_type: filters.value.notification_type || undefined,
      creator: filters.value.creator || undefined,
      date_from: filters.value.date_from || undefined,
      date_to: filters.value.date_to || undefined,
      per_page: PER_PAGE,
      cursor: cursor ?? undefined,
    })
    if (seq !== requestSeq) return
    items.value = res.data
    nextCursor.value = res.meta.next_cursor
  } catch (error) {
    if (seq !== requestSeq) return
    errorKey.value =
      error instanceof ApiError ? error.code : 'admin.operations.push_deliveries.load_failed'
  } finally {
    if (seq === requestSeq) loading.value = false
  }
}

function applyFilters(): void {
  cursorHistory.value = []
  void fetchPage(null)
}

function clearCreator(): void {
  filters.value.creator = ''
  creatorName.value = null
  applyFilters()
}

function goNext(): void {
  if (nextCursor.value === null) return
  cursorHistory.value.push(nextCursor.value)
  void fetchPage(nextCursor.value)
}

function goPrev(): void {
  // Pop the current page's cursor; the new top is the previous page.
  cursorHistory.value.pop()
  void fetchPage(cursorHistory.value[cursorHistory.value.length - 1] ?? null)
}

// A chip change is a new query: the old cursor means nothing, restart.
watch(outcome, applyFilters)
watch(windowChoice, () => {
  void loadSummary()
})

function outcomeColor(value: PushOutcome): string {
  switch (value) {
    case 'delivered':
      return 'success'
    case 'retryable':
      return 'warning'
    default:
      return 'error'
  }
}

onMounted(() => {
  const creator = route.query.creator
  if (typeof creator === 'string' && creator !== '') {
    filters.value.creator = creator
    const name = route.query.creator_name
    creatorName.value = typeof name === 'string' && name !== '' ? name : null
  }
  void loadSummary()
  void fetchPage(null)
})
</script>

<template>
  <section data-testid="admin-push-deliveries">
    <header class="d-flex align-center justify-space-between flex-wrap ga-2 mb-4">
      <h1 class="text-h5 ma-0">{{ t('admin.operations.push_deliveries.title') }}</h1>

      <v-btn-toggle
        v-model="windowChoice"
        mandatory
        density="compact"
        variant="outlined"
        divided
        :aria-label="t('admin.operations.push_deliveries.window.label')"
        data-testid="admin-push-window"
      >
        <v-btn v-for="w in WINDOWS" :key="w" :value="w" :data-testid="`admin-push-window-${w}`">
          {{ t(`admin.operations.push_deliveries.window.${w}`) }}
        </v-btn>
      </v-btn-toggle>
    </header>

    <v-row dense class="mb-2" data-testid="admin-push-tiles">
      <v-col v-for="tile in tiles" :key="tile.key" cols="6" sm="4" md="2">
        <v-card
          variant="tonal"
          :color="tile.key === 'config_error' && configErrorProminent ? 'error' : undefined"
          :data-testid="`admin-push-tile-${tile.key}`"
          :data-prominent="tile.key === 'config_error' && configErrorProminent ? 'true' : 'false'"
        >
          <v-card-text>
            <div class="text-caption">{{ tile.label }}</div>
            <div
              class="text-h5"
              :class="{ 'font-weight-bold': tile.key === 'config_error' && configErrorProminent }"
              :data-testid="`admin-push-tile-${tile.key}-count`"
            >
              {{ tile.n ?? '—' }}
            </div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <v-alert
      v-if="configErrorProminent"
      type="error"
      variant="tonal"
      density="compact"
      class="mb-4"
      data-testid="admin-push-config-error-hint"
    >
      {{ t('admin.operations.push_deliveries.tiles.config_error_hint') }}
    </v-alert>

    <div class="d-flex flex-wrap ga-2 mb-4" data-testid="admin-push-channels">
      <v-chip variant="tonal" color="primary" data-testid="admin-push-channel-push">
        {{ t('admin.operations.push_deliveries.channels.push') }}
        · {{ t('admin.operations.push_deliveries.channels.push_note') }}
      </v-chip>
      <v-chip variant="tonal" data-testid="admin-push-channel-in-app">
        {{ t('admin.operations.push_deliveries.channels.in_app') }}
        · {{ t('admin.operations.push_deliveries.channels.in_app_note') }}
      </v-chip>
      <v-chip variant="outlined" data-testid="admin-push-channel-email">
        {{ t('admin.operations.push_deliveries.channels.email') }}
        · {{ t('admin.operations.push_deliveries.channels.email_note') }}
      </v-chip>
    </div>

    <v-chip-group v-model="outcome" mandatory class="mb-2" data-testid="admin-push-outcome">
      <v-chip
        v-for="chip in OUTCOME_CHIPS"
        :key="chip"
        :value="chip"
        filter
        variant="outlined"
        :data-testid="`admin-push-outcome-${chip}`"
      >
        {{ t(`admin.operations.push_deliveries.outcomes.${chip}`) }}
      </v-chip>
    </v-chip-group>

    <v-row dense class="mb-2">
      <v-col cols="12" sm="3">
        <v-select
          v-model="filters.notification_type"
          :items="typeItems"
          item-title="title"
          item-value="value"
          :label="t('admin.operations.push_deliveries.filters.type')"
          density="compact"
          variant="outlined"
          hide-details
          clearable
          data-testid="admin-push-filter-type"
        />
      </v-col>
      <v-col cols="12" sm="3">
        <v-text-field
          v-model="filters.creator"
          :label="t('admin.operations.push_deliveries.filters.creator')"
          :hint="creatorName ?? undefined"
          :persistent-hint="creatorName !== null"
          density="compact"
          variant="outlined"
          clearable
          :hide-details="creatorName === null"
          data-testid="admin-push-filter-creator"
          @update:model-value="creatorName = null"
          @click:clear="clearCreator"
        />
      </v-col>
      <v-col cols="6" sm="2">
        <v-text-field
          v-model="filters.date_from"
          :label="t('admin.audit.filters.date_from')"
          type="date"
          density="compact"
          variant="outlined"
          hide-details
          data-testid="admin-push-filter-date-from"
        />
      </v-col>
      <v-col cols="6" sm="2">
        <v-text-field
          v-model="filters.date_to"
          :label="t('admin.audit.filters.date_to')"
          type="date"
          density="compact"
          variant="outlined"
          hide-details
          data-testid="admin-push-filter-date-to"
        />
      </v-col>
      <v-col cols="12" sm="2" class="d-flex align-center">
        <v-btn
          color="primary"
          variant="flat"
          block
          :loading="loading"
          data-testid="admin-push-apply"
          @click="applyFilters"
        >
          {{ t('admin.audit.filters.apply') }}
        </v-btn>
      </v-col>
    </v-row>

    <v-alert
      v-if="errorKey"
      type="error"
      variant="tonal"
      class="mb-4"
      data-testid="admin-push-error"
    >
      {{ errorText }}
    </v-alert>

    <v-table density="compact" data-testid="admin-push-table">
      <thead>
        <tr>
          <th>{{ t('admin.audit.fields.created_at') }}</th>
          <th>{{ t('admin.operations.push_deliveries.columns.creator') }}</th>
          <th>{{ t('admin.operations.push_deliveries.columns.type') }}</th>
          <th>{{ t('admin.operations.push_deliveries.columns.platform') }}</th>
          <th>{{ t('admin.operations.push_deliveries.columns.outcome') }}</th>
          <th>{{ t('admin.operations.push_deliveries.columns.attempt') }}</th>
          <th>{{ t('admin.operations.push_deliveries.columns.detail') }}</th>
          <th>{{ t('admin.operations.push_deliveries.columns.in_app') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-if="items.length === 0 && !loading && !errorKey">
          <td colspan="8" class="text-medium-emphasis" data-testid="admin-push-empty">
            {{ t('admin.operations.push_deliveries.empty') }}
          </td>
        </tr>
        <tr v-for="row in items" :key="row.id" :data-testid="`admin-push-row-${row.id}`">
          <td>{{ formatDateTime(row.attributes.created_at, locale) }}</td>
          <td>
            <div>
              {{ creatorLabel(row.attributes.creator, t, '—') }}
              <DeletionRequestedChip :account="row.attributes.creator" />
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ creatorEmail(row.attributes.creator) ?? '' }}
            </div>
          </td>
          <td>
            <v-chip size="x-small" variant="tonal">{{ row.attributes.notification_type }}</v-chip>
          </td>
          <td>{{ row.attributes.platform === 'ios' ? 'iOS' : 'Android' }}</td>
          <td>
            <v-chip
              size="x-small"
              variant="tonal"
              :color="outcomeColor(row.attributes.outcome)"
              :data-testid="`admin-push-outcome-chip-${row.id}`"
            >
              {{ t(`admin.operations.push_deliveries.outcomes.${row.attributes.outcome}`) }}
            </v-chip>
          </td>
          <td>
            {{ row.attributes.attempt }}
            <span v-if="row.attributes.final" class="text-caption text-medium-emphasis">
              ({{ t('admin.operations.push_deliveries.attempt_final') }})
            </span>
          </td>
          <td>
            <template
              v-if="row.attributes.code || row.attributes.http_status || row.attributes.detail"
            >
              <div>
                {{ row.attributes.code ?? '' }}
                <span v-if="row.attributes.http_status">{{ row.attributes.http_status }}</span>
              </div>
              <div class="text-caption text-medium-emphasis">{{ row.attributes.detail ?? '' }}</div>
            </template>
            <template v-else>—</template>
          </td>
          <td :data-testid="`admin-push-inapp-${row.id}`">
            {{ t(`admin.operations.push_deliveries.in_app.${row.attributes.in_app}`) }}
          </td>
        </tr>
      </tbody>
    </v-table>

    <div class="d-flex justify-end align-center ga-2 mt-3">
      <v-btn
        variant="text"
        :disabled="cursorHistory.length === 0 || loading"
        data-testid="admin-push-prev"
        @click="goPrev"
      >
        {{ t('admin.audit.pagination.prev') }}
      </v-btn>
      <v-btn
        variant="text"
        :disabled="nextCursor === null || loading"
        data-testid="admin-push-next"
        @click="goNext"
      >
        {{ t('admin.audit.pagination.next') }}
      </v-btn>
    </div>

    <p class="text-caption text-medium-emphasis mt-2" data-testid="admin-push-footer">
      <template v-if="footerText !== null">{{ footerText }} · </template
      >{{ t('admin.operations.push_deliveries.footer.retention') }}
    </p>

    <v-alert
      type="info"
      variant="tonal"
      density="compact"
      class="mt-4"
      :title="t('admin.operations.push_deliveries.counts.title')"
      data-testid="admin-push-counts-panel"
    >
      {{ t('admin.operations.push_deliveries.counts.body') }}
    </v-alert>
  </section>
</template>
