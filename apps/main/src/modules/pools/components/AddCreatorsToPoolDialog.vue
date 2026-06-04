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
 *   - D-5 client-side search filters the fetched roster page locally.
 *
 * Note: the slim roster row carries `display_name` + `country_code` + the
 * `creator_id` ULID, but NOT an avatar URL (only the member resource does),
 * so the avatar here is an initials placeholder.
 */

import type { RosterCreatorListItem } from '@catalyst/api-client'
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

// Fetch a wide single page so the client-side search + exclusion cover as
// much of the roster as possible without a server round-trip (D-5). The
// server `?q=` FTS stays deferred (D-7).
const ROSTER_PER_PAGE = 100

const roster = ref<RosterCreatorListItem[]>([])
const memberIds = ref<Set<string>>(new Set())
const loading = ref(false)
const error = ref<string | null>(null)
const adding = ref(false)
const search = ref('')
const selected = ref<Set<string>>(new Set())

/** Roster creators NOT already in the pool (client-side exclusion, D-3). */
const available = computed<RosterCreatorListItem[]>(() =>
  roster.value.filter((row) => {
    const id = row.attributes.creator_id
    return id !== null && id !== '' && !memberIds.value.has(id)
  }),
)

const filtered = computed<RosterCreatorListItem[]>(() => {
  const q = search.value.trim().toLowerCase()
  if (q === '') return available.value
  return available.value.filter((row) =>
    (row.attributes.display_name ?? '').toLowerCase().includes(q),
  )
})

const rosterEmpty = computed(() => roster.value.length === 0)
const allInPool = computed(() => roster.value.length > 0 && available.value.length === 0)
const canAdd = computed(() => selected.value.size > 0 && !adding.value)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  selected.value = new Set()
  search.value = ''
  try {
    const [rosterRes, membersRes] = await Promise.all([
      rosterApi.list(props.agencyId, { per_page: ROSTER_PER_PAGE }),
      talentPoolsApi.members(props.agencyId, props.poolId, { per_page: 25 }),
    ])
    roster.value = rosterRes.data
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

  adding.value = true
  error.value = null
  const ids = [...selected.value]
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

        <v-text-field
          v-if="!loading && !rosterEmpty"
          v-model="search"
          density="compact"
          variant="outlined"
          hide-details
          clearable
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
          v-else-if="rosterEmpty"
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
          v-else-if="filtered.length === 0"
          class="text-body-2 text-medium-emphasis py-4"
          data-test="add-creators-empty-search"
        >
          {{ t('app.pools.addCreators.noSearchMatch') }}
        </div>

        <v-list v-else data-test="add-creators-list">
          <v-list-item
            v-for="row in filtered"
            :key="row.attributes.creator_id ?? row.id"
            :data-test="`add-creators-row-${row.attributes.creator_id}`"
            @click="row.attributes.creator_id && toggleSelect(row.attributes.creator_id)"
          >
            <template #prepend>
              <v-avatar size="40" color="surface-variant">
                <span class="text-caption">
                  {{ (row.attributes.display_name ?? '?')[0]?.toUpperCase() }}
                </span>
              </v-avatar>
            </template>
            <v-list-item-title>
              {{ row.attributes.display_name ?? t('app.pools.detail.unnamed') }}
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
