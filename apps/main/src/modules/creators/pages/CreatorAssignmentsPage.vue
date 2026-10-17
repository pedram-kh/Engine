<script setup lang="ts">
/**
 * CreatorAssignmentsPage — the creator's campaign-invitation surface (Sprint 8
 * Chunk 2, D-9/D-10). Mirrors the dashboard connection-request inbox pattern:
 * a flat list, per-row accept / decline actions, a re-fetch after a mutation,
 * and a `meta.code`-keyed snackbar.
 *
 *   - accept  → the backend machine flips invited → accepted (which also
 *     auto-blocks the creator's calendar over the posting window, D-11).
 *   - decline → invited → declined.
 *
 * Countering was removed (re-offer-after-decline chunk): the creator only
 * accepts or declines, and the agency re-offers a declined invite with new
 * terms through the invite front-door (same assignment row, same chat thread).
 * Negotiation happens in the shared conversation, not a counter fee-form.
 *
 * Only `invited` rows are actionable; everything else is shown read-only with a
 * status chip (fail-closed server-side too — a stale action 422s).
 */

import { formatCurrency, formatDate, type CreatorAssignmentResource } from '@catalyst/api-client'
import { CEmptyState } from '@catalyst/ui'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import RichBrief from '@/components/RichBrief.vue'
import { isNativePlatform } from '@/core/platform/isNativePlatform'

import CampaignStatusChip from '../components/CampaignStatusChip.vue'
import { creatorAssignmentsApi } from '../assignments.api'

const { t, locale } = useI18n()

const assignments = ref<CreatorAssignmentResource[]>([])
const loading = ref(false)
const loadedOnce = ref(false)
const actioningId = ref<string | null>(null)
const snackbar = ref<{ color: string; text: string } | null>(null)

/** Native (AH-100d, Figma frame 10): the card is built from the offer payload. */
const isNative = isNativePlatform()

const isEmpty = computed(() => loadedOnce.value && assignments.value.length === 0)

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

/** Two-letter brand tile, e.g. "Glow Labs" -> "GL"; falls back to the campaign name. */
function brandInitials(a: CreatorAssignmentResource): string {
  const source = a.attributes.campaign?.brand_name ?? a.attributes.campaign?.name ?? ''
  const words = source.split(/\s+/).filter((w) => w !== '')
  if (words.length === 0) return '—'
  const letters =
    words.length > 1
      ? [Array.from(words[0] as string)[0], Array.from(words[1] as string)[0]]
      : Array.from(words[0] as string).slice(0, 2)
  return letters.join('').toLocaleUpperCase(locale.value)
}

/** "€25.00 per video" — `fee_per` is the agency's own unit text ("per video"). */
function feeLabel(a: CreatorAssignmentResource): string {
  const money = formatMoney(a.attributes.agreed_fee_minor_units, a.attributes.agreed_fee_currency)
  const per = a.attributes.fee_per?.trim()
  return per ? `${money} ${per}` : money
}

function deliverablesLabel(a: CreatorAssignmentResource): string {
  return (a.attributes.deliverables ?? []).filter((d) => d.trim() !== '').join(', ')
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
          <template v-if="isNative">
            <div class="assignment-card__top">
              <span class="assignment-card__tile" aria-hidden="true">{{
                brandInitials(item)
              }}</span>
              <div class="assignment-card__head">
                <span class="assignment__name">{{ item.attributes.campaign?.name ?? '—' }}</span>
                <span v-if="item.attributes.campaign?.brand_name" class="assignment-card__brand">
                  {{ item.attributes.campaign.brand_name }}
                </span>
                <!-- AH-107: the CAMPAIGN's state, in the head column under the brand, never
                     in the row that holds the assignment-status pill (AH-106's squeeze). -->
                <CampaignStatusChip
                  :status="item.attributes.campaign?.status"
                  surface="assignment"
                  :data-testid="`creator-assignment-campaign-status-${item.id}`"
                />
              </div>
              <v-chip
                size="x-small"
                variant="tonal"
                :data-status="item.attributes.status"
                :data-testid="`creator-assignment-status-${item.id}`"
              >
                {{ t(`app.campaigns.assignmentStatus.${item.attributes.status}`) }}
              </v-chip>
            </div>

            <dl class="assignment-card__panel" :data-testid="`creator-assignment-panel-${item.id}`">
              <div class="assignment-card__row" :data-testid="`creator-assignment-fee-${item.id}`">
                <dt>
                  <v-icon icon="mdi-currency-eur" size="15" />{{ t('creator.ui.assignments.fee') }}
                </dt>
                <dd>{{ feeLabel(item) }}</dd>
              </div>
              <div
                v-if="
                  item.attributes.status === 'countered' &&
                  item.attributes.countered_fee_minor_units !== null
                "
                class="assignment-card__row"
                :data-testid="`creator-assignment-countered-${item.id}`"
              >
                <dt>
                  <v-icon icon="mdi-swap-horizontal" size="15" />{{
                    t('creator.ui.assignments.youCountered')
                  }}
                </dt>
                <dd>
                  {{
                    formatMoney(
                      item.attributes.countered_fee_minor_units,
                      item.attributes.countered_fee_currency,
                    )
                  }}
                </dd>
              </div>
              <div
                class="assignment-card__row"
                :data-testid="`creator-assignment-dates-${item.id}`"
              >
                <dt>
                  <v-icon icon="mdi-calendar-blank-outline" size="15" />{{
                    t('creator.ui.assignments.dates')
                  }}
                </dt>
                <dd>{{ windowLabel(item) }}</dd>
              </div>
              <div
                v-if="deliverablesLabel(item) !== ''"
                class="assignment-card__row"
                :data-testid="`creator-assignment-deliverables-${item.id}`"
              >
                <dt>
                  <v-icon icon="mdi-briefcase-outline" size="15" />{{
                    t('creator.ui.assignments.deliverables')
                  }}
                </dt>
                <dd>{{ deliverablesLabel(item) }}</dd>
              </div>
            </dl>

            <div
              v-if="item.attributes.offer_description"
              class="assignment-card__note"
              :data-testid="`creator-assignment-note-${item.id}`"
            >
              <span class="assignment-card__note-label">{{
                t('creator.ui.assignments.note_from_brand')
              }}</span>
              <RichBrief
                :text="item.attributes.offer_description"
                class="assignment__description"
                :data-test="`creator-assignment-description-${item.id}`"
              />
            </div>
            <v-chip
              v-if="item.attributes.offer_attachment?.url"
              size="small"
              variant="tonal"
              prepend-icon="mdi-paperclip"
              :href="item.attributes.offer_attachment.url"
              target="_blank"
              rel="noopener noreferrer"
              :data-test="`creator-assignment-attachment-${item.id}`"
            >
              {{ item.attributes.offer_attachment.name }}
            </v-chip>
          </template>

          <template v-else>
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
              <!-- AH-107: the CAMPAIGN's state on its own line under the title, so it never
                   competes with the assignment-status chip for the title row's width. -->
              <CampaignStatusChip
                class="mt-1"
                :status="item.attributes.campaign?.status"
                surface="assignment"
                :data-testid="`creator-assignment-campaign-status-${item.id}`"
              />
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
              <p class="assignment__meta">{{ windowLabel(item) }}</p>
              <!-- The agency's offer expectations (invite-offer-details batch).
                   Minimal rich text (AH-081): bold/italic/links/breaks only,
                   sanitized by RichBrief — see useRichBriefRenderer.ts. -->
              <RichBrief
                v-if="item.attributes.offer_description"
                :text="item.attributes.offer_description"
                class="assignment__description"
                :data-test="`creator-assignment-description-${item.id}`"
              />
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
          </template>

          <div class="assignment__actions">
            <v-btn
              variant="outlined"
              size="small"
              :to="{ name: 'creator.assignment.detail', params: { ulid: item.id } }"
              :data-testid="`creator-assignment-view-${item.id}`"
            >
              {{
                isNative ? t('creator.ui.assignments.view_brief') : t('creator.ui.assignments.view')
              }}
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
                {{
                  isNative
                    ? t('creator.ui.assignments.accept_invitation')
                    : t('creator.ui.assignments.accept')
                }}
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
  /* RichBrief's own <p>/<br> markup owns line breaks now (AH-081) — pre-wrap
     would additionally render the sanitized HTML's literal whitespace
     between tags, doubling every break into a blank line. */
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
