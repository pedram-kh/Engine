<script setup lang="ts">
/**
 * Admin impersonation log (Sprint 13, D-9).
 *
 * Read-only, cross-agency view over the append-only
 * admin_impersonation_sessions table — the §6.8 log of record. Mirrors the
 * audit-log viewer: status / search / date-range filters and CURSOR-based
 * prev/next pagination over the opaque tokens the backend returns.
 *
 * Cross-agency BY DESIGN — the platform_admin bounded bypass; the backend
 * enforces the gate and this page just renders what it returns.
 */

import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { formatDateTime, ApiError } from '@catalyst/api-client'

import {
  impersonationApi,
  type ImpersonationLogEntry,
  type ImpersonationLogStatusFilter,
  type ImpersonationSessionStatus,
} from '../api/impersonation.api'

const { t, locale } = useI18n()

const items = ref<ImpersonationLogEntry[]>([])
const loading = ref(false)
const errorKey = ref<string | null>(null)

const filters = ref<{
  status: ImpersonationLogStatusFilter
  q: string
  date_from: string
  date_to: string
}>({
  status: 'all',
  q: '',
  date_from: '',
  date_to: '',
})

const statusFilterItems: { title: string; value: ImpersonationLogStatusFilter }[] = [
  { title: t('admin.support.impersonation_log.status_filter.all'), value: 'all' },
  { title: t('admin.support.impersonation_log.status_filter.active'), value: 'active' },
  { title: t('admin.support.impersonation_log.status_filter.ended'), value: 'ended' },
  { title: t('admin.support.impersonation_log.status_filter.expired'), value: 'expired' },
]

const perPage = 50
const nextCursor = ref<string | null>(null)
const prevCursor = ref<string | null>(null)
// Cursor stack so "previous" walks back through visited pages.
const cursorHistory = ref<string[]>([])

const statusColors: Record<ImpersonationSessionStatus, string> = {
  active: 'success',
  ended: 'default',
  expired: 'warning',
}

async function fetchPage(cursor: string | null): Promise<void> {
  loading.value = true
  errorKey.value = null
  try {
    const res = await impersonationApi.sessions({
      status: filters.value.status,
      q: filters.value.q || undefined,
      date_from: filters.value.date_from || undefined,
      date_to: filters.value.date_to || undefined,
      per_page: perPage,
      cursor: cursor ?? undefined,
    })
    items.value = res.data
    nextCursor.value = res.meta.next_cursor
    prevCursor.value = res.meta.prev_cursor
  } catch (error) {
    errorKey.value =
      error instanceof ApiError ? error.code : 'admin.support.impersonation_log.load_failed'
  } finally {
    loading.value = false
  }
}

function applyFilters(): void {
  cursorHistory.value = []
  void fetchPage(null)
}

function goNext(): void {
  if (nextCursor.value === null) return
  cursorHistory.value.push(nextCursor.value)
  void fetchPage(nextCursor.value)
}

function goPrev(): void {
  cursorHistory.value.pop()
  const target = cursorHistory.value[cursorHistory.value.length - 1] ?? null
  void fetchPage(target)
}

function statusLabel(status: ImpersonationSessionStatus): string {
  return t(`admin.support.impersonation_log.statuses.${status}`)
}

function formatTimestamp(iso: string | null): string {
  return formatDateTime(iso, locale.value)
}

onMounted(() => {
  void fetchPage(null)
})
</script>

<template>
  <section data-testid="admin-impersonation-log">
    <header class="d-flex align-center justify-space-between mb-1">
      <h1 class="text-h5 ma-0">{{ t('admin.support.impersonation_log.title') }}</h1>
    </header>
    <p class="text-body-2 text-medium-emphasis mb-4">
      {{ t('admin.support.impersonation_log.subtitle') }}
    </p>

    <v-row dense class="mb-2">
      <v-col cols="12" sm="3">
        <v-select
          v-model="filters.status"
          :items="statusFilterItems"
          :label="t('admin.support.impersonation_log.filters.status')"
          density="compact"
          variant="outlined"
          hide-details
          data-testid="admin-impersonation-filter-status"
        />
      </v-col>
      <v-col cols="12" sm="3">
        <v-text-field
          v-model="filters.q"
          :label="t('admin.support.impersonation_log.filters.search')"
          density="compact"
          variant="outlined"
          hide-details
          clearable
          prepend-inner-icon="mdi-magnify"
          data-testid="admin-impersonation-filter-search"
        />
      </v-col>
      <v-col cols="6" sm="2">
        <v-text-field
          v-model="filters.date_from"
          :label="t('admin.support.impersonation_log.filters.date_from')"
          type="date"
          density="compact"
          variant="outlined"
          hide-details
          data-testid="admin-impersonation-filter-date-from"
        />
      </v-col>
      <v-col cols="6" sm="2">
        <v-text-field
          v-model="filters.date_to"
          :label="t('admin.support.impersonation_log.filters.date_to')"
          type="date"
          density="compact"
          variant="outlined"
          hide-details
          data-testid="admin-impersonation-filter-date-to"
        />
      </v-col>
      <v-col cols="12" sm="2" class="d-flex align-center">
        <v-btn
          color="primary"
          variant="flat"
          block
          :loading="loading"
          data-testid="admin-impersonation-apply"
          @click="applyFilters"
        >
          {{ t('admin.support.impersonation_log.filters.apply') }}
        </v-btn>
      </v-col>
    </v-row>

    <v-alert
      v-if="errorKey"
      type="error"
      variant="tonal"
      class="mb-4"
      data-testid="admin-impersonation-error"
    >
      {{ t(errorKey) }}
    </v-alert>

    <v-table density="compact" data-testid="admin-impersonation-table">
      <thead>
        <tr>
          <th>{{ t('admin.support.impersonation_log.fields.admin') }}</th>
          <th>{{ t('admin.support.impersonation_log.fields.impersonated_user') }}</th>
          <th>{{ t('admin.support.impersonation_log.fields.reason') }}</th>
          <th>{{ t('admin.support.impersonation_log.fields.status') }}</th>
          <th>{{ t('admin.support.impersonation_log.fields.started_at') }}</th>
          <th>{{ t('admin.support.impersonation_log.fields.ended_at') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-if="items.length === 0 && !loading">
          <td colspan="6" class="text-medium-emphasis" data-testid="admin-impersonation-empty">
            {{ t('admin.support.impersonation_log.empty') }}
          </td>
        </tr>
        <tr v-for="row in items" :key="row.id" :data-testid="`admin-impersonation-row-${row.id}`">
          <td>{{ row.attributes.admin_name ?? row.attributes.admin_email ?? '—' }}</td>
          <td>
            {{
              row.attributes.impersonated_user_name ?? row.attributes.impersonated_user_email ?? '—'
            }}
          </td>
          <td>{{ row.attributes.reason }}</td>
          <td>
            <v-chip size="x-small" variant="tonal" :color="statusColors[row.attributes.status]">
              {{ statusLabel(row.attributes.status) }}
            </v-chip>
          </td>
          <td>{{ formatTimestamp(row.attributes.started_at) }}</td>
          <td>{{ formatTimestamp(row.attributes.ended_at) }}</td>
        </tr>
      </tbody>
    </v-table>

    <div class="d-flex justify-end align-center ga-2 mt-3">
      <v-btn
        variant="text"
        :disabled="cursorHistory.length === 0 || loading"
        data-testid="admin-impersonation-prev"
        @click="goPrev"
      >
        {{ t('admin.support.impersonation_log.pagination.prev') }}
      </v-btn>
      <v-btn
        variant="text"
        :disabled="nextCursor === null || loading"
        data-testid="admin-impersonation-next"
        @click="goNext"
      >
        {{ t('admin.support.impersonation_log.pagination.next') }}
      </v-btn>
    </div>
  </section>
</template>
