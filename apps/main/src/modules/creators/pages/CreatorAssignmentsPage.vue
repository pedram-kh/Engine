<script setup lang="ts">
/**
 * CreatorAssignmentsPage — the creator's campaign-invitation surface (Sprint 8
 * Chunk 2, D-9/D-10). Mirrors the dashboard connection-request inbox pattern:
 * a flat list, per-row accept / decline / counter actions, a re-fetch after a
 * mutation, and a `meta.code`-keyed snackbar.
 *
 *   - accept  → the backend machine flips invited → accepted (which also
 *     auto-blocks the creator's calendar over the posting window, D-11).
 *   - decline → invited → declined.
 *   - counter → invited → countered with a proposed fee (a small dialog; the
 *     currency is the campaign's, shown read-only — D-8).
 *
 * Only `invited` rows are actionable; everything else is shown read-only with a
 * status chip (fail-closed server-side too — a stale action 422s).
 */

import {
  formatCurrency,
  formatDate,
  ApiError,
  extractFieldErrors,
  type CreatorAssignmentResource,
} from '@catalyst/api-client'
import { CEmptyState } from '@catalyst/ui'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { creatorAssignmentsApi } from '../assignments.api'

type CounterField = 'countered_fee_minor_units' | 'countered_fee_currency'

const { t, locale } = useI18n()

const assignments = ref<CreatorAssignmentResource[]>([])
const loading = ref(false)
const loadedOnce = ref(false)
const actioningId = ref<string | null>(null)
const snackbar = ref<{ color: string; text: string } | null>(null)

// Counter dialog state.
const counterOpen = ref(false)
const counterTarget = ref<CreatorAssignmentResource | null>(null)
const counterAmount = ref<number | null>(null)
const counterFieldErrors = ref<Partial<Record<CounterField, readonly string[]>>>({})

const isEmpty = computed(() => loadedOnce.value && assignments.value.length === 0)

const counterCurrency = computed(() => counterTarget.value?.attributes.agreed_fee_currency ?? 'EUR')
const counterValid = computed(() => counterAmount.value !== null && counterAmount.value > 0)

function formatMoney(minor: number | null, currency: string | null): string {
  return formatCurrency(minor, currency, locale.value)
}

function windowLabel(a: CreatorAssignmentResource): string {
  const start = a.attributes.campaign?.posting_window_starts_at
  const end = a.attributes.campaign?.posting_window_ends_at
  if (!start || !end) return t('creator.ui.assignments.window.unset')
  return t('creator.ui.assignments.window.range', {
    start: formatDate(start, locale.value),
    end: formatDate(end, locale.value),
  })
}

async function load(): Promise<void> {
  loading.value = true
  try {
    const res = await creatorAssignmentsApi.list()
    assignments.value = res.data
  } catch {
    assignments.value = []
  } finally {
    loading.value = false
    loadedOnce.value = true
  }
}

function snackbarFor(code: string): { color: string; text: string } {
  switch (code) {
    case 'assignment.accepted':
      return { color: 'success', text: t('creator.ui.assignments.toast.accepted') }
    case 'assignment.countered':
      return { color: 'info', text: t('creator.ui.assignments.toast.countered') }
    case 'assignment.declined':
    default:
      return { color: 'info', text: t('creator.ui.assignments.toast.declined') }
  }
}

async function accept(item: CreatorAssignmentResource): Promise<void> {
  if (actioningId.value !== null) return
  actioningId.value = item.id
  try {
    const res = await creatorAssignmentsApi.accept(item.id)
    snackbar.value = snackbarFor(res.meta.code)
    await load()
  } catch {
    snackbar.value = { color: 'error', text: t('creator.ui.assignments.toast.error') }
  } finally {
    actioningId.value = null
  }
}

async function decline(item: CreatorAssignmentResource): Promise<void> {
  if (actioningId.value !== null) return
  actioningId.value = item.id
  try {
    const res = await creatorAssignmentsApi.decline(item.id)
    snackbar.value = snackbarFor(res.meta.code)
    await load()
  } catch {
    snackbar.value = { color: 'error', text: t('creator.ui.assignments.toast.error') }
  } finally {
    actioningId.value = null
  }
}

function openCounter(item: CreatorAssignmentResource): void {
  counterTarget.value = item
  counterAmount.value = null
  counterFieldErrors.value = {}
  counterOpen.value = true
}

async function submitCounter(): Promise<void> {
  const target = counterTarget.value
  if (target === null || !counterValid.value) return
  actioningId.value = target.id
  counterFieldErrors.value = {}
  try {
    const res = await creatorAssignmentsApi.counter(target.id, {
      countered_fee_minor_units: Math.round((counterAmount.value ?? 0) * 100),
      countered_fee_currency: counterCurrency.value,
    })
    counterOpen.value = false
    snackbar.value = snackbarFor(res.meta.code)
    await load()
  } catch (err) {
    // Per-field 422 binding (form-error allowlist): a fee that fails the
    // backend rule (non-positive, or currency ≠ campaign currency) lands on
    // the amount field; anything else falls back to the generic toast.
    if (err instanceof ApiError) {
      counterFieldErrors.value = extractFieldErrors<CounterField>(err)
    }
    if (Object.keys(counterFieldErrors.value).length === 0) {
      counterOpen.value = false
      snackbar.value = { color: 'error', text: t('creator.ui.assignments.toast.error') }
    }
  } finally {
    actioningId.value = null
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <section class="creator-assignments" data-testid="creator-assignments">
    <header>
      <h1 class="text-h4">{{ t('creator.ui.assignments.title') }}</h1>
      <p class="text-body-1 text-medium-emphasis">{{ t('creator.ui.assignments.subtitle') }}</p>
    </header>

    <v-skeleton-loader
      v-if="loading && !loadedOnce"
      type="list-item-two-line, list-item-two-line"
      data-testid="creator-assignments-skeleton"
    />

    <template v-else>
      <div v-if="!isEmpty" class="assignments" data-testid="creator-assignments-list">
        <article
          v-for="item in assignments"
          :key="item.id"
          class="assignment"
          :data-testid="`creator-assignment-${item.id}`"
        >
          <div class="assignment__info">
            <div class="assignment__title">
              <span class="assignment__name">{{ item.attributes.campaign?.name ?? '—' }}</span>
              <v-chip
                size="x-small"
                variant="tonal"
                :data-testid="`creator-assignment-status-${item.id}`"
              >
                {{ t(`app.campaigns.assignmentStatus.${item.attributes.status}`) }}
              </v-chip>
            </div>
            <p class="assignment__meta">
              <span v-if="item.attributes.campaign?.brand_name">
                {{ item.attributes.campaign.brand_name }} ·
              </span>
              {{ t('creator.ui.assignments.fee') }}:
              {{
                formatMoney(
                  item.attributes.agreed_fee_minor_units,
                  item.attributes.agreed_fee_currency,
                )
              }}<span
                v-if="item.attributes.fee_per"
                :data-test="`creator-assignment-fee-per-${item.id}`"
              >
                / {{ item.attributes.fee_per }}</span
              >
              · {{ windowLabel(item) }}
              <span
                v-if="
                  item.attributes.status === 'countered' &&
                  item.attributes.countered_fee_minor_units !== null
                "
                :data-testid="`creator-assignment-countered-${item.id}`"
              >
                · {{ t('creator.ui.assignments.youCountered') }}:
                {{
                  formatMoney(
                    item.attributes.countered_fee_minor_units,
                    item.attributes.countered_fee_currency,
                  )
                }}
              </span>
            </p>
            <!-- The agency's offer expectations (invite-offer-details batch). -->
            <p
              v-if="item.attributes.offer_description"
              class="assignment__description"
              :data-test="`creator-assignment-description-${item.id}`"
            >
              {{ item.attributes.offer_description }}
            </p>
            <!-- The one optional offer attachment — the chip label is the
                 agency-given file name, opened via the short-lived signed URL. -->
            <v-chip
              v-if="item.attributes.offer_attachment?.url"
              size="small"
              variant="tonal"
              prepend-icon="mdi-paperclip"
              class="mt-1"
              :href="item.attributes.offer_attachment.url"
              target="_blank"
              rel="noopener noreferrer"
              :data-test="`creator-assignment-attachment-${item.id}`"
            >
              {{ item.attributes.offer_attachment.name }}
            </v-chip>
          </div>

          <div class="assignment__actions">
            <v-btn
              variant="outlined"
              size="small"
              :to="{ name: 'creator.assignment.detail', params: { ulid: item.id } }"
              :data-testid="`creator-assignment-view-${item.id}`"
            >
              {{ t('creator.ui.assignments.view') }}
            </v-btn>
            <template v-if="item.attributes.status === 'invited'">
              <v-btn
                color="primary"
                variant="flat"
                size="small"
                :loading="actioningId === item.id"
                :disabled="actioningId !== null && actioningId !== item.id"
                :data-testid="`creator-assignment-accept-${item.id}`"
                @click="accept(item)"
              >
                {{ t('creator.ui.assignments.accept') }}
              </v-btn>
              <v-btn
                variant="tonal"
                size="small"
                :loading="actioningId === item.id"
                :disabled="actioningId !== null && actioningId !== item.id"
                :data-testid="`creator-assignment-counter-${item.id}`"
                @click="openCounter(item)"
              >
                {{ t('creator.ui.assignments.counter') }}
              </v-btn>
              <v-btn
                color="error"
                variant="outlined"
                size="small"
                :loading="actioningId === item.id"
                :disabled="actioningId !== null && actioningId !== item.id"
                :data-testid="`creator-assignment-decline-${item.id}`"
                @click="decline(item)"
              >
                {{ t('creator.ui.assignments.decline') }}
              </v-btn>
            </template>
          </div>
        </article>
      </div>

      <CEmptyState
        v-else
        data-test="creator-assignments-empty"
        :title="t('creator.ui.assignments.empty.title')"
        :body="t('creator.ui.assignments.empty.body')"
      >
        <template #icon>
          <v-icon icon="mdi-clipboard-text-outline" size="64" color="medium-emphasis" />
        </template>
      </CEmptyState>
    </template>

    <!-- Counter fee dialog (D-8 — positive amount; campaign currency, read-only). -->
    <v-dialog v-model="counterOpen" max-width="420" data-testid="creator-assignment-counter-dialog">
      <v-card>
        <v-card-title class="text-h6">{{
          t('creator.ui.assignments.counterDialog.title')
        }}</v-card-title>
        <v-card-text>
          <p class="text-body-2 text-medium-emphasis mb-3">
            {{ t('creator.ui.assignments.counterDialog.body') }}
          </p>
          <v-text-field
            v-model.number="counterAmount"
            type="number"
            min="0"
            step="0.01"
            density="compact"
            variant="outlined"
            :label="
              t('creator.ui.assignments.counterDialog.feeLabel', { currency: counterCurrency })
            "
            :suffix="counterCurrency"
            :error-messages="counterFieldErrors.countered_fee_minor_units as string[]"
            data-testid="creator-assignment-counter-amount"
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn
            variant="text"
            data-testid="creator-assignment-counter-cancel"
            @click="counterOpen = false"
          >
            {{ t('creator.ui.assignments.counterDialog.cancel') }}
          </v-btn>
          <v-btn
            color="primary"
            variant="flat"
            :disabled="!counterValid"
            data-testid="creator-assignment-counter-submit"
            @click="submitCounter"
          >
            {{ t('creator.ui.assignments.counterDialog.submit') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar
      :model-value="snackbar !== null"
      :timeout="3000"
      :color="snackbar?.color"
      data-testid="creator-assignments-snackbar"
      @update:model-value="
        (v) => {
          if (!v) snackbar = null
        }
      "
    >
      {{ snackbar?.text }}
    </v-snackbar>
  </section>
</template>

<style scoped>
.creator-assignments {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.assignments {
  display: flex;
  flex-direction: column;
}

/* Desktop: text on the left, actions on the right. */
.assignment {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 16px 0;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}

.assignment__info {
  min-width: 0;
}

.assignment__title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 600;
}

.assignment__name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.assignment__meta {
  margin: 4px 0 0;
  font-size: 0.875rem;
  opacity: 0.75;
}

.assignment__description {
  margin: 4px 0 0;
  font-size: 0.875rem;
  opacity: 0.75;
  white-space: pre-wrap;
  word-break: break-word;
}

.assignment__actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}

/* Mobile: stack the name + description, then the actions in one row below,
   each button sharing the width equally so all fit on a single line. */
@media (max-width: 599px) {
  .assignment {
    flex-direction: column;
    align-items: stretch;
    gap: 12px;
  }

  .assignment__name {
    white-space: normal;
  }

  .assignment__actions > * {
    flex: 1 1 0;
    min-width: 0;
  }
}
</style>
