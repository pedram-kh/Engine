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
 *
 * Search is SERVER-side (`?q=`, debounced) rather than a filter over one
 * fetched page — see {@link fetchRoster} for why the local filter was a
 * correctness bug, not just a slow path.
 *
 * Offer context (invite-offer-details batch): the fee, the free-text "Per" unit,
 * the offer description and ONE optional attachment are all batch-wide,
 * mirroring the single fee — and all of them now live in the shared
 * {@link OfferFieldsForm} child (AH-058, Q2), which this dialog drives through
 * `buildOffer()`. The file is uploaded ONCE there before the invite loop; every
 * invite then carries the same attachment block.
 */

import { ApiError } from '@catalyst/api-client'
import type { RosterCreatorListItem, RosterListParams } from '@catalyst/api-client'
import { BlacklistBadge } from '@catalyst/ui'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { rosterApi } from '@/modules/roster/api/roster.api'
import { campaignsApi } from '../api/campaigns.api'
import OfferFieldsForm from './OfferFieldsForm.vue'

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
const SEARCH_DEBOUNCE_MS = 300

const roster = ref<RosterCreatorListItem[]>([])
const loading = ref(false)
// A search re-query, as distinct from the initial open. It drives the field's
// own spinner rather than the skeleton, so the input never unmounts mid-type.
const searching = ref(false)
const error = ref<string | null>(null)
const submitting = ref(false)
// `clearable` hands back null on clear, so this is nullable and every read
// goes through `searchTerm`.
const search = ref<string | null>('')
const selected = ref<Set<string>>(new Set())
// Whether the agency has NO roster at all, captured on the unfiltered open
// load. Kept separate from `roster.length === 0` so a search that matches
// nothing can never masquerade as "you have no creators" — which would also
// hide the search field and trap the user in the empty state.
const rosterEmptyUnfiltered = ref(false)

const currency = computed(() => props.campaignCurrency ?? 'EUR')

// The shared offer form owns the fee, the "per" unit, the description and the
// one attachment — including its single upload. This dialog only asks it for a
// payload and for whether the fee is usable yet.
const offerFields = ref<InstanceType<typeof OfferFieldsForm> | null>(null)
const offerValid = ref(false)
// The batch-wide offer, built once per submission and reused by the TIER-2
// acknowledge pass so the attachment is never uploaded twice.
type BuiltOffer = Awaited<ReturnType<InstanceType<typeof OfferFieldsForm>['buildOffer']>>
const offer = ref<BuiltOffer>(null)

// The availability-warning modal state (TIER 2).
const conflictPrompt = ref(false)
const conflictedIds = ref<string[]>([])

const searchTerm = computed(() => (search.value ?? '').trim())

/** Agency-wide HARD blacklist → the row is disabled (TIER 1, FE-visible half). */
function isHardBlacklisted(row: RosterCreatorListItem): boolean {
  return (
    row.attributes.is_blacklisted === true && (row.attributes.blacklist_type ?? 'hard') === 'hard'
  )
}

const canInvite = computed(() => selected.value.size > 0 && offerValid.value && !submitting.value)

/**
 * The roster query. Search runs SERVER-side (`?q=`), mirroring
 * `CreatorRosterPage` — it used to filter one fetched page in the browser,
 * which silently capped the picker at the first `ROSTER_PER_PAGE` creators by
 * display_name. On a roster larger than that page the tail of the alphabet was
 * not merely unsearchable but unreachable: absent from the list, so no amount
 * of scrolling reached them and they could never be invited.
 *
 * Responses are sequence-guarded: with a debounce in front of an async call,
 * a slow early query can still land after a fast later one, so only the newest
 * request is allowed to write.
 */
let requestSeq = 0
let lastFetchedTerm: string | null = null

async function fetchRoster(term: string): Promise<void> {
  const seq = ++requestSeq
  const params: RosterListParams = { per_page: ROSTER_PER_PAGE }
  if (term !== '') params.q = term

  try {
    const res = await rosterApi.list(props.agencyId, params)
    if (seq !== requestSeq) return
    lastFetchedTerm = term
    roster.value = res.data
    // Only an UNFILTERED read can tell us the roster is genuinely empty.
    if (term === '') rosterEmptyUnfiltered.value = res.data.length === 0
  } catch {
    if (seq !== requestSeq) return
    error.value = t('app.campaigns.invite.loadFailed')
    roster.value = []
  }
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  selected.value = new Set()
  search.value = ''
  offerFields.value?.reset()
  offer.value = null
  conflictedIds.value = []
  await fetchRoster('')
  loading.value = false
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) void load()
  },
  { immediate: true },
)

// Debounced server search — fires 300ms after the last keystroke, mirroring
// CreatorRosterPage. Re-queries are skipped when the term is already the one
// on screen, which also absorbs the reset-to-empty that `load()` performs on
// open (and a type-then-backspace round trip).
let searchTimer: ReturnType<typeof setTimeout> | null = null

watch(searchTerm, (term) => {
  if (searchTimer !== null) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    if (term === lastFetchedTerm) return
    searching.value = true
    void fetchRoster(term).finally(() => {
      searching.value = false
    })
  }, SEARCH_DEBOUNCE_MS)
})

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
  const built = offer.value
  if (built === null) return 'error'

  try {
    await campaignsApi.invite(props.agencyId, props.campaignId, {
      ...built,
      creator_id: creatorId,
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

  // The one attachment upload precedes the loop; a failed upload aborts the
  // whole submission (never a half-attached batch). The built payload is then
  // reused for every creator, and for the acknowledge pass.
  offer.value = (await offerFields.value?.buildOffer()) ?? null
  if (offer.value === null) {
    submitting.value = false
    return
  }

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

        <!-- The shared offer form (Q2): fee + per + description + attachment,
             batch-wide. The `invite-creators` prefix keeps this dialog's
             existing selectors after the extraction. -->
        <OfferFieldsForm
          ref="offerFields"
          :agency-id="agencyId"
          :campaign-id="campaignId"
          :currency="currency"
          test-prefix="invite-creators"
          @update:valid="(v) => (offerValid = v)"
        />

        <!-- Stays mounted whenever a roster exists — including while a search
             matches nothing, which is exactly when the user needs to edit or
             clear the term. -->
        <v-text-field
          v-if="!loading && !rosterEmptyUnfiltered"
          v-model="search"
          density="compact"
          variant="outlined"
          hide-details
          clearable
          :loading="searching"
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
          v-else-if="rosterEmptyUnfiltered"
          class="text-body-2 text-medium-emphasis py-4"
          data-test="invite-creators-empty"
        >
          {{ t('app.campaigns.invite.noRoster') }}
        </div>

        <div
          v-else-if="roster.length === 0"
          class="text-body-2 text-medium-emphasis py-4"
          data-test="invite-creators-no-match"
        >
          {{ t('app.campaigns.invite.noSearchMatch') }}
        </div>

        <v-list v-else data-test="invite-creators-list">
          <v-list-item
            v-for="row in roster"
            :key="row.attributes.creator_id ?? row.id"
            :disabled="isHardBlacklisted(row)"
            :data-test="`invite-creators-row-${row.attributes.creator_id}`"
            @click="row.attributes.creator_id && toggleSelect(row.attributes.creator_id, row)"
          >
            <template #prepend>
              <!-- Real profile photo when the roster row carries a signed
                   avatar_url; the initial-letter avatar stays as fallback. -->
              <v-avatar size="40" color="surface-variant">
                <v-img
                  v-if="row.attributes.avatar_url"
                  :src="row.attributes.avatar_url"
                  :alt="row.attributes.display_name ?? ''"
                  cover
                  :data-test="`invite-creators-avatar-${row.attributes.creator_id}`"
                />
                <span v-else class="text-caption">
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
