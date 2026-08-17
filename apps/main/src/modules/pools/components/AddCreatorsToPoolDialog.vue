<script setup lang="ts">
/**
 * Pool-side "Add creators" picker (frontend-only — reuses the existing
 * idempotent, relation-gated `store`). The inverse of `AddToPoolDialog`
 * (which lists pools for ONE creator): this lists the agency's ROSTER
 * creators for ONE pool and adds the selected ones.
 *
 * Design locks (kickoff):
 *   - D-2 roster-sourced: the picker is `rosterApi.list`, NOT discovery —
 *     every roster creator has an AgencyCreatorRelation, so the `store`
 *     `requireRosterRelation()` gate can never reject a roster-sourced add.
 *   - D-3 client-side exclusion: fetch the pool members + subtract them from
 *     the roster here. The members endpoint paginates at 25, so on a large
 *     pool the exclusion is page-local/partial — but `store`'s idempotent
 *     `firstOrCreate` makes a missed exclusion a harmless no-op, so a partial
 *     filter is only ever cosmetic, never a correctness bug.
 *   - D-4 multi-add loops the single `store` (no batch endpoint exists).
 *   - D-5 client-side search filtered the fetched roster page locally — NOW
 *     REVERSED, and D-7's deferral of the server `?q=` FTS with it. The local
 *     filter capped the picker at the first `ROSTER_PER_PAGE` creators by
 *     display_name: past that, the tail of the alphabet was absent from the
 *     list entirely, so those creators could not be added to a pool at all.
 *     Search now goes to the server, debounced, as `CreatorRosterPage` does.
 *
 * Note: the slim roster row carries `display_name` + `country_code` + the
 * `creator_id` ULID, and — since the invite-offer-details batch put
 * `avatar_url` on it for the campaign invite picker — a signed avatar too.
 * The initials placeholder is now only the fallback for a creator with no
 * avatar (or a non-S3 disk, where the URL is null).
 */

import type { RosterCreatorListItem, RosterListParams } from '@catalyst/api-client'
import { BlacklistBadge } from '@catalyst/ui'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { rosterApi } from '@/modules/roster/api/roster.api'
import { talentPoolsApi } from '../api/talentPools.api'

const props = defineProps<{
  modelValue: boolean
  agencyId: string
  poolId: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  added: [message: string]
}>()

const { t } = useI18n()

// The result window per query. With search now server-side (D-5 reversed) this
// bounds one page of results rather than the whole reachable roster.
const ROSTER_PER_PAGE = 100
const SEARCH_DEBOUNCE_MS = 300

const roster = ref<RosterCreatorListItem[]>([])
const memberIds = ref<Set<string>>(new Set())
const loading = ref(false)
// A search re-query, as distinct from the initial open — drives the field's
// own spinner so the input never unmounts mid-type.
const searching = ref(false)
const error = ref<string | null>(null)
const adding = ref(false)
// `clearable` hands back null on clear; every read goes through `searchTerm`.
const search = ref<string | null>('')
const selected = ref<Set<string>>(new Set())
// Whether the agency has NO roster at all, captured on the unfiltered open
// load — so a search matching nothing never renders as "you have no creators".
const rosterEmptyUnfiltered = ref(false)

/** Roster creators NOT already in the pool (client-side exclusion, D-3). */
const available = computed<RosterCreatorListItem[]>(() =>
  roster.value.filter((row) => {
    const id = row.attributes.creator_id
    return id !== null && id !== '' && !memberIds.value.has(id)
  }),
)

const searchTerm = computed(() => (search.value ?? '').trim())
const hasSearch = computed(() => searchTerm.value !== '')

/**
 * Every roster row seen since the dialog opened, keyed by creator ULID — lets
 * `addSelected` resolve a selected id back to its blacklist status for the
 * hard-only confirm (D-6/D-7).
 *
 * This ACCUMULATES rather than mirroring the current result set, because a
 * selection survives a re-query: a creator picked under an earlier search term
 * is no longer among the rows on screen, and resolving them against those rows
 * alone would silently drop them from the confirm — turning the friction gate
 * off for exactly the creator the user has lost sight of.
 */
const rosterById = ref<Map<string, RosterCreatorListItem>>(new Map())

// "Everyone is already in the pool" is only a truthful claim about the WHOLE
// roster, so it is suppressed while a search narrows the set — a search that
// happens to return only members is a no-match, not an exhausted roster.
const allInPool = computed(
  () => !hasSearch.value && roster.value.length > 0 && available.value.length === 0,
)
const canAdd = computed(() => selected.value.size > 0 && !adding.value)

/**
 * The roster query. Search runs SERVER-side (`?q=`) — see the D-5 note in the
 * component docblock for why the previous local filter was a correctness bug.
 *
 * Sequence-guarded: a debounce in front of an async call still lets a slow
 * early query land after a fast later one, so only the newest request writes.
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

    const seen = new Map(rosterById.value)
    for (const row of res.data) {
      const id = row.attributes.creator_id
      if (id !== null && id !== '') seen.set(id, row)
    }
    rosterById.value = seen

    // Only an UNFILTERED read can tell us the roster is genuinely empty.
    if (term === '') rosterEmptyUnfiltered.value = res.data.length === 0
  } catch {
    if (seq !== requestSeq) return
    error.value = t('app.pools.addCreators.loadFailed')
    roster.value = []
  }
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  selected.value = new Set()
  search.value = ''
  rosterById.value = new Map()
  try {
    const [, membersRes] = await Promise.all([
      fetchRoster(''),
      talentPoolsApi.members(props.agencyId, props.poolId, { per_page: 25 }),
    ])
    memberIds.value = new Set(membersRes.data.map((m) => m.id))
  } catch {
    error.value = t('app.pools.addCreators.loadFailed')
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

// Debounced server search — 300ms after the last keystroke, mirroring
// CreatorRosterPage. Skipped when the term already matches what is on screen,
// which absorbs both `load()`'s reset-to-empty and a type-then-backspace.
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

function toggleSelect(creatorId: string): void {
  const next = new Set(selected.value)
  if (next.has(creatorId)) next.delete(creatorId)
  else next.add(creatorId)
  selected.value = next
}

/**
 * Loop the single `store` per selected creator (D-4 — no batch endpoint).
 * Idempotent server-side, so re-adding a creator the partial exclusion still
 * showed (D-3) is a harmless no-op. On done, emit so the parent reloads its
 * member list + count.
 */
async function addSelected(): Promise<void> {
  if (selected.value.size === 0 || adding.value) return

  const ids = [...selected.value]

  // Hard-only confirm-on-add (D-6/D-7): visibility is everywhere (the per-row
  // badge shows hard + soft), but friction fires ONLY where the mistake is
  // costly — a HARD blacklist. SOFT is a mild caution; the badge alone is
  // enough (a confirm-about-a-warning is redundant friction). Warn-don't-
  // remove: the user can still proceed; nothing is blocked or auto-removed.
  const hardSelected = ids
    .map((id) => rosterById.value.get(id))
    .filter(
      (row): row is RosterCreatorListItem =>
        row !== undefined &&
        row.attributes.is_blacklisted === true &&
        (row.attributes.blacklist_type ?? 'hard') === 'hard',
    )

  if (hardSelected.length > 0) {
    const names = hardSelected
      .map((row) => row.attributes.display_name ?? t('app.pools.detail.unnamed'))
      .join(', ')
    const message =
      hardSelected.length === 1
        ? t('app.pools.addCreators.confirmHard.one', { name: names })
        : t('app.pools.addCreators.confirmHard.many', { count: hardSelected.length, names })
    if (!window.confirm(message)) return
  }

  adding.value = true
  error.value = null
  try {
    for (const creatorId of ids) {
      await talentPoolsApi.addCreator(props.agencyId, props.poolId, creatorId)
    }
    emit('added', t('app.pools.addCreators.added', { count: ids.length }))
    emit('update:modelValue', false)
  } catch {
    error.value = t('app.pools.addCreators.addFailed')
  } finally {
    adding.value = false
  }
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="480"
    data-test="add-creators-dialog"
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <v-card>
      <v-card-title class="text-h6 pa-4 d-flex align-center justify-space-between">
        {{ t('app.pools.addCreators.title') }}
        <v-btn
          icon="mdi-close"
          variant="text"
          size="small"
          data-test="add-creators-close"
          @click="close"
        />
      </v-card-title>

      <v-card-text>
        <v-alert
          v-if="error"
          type="error"
          variant="tonal"
          class="mb-3"
          data-test="add-creators-error"
        >
          {{ error }}
        </v-alert>

        <!-- Stays mounted whenever a roster exists — including while a search
             matches nothing, which is when the user needs to edit the term. -->
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
          :label="t('app.pools.addCreators.search')"
          data-test="add-creators-search"
        />

        <v-skeleton-loader
          v-if="loading"
          type="list-item-avatar@3"
          data-test="add-creators-skeleton"
        />

        <div
          v-else-if="rosterEmptyUnfiltered"
          class="text-body-2 text-medium-emphasis py-4"
          data-test="add-creators-empty-no-roster"
        >
          {{ t('app.pools.addCreators.noRoster') }}
        </div>

        <div
          v-else-if="allInPool"
          class="text-body-2 text-medium-emphasis py-4"
          data-test="add-creators-empty-all-in-pool"
        >
          {{ t('app.pools.addCreators.allInPool') }}
        </div>

        <div
          v-else-if="available.length === 0"
          class="text-body-2 text-medium-emphasis py-4"
          data-test="add-creators-empty-search"
        >
          {{ t('app.pools.addCreators.noSearchMatch') }}
        </div>

        <v-list v-else data-test="add-creators-list">
          <v-list-item
            v-for="row in available"
            :key="row.attributes.creator_id ?? row.id"
            :data-test="`add-creators-row-${row.attributes.creator_id}`"
            @click="row.attributes.creator_id && toggleSelect(row.attributes.creator_id)"
          >
            <template #prepend>
              <!-- Real profile photo when the roster row carries a signed
                   avatar_url; the initial-letter avatar stays as fallback
                   (the InviteCreatorsDialog pattern, same roster shape). -->
              <v-avatar size="40" color="surface-variant">
                <v-img
                  v-if="row.attributes.avatar_url"
                  :src="row.attributes.avatar_url"
                  :alt="row.attributes.display_name ?? ''"
                  cover
                  :data-test="`add-creators-avatar-${row.attributes.creator_id}`"
                />
                <span v-else class="text-caption">
                  {{ (row.attributes.display_name ?? '?')[0]?.toUpperCase() }}
                </span>
              </v-avatar>
            </template>
            <v-list-item-title>
              {{ row.attributes.display_name ?? t('app.pools.detail.unnamed') }}
              <!-- Per-row blacklist flag (D-6): shows for BOTH hard + soft so
                   status is visible BEFORE selecting. The HARD-only confirm in
                   addSelected is the friction gate; this badge is the visibility. -->
              <BlacklistBadge
                v-if="row.attributes.is_blacklisted"
                :type="row.attributes.blacklist_type ?? 'hard'"
                :label="t(`app.roster.blacklist.badge.${row.attributes.blacklist_type ?? 'hard'}`)"
                size="x-small"
                class="ml-2"
                :data-test="`add-creators-blacklist-${row.attributes.creator_id}`"
              />
            </v-list-item-title>
            <v-list-item-subtitle>
              {{ row.attributes.country_code ?? '' }}
            </v-list-item-subtitle>
            <template #append>
              <v-checkbox-btn
                :model-value="
                  row.attributes.creator_id !== null && selected.has(row.attributes.creator_id)
                "
                :data-test="`add-creators-checkbox-${row.attributes.creator_id}`"
                @click.stop="row.attributes.creator_id && toggleSelect(row.attributes.creator_id)"
              />
            </template>
          </v-list-item>
        </v-list>
      </v-card-text>

      <v-card-actions class="px-4 pb-4">
        <v-spacer />
        <v-btn variant="text" data-test="add-creators-cancel" @click="close">
          {{ t('app.pools.addCreators.cancel') }}
        </v-btn>
        <v-btn
          color="primary"
          variant="flat"
          :disabled="!canAdd"
          :loading="adding"
          data-test="add-creators-submit"
          @click="addSelected"
        >
          {{ t('app.pools.addCreators.add', { count: selected.size }) }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
