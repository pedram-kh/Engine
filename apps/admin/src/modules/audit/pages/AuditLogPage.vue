<script setup lang="ts">
/**
 * Admin audit-log viewer (Sprint 13, D-5).
 *
 * Read-only, cross-agency view over the append-only audit_logs table.
 * Filters (action / subject ULID / date range) target indexed columns;
 * the list is CURSOR-paginated (the volume concern) with prev/next tokens
 * surfaced by the backend.
 */

import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { formatDateTime, ApiError } from '@catalyst/api-client'

import { adminAuditApi, type AdminAuditLog } from '../api/audit.api'

const { t, locale } = useI18n()

const items = ref<AdminAuditLog[]>([])
const loading = ref(false)
const errorKey = ref<string | null>(null)

const filters = ref<{ action: string; subject_ulid: string; date_from: string; date_to: string }>({
  action: '',
  subject_ulid: '',
  date_from: '',
  date_to: '',
})

const perPage = 50
const nextCursor = ref<string | null>(null)
const prevCursor = ref<string | null>(null)
// Cursor stack so "previous" walks back through visited pages.
const cursorHistory = ref<string[]>([])

async function fetchPage(cursor: string | null): Promise<void> {
  loading.value = true
  errorKey.value = null
  try {
    const res = await adminAuditApi.list({
      action: filters.value.action || undefined,
      subject_ulid: filters.value.subject_ulid || undefined,
      date_from: filters.value.date_from || undefined,
      date_to: filters.value.date_to || undefined,
      per_page: perPage,
      cursor: cursor ?? undefined,
    })
    items.value = res.data
    nextCursor.value = res.meta.next_cursor
    prevCursor.value = res.meta.prev_cursor
  } catch (error) {
    errorKey.value = error instanceof ApiError ? error.code : 'admin.audit.load_failed'
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
  // Pop the current page's cursor; the new top is the previous page.
  cursorHistory.value.pop()
  const target = cursorHistory.value[cursorHistory.value.length - 1] ?? null
  void fetchPage(target)
}

function formatTimestamp(iso: string): string {
  return formatDateTime(iso, locale.value)
}

onMounted(() => {
  void fetchPage(null)
})
</script>

<template>
  <section data-testid="admin-audit-log">
    <header class="d-flex align-center justify-space-between mb-4">
      <h1 class="text-h5 ma-0">{{ t('admin.audit.title') }}</h1>
    </header>

    <v-row dense class="mb-2">
      <v-col cols="12" sm="3">
        <v-text-field
          v-model="filters.action"
          :label="t('admin.audit.filters.action')"
          density="compact"
          variant="outlined"
          hide-details
          clearable
          data-testid="admin-audit-filter-action"
        />
      </v-col>
      <v-col cols="12" sm="3">
        <v-text-field
          v-model="filters.subject_ulid"
          :label="t('admin.audit.filters.subject')"
          density="compact"
          variant="outlined"
          hide-details
          clearable
          data-testid="admin-audit-filter-subject"
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
          data-testid="admin-audit-filter-date-from"
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
          data-testid="admin-audit-filter-date-to"
        />
      </v-col>
      <v-col cols="12" sm="2" class="d-flex align-center">
        <v-btn
          color="primary"
          variant="flat"
          block
          :loading="loading"
          data-testid="admin-audit-apply"
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
      data-testid="admin-audit-error"
    >
      {{ t(errorKey) }}
    </v-alert>

    <v-table density="compact" data-testid="admin-audit-table">
      <thead>
        <tr>
          <th>{{ t('admin.audit.fields.action') }}</th>
          <th>{{ t('admin.audit.fields.actor') }}</th>
          <th>{{ t('admin.audit.fields.subject') }}</th>
          <th>{{ t('admin.audit.fields.reason') }}</th>
          <th>{{ t('admin.audit.fields.created_at') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-if="items.length === 0 && !loading">
          <td colspan="5" class="text-medium-emphasis" data-testid="admin-audit-empty">
            {{ t('admin.audit.empty') }}
          </td>
        </tr>
        <tr v-for="row in items" :key="row.id" :data-testid="`admin-audit-row-${row.id}`">
          <td>
            <v-chip size="x-small" variant="tonal">{{ row.attributes.action }}</v-chip>
          </td>
          <td>{{ row.attributes.actor_name ?? row.attributes.actor_email ?? '—' }}</td>
          <td>{{ row.attributes.subject_ulid ?? '—' }}</td>
          <td>{{ row.attributes.reason ?? '—' }}</td>
          <td>{{ formatTimestamp(row.attributes.created_at) }}</td>
        </tr>
      </tbody>
    </v-table>

    <div class="d-flex justify-end align-center ga-2 mt-3">
      <v-btn
        variant="text"
        :disabled="cursorHistory.length === 0 || loading"
        data-testid="admin-audit-prev"
        @click="goPrev"
      >
        {{ t('admin.audit.pagination.prev') }}
      </v-btn>
      <v-btn
        variant="text"
        :disabled="nextCursor === null || loading"
        data-testid="admin-audit-next"
        @click="goNext"
      >
        {{ t('admin.audit.pagination.next') }}
      </v-btn>
    </div>
  </section>
</template>
