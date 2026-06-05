<script setup lang="ts">
/**
 * Campaign "Invite creators" picker + the TWO-TIER gate UX (Sprint 8 Chunk 2,
 * D-1/D-2/D-5). Mirrors `AddCreatorsToPoolDialog` (roster-sourced multi-select
 * looping a single create) and adds the two distinct gate severities:
 *
 *   - TIER 1 (blacklist, HARD): agency-wide hard-blacklisted rows are DISABLED
 *     in the picker (a badge + a non-selectable checkbox) — the FE can only see
 *     the agency-wide flag (the roster row), so a brand-scoped hard blacklist
 *     is caught by the backend 422 during the loop and REPORTED as skipped.
 *   - TIER 2 (availability, SOFT WARN): a 409 from the backend collects the
 *     conflicted creators; after the loop, a warning modal ("N have availability
 *     conflicts — proceed?") re-submits just those with `acknowledged: true`.
 *
 * Fee: a single agreed fee applied to every selected creator (D-8 — positive
 * minor units; the currency is the campaign's, shown read-only). Per-creator
 * fee override is out of scope this chunk.
 */

import { ApiError } from '@catalyst/api-client'
import type { RosterCreatorListItem } from '@catalyst/api-client'
import { BlacklistBadge } from '@catalyst/ui'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { rosterApi } from '@/modules/roster/api/roster.api'
import { campaignsApi } from '../api/campaigns.api'

const props = defineProps<{
  modelValue: boolean
  agencyId: string
  campaignId: string
  campaignCurrency: string | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  invited: [message: string]
}>()

const { t } = useI18n()

const ROSTER_PER_PAGE = 100

const roster = ref<RosterCreatorListItem[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const submitting = ref(false)
const search = ref('')
const selected = ref<Set<string>>(new Set())

// Fee form — major units in the input, converted to minor on the wire.
const feeAmount = ref<number | null>(null)
const currency = computed(() => props.campaignCurrency ?? 'EUR')

// The availability-warning modal state (TIER 2).
const conflictPrompt = ref(false)
const conflictedIds = ref<string[]>([])

const filtered = computed<RosterCreatorListItem[]>(() => {
  const q = search.value.trim().toLowerCase()
  const base = roster.value
  if (q === '') return base
  return base.filter((row) => (row.attributes.display_name ?? '').toLowerCase().includes(q))
})

/** Agency-wide HARD blacklist → the row is disabled (TIER 1, FE-visible half). */
function isHardBlacklisted(row: RosterCreatorListItem): boolean {
  return (
    row.attributes.is_blacklisted === true && (row.attributes.blacklist_type ?? 'hard') === 'hard'
  )
}

const rosterEmpty = computed(() => roster.value.length === 0)
const feeValid = computed(() => feeAmount.value !== null && feeAmount.value > 0)
const canInvite = computed(() => selected.value.size > 0 && feeValid.value && !submitting.value)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  selected.value = new Set()
  search.value = ''
  feeAmount.value = null
  conflictedIds.value = []
  try {
    const res = await rosterApi.list(props.agencyId, { per_page: ROSTER_PER_PAGE })
    roster.value = res.data
  } catch {
    error.value = t('app.campaigns.invite.loadFailed')
  } finally {
    loading.value = false
  }
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) void load()
  },
  { immediate: true },
)

function close(): void {
  emit('update:modelValue', false)
}

function toggleSelect(creatorId: string, row: RosterCreatorListItem): void {
  if (isHardBlacklisted(row)) return
  const next = new Set(selected.value)
  if (next.has(creatorId)) next.delete(creatorId)
  else next.add(creatorId)
  selected.value = next
}

/** Minor units (D-8) from the major-unit input. */
function feeMinorUnits(): number {
  return Math.round((feeAmount.value ?? 0) * 100)
}

/**
 * Invite one creator. Resolves to a discriminated outcome the loop aggregates:
 *   - 'ok'         created / idempotent no-op
 *   - 'blacklist'  422 (the brand-scoped hard block the FE couldn't see)
 *   - 'conflict'   409 (a hard availability conflict — TIER 2)
 *   - 'error'      anything else
 */
async function inviteOne(
  creatorId: string,
  acknowledged: boolean,
): Promise<'ok' | 'blacklist' | 'conflict' | 'error'> {
  try {
    await campaignsApi.invite(props.agencyId, props.campaignId, {
      creator_id: creatorId,
      agreed_fee_minor_units: feeMinorUnits(),
      agreed_fee_currency: currency.value,
      acknowledged,
    })
    return 'ok'
  } catch (err) {
    if (err instanceof ApiError) {
      if (err.status === 409) return 'conflict'
      if (err.status === 422) return 'blacklist'
    }
    return 'error'
  }
}

/** First pass — no acknowledge. Aggregates outcomes across the selection. */
async function invite(): Promise<void> {
  if (!canInvite.value) return
  submitting.value = true
  error.value = null

  const ids = [...selected.value]
  let ok = 0
  let blacklisted = 0
  const conflicts: string[] = []

  for (const id of ids) {
    const outcome = await inviteOne(id, false)
    if (outcome === 'ok') ok++
    else if (outcome === 'blacklist') blacklisted++
    else if (outcome === 'conflict') conflicts.push(id)
  }

  submitting.value = false

  if (conflicts.length > 0) {
    // TIER 2 — surface the aggregate availability warning; the agency decides.
    conflictedIds.value = conflicts
    conflictPrompt.value = true
    // Carry the first-pass tallies into the summary the modal will finalise.
    pendingOk.value = ok
    pendingBlacklisted.value = blacklisted
    return
  }

  finish(ok, blacklisted, 0)
}

// Tallies carried from the first pass into the acknowledge step.
const pendingOk = ref(0)
const pendingBlacklisted = ref(0)

/** TIER 2 proceed — re-invite the conflicted creators with acknowledged:true. */
async function proceedWithConflicts(): Promise<void> {
  conflictPrompt.value = false
  submitting.value = true
  let ok = pendingOk.value
  for (const id of conflictedIds.value) {
    const outcome = await inviteOne(id, true)
    if (outcome === 'ok') ok++
  }
  submitting.value = false
  finish(ok, pendingBlacklisted.value, 0)
}

function cancelConflicts(): void {
  conflictPrompt.value = false
  // The conflicted creators were NOT invited; report what DID go through.
  finish(pendingOk.value, pendingBlacklisted.value, conflictedIds.value.length)
}

function finish(ok: number, blacklisted: number, skippedConflicts: number): void {
  emit(
    'invited',
    t('app.campaigns.invite.summary', { ok, blacklisted, conflicts: skippedConflicts }),
  )
  emit('update:modelValue', false)
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="520"
    data-test="invite-creators-dialog"
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <v-card>
      <v-card-title class="text-h6 pa-4 d-flex align-center justify-space-between">
        {{ t('app.campaigns.invite.title') }}
        <v-btn
          icon="mdi-close"
          variant="text"
          size="small"
          data-test="invite-creators-close"
          @click="close"
        />
      </v-card-title>

      <v-card-text>
        <v-alert
          v-if="error"
          type="error"
          variant="tonal"
          class="mb-3"
          data-test="invite-creators-error"
        >
          {{ error }}
        </v-alert>

        <!-- The single agreed fee applied to every selected creator (D-8). -->
        <v-text-field
          v-model.number="feeAmount"
          type="number"
          min="0"
          step="0.01"
          density="compact"
          variant="outlined"
          class="mb-3"
          :label="t('app.campaigns.invite.feeLabel', { currency })"
          :suffix="currency"
          data-test="invite-creators-fee"
        />

        <v-text-field
          v-if="!loading && !rosterEmpty"
          v-model="search"
          density="compact"
          variant="outlined"
          hide-details
          clearable
          prepend-inner-icon="mdi-magnify"
          class="mb-3"
          :label="t('app.campaigns.invite.search')"
          data-test="invite-creators-search"
        />

        <v-skeleton-loader
          v-if="loading"
          type="list-item-avatar@3"
          data-test="invite-creators-skeleton"
        />

        <div
          v-else-if="rosterEmpty"
          class="text-body-2 text-medium-emphasis py-4"
          data-test="invite-creators-empty"
        >
          {{ t('app.campaigns.invite.noRoster') }}
        </div>

        <div
          v-else-if="filtered.length === 0"
          class="text-body-2 text-medium-emphasis py-4"
          data-test="invite-creators-no-match"
        >
          {{ t('app.campaigns.invite.noSearchMatch') }}
        </div>

        <v-list v-else data-test="invite-creators-list">
          <v-list-item
            v-for="row in filtered"
            :key="row.attributes.creator_id ?? row.id"
            :disabled="isHardBlacklisted(row)"
            :data-test="`invite-creators-row-${row.attributes.creator_id}`"
            @click="row.attributes.creator_id && toggleSelect(row.attributes.creator_id, row)"
          >
            <template #prepend>
              <v-avatar size="40" color="surface-variant">
                <span class="text-caption">
                  {{ (row.attributes.display_name ?? '?')[0]?.toUpperCase() }}
                </span>
              </v-avatar>
            </template>
            <v-list-item-title>
              {{ row.attributes.display_name ?? t('app.campaigns.invite.unnamed') }}
              <BlacklistBadge
                v-if="row.attributes.is_blacklisted"
                :type="row.attributes.blacklist_type ?? 'hard'"
                :label="t(`app.roster.blacklist.badge.${row.attributes.blacklist_type ?? 'hard'}`)"
                size="x-small"
                class="ml-2"
                :data-test="`invite-creators-blacklist-${row.attributes.creator_id}`"
              />
            </v-list-item-title>
            <v-list-item-subtitle>
              <span v-if="isHardBlacklisted(row)" data-test="invite-creators-blocked-note">
                {{ t('app.campaigns.invite.blockedHint') }}
              </span>
              <span v-else>{{ row.attributes.country_code ?? '' }}</span>
            </v-list-item-subtitle>
            <template #append>
              <v-checkbox-btn
                :model-value="
                  row.attributes.creator_id !== null && selected.has(row.attributes.creator_id)
                "
                :disabled="isHardBlacklisted(row)"
                :data-test="`invite-creators-checkbox-${row.attributes.creator_id}`"
                @click.stop="
                  row.attributes.creator_id && toggleSelect(row.attributes.creator_id, row)
                "
              />
            </template>
          </v-list-item>
        </v-list>
      </v-card-text>

      <v-card-actions class="px-4 pb-4">
        <v-spacer />
        <v-btn variant="text" data-test="invite-creators-cancel" @click="close">
          {{ t('app.campaigns.invite.cancel') }}
        </v-btn>
        <v-btn
          color="primary"
          variant="flat"
          :disabled="!canInvite"
          :loading="submitting"
          data-test="invite-creators-submit"
          @click="invite"
        >
          {{ t('app.campaigns.invite.submit', { count: selected.size }) }}
        </v-btn>
      </v-card-actions>
    </v-card>

    <!-- TIER 2 — the availability-warning modal (proceed-anyway, D-2). -->
    <v-dialog v-model="conflictPrompt" max-width="420" data-test="invite-availability-warning">
      <v-card>
        <v-card-title class="text-h6">
          {{ t('app.campaigns.invite.conflict.title') }}
        </v-card-title>
        <v-card-text>
          {{ t('app.campaigns.invite.conflict.body', { count: conflictedIds.length }) }}
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" data-test="invite-availability-cancel" @click="cancelConflicts">
            {{ t('app.campaigns.invite.conflict.cancel') }}
          </v-btn>
          <v-btn
            color="primary"
            variant="flat"
            data-test="invite-availability-proceed"
            @click="proceedWithConflicts"
          >
            {{ t('app.campaigns.invite.conflict.proceed') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </v-dialog>
</template>
