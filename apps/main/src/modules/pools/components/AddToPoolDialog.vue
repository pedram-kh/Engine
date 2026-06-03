<script setup lang="ts">
/**
 * Add-to-pool picker dialog (Sprint 6 Chunk 2b, D-2b-9). Opened from the 2a
 * creator detail page header. Lists the agency's pools with a per-pool toggle
 * reflecting THIS creator's current membership; toggling calls the add/remove
 * endpoints (D-2b-8).
 *
 * The membership state is fetched in ONE request (poolsForCreator → is_member
 * per pool, no N+1). There is no add-to-collection precedent in the codebase;
 * the closest dialog shell is InviteUserModal, but that is single-action
 * create — this is a multi-row toggle, so it is built fresh here.
 */

import type { TalentPoolPickerItem } from '@catalyst/api-client'
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { talentPoolsApi } from '../api/talentPools.api'

const props = defineProps<{
  modelValue: boolean
  agencyId: string
  creatorUlid: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  changed: [message: string]
}>()

const { t } = useI18n()

const pools = ref<TalentPoolPickerItem[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
// Per-pool in-flight flag so toggling one row does not disable the others.
const togglingIds = ref<Set<string>>(new Set())

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await talentPoolsApi.poolsForCreator(props.agencyId, props.creatorUlid)
    pools.value = res.data
  } catch {
    error.value = t('app.pools.picker.loadFailed')
  } finally {
    loading.value = false
  }
}

// Fetch fresh membership state each time the dialog opens (immediate so a
// dialog mounted already-open also loads).
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

async function toggle(pool: TalentPoolPickerItem): Promise<void> {
  if (togglingIds.value.has(pool.id)) return

  const next = !pool.attributes.is_member
  const set = new Set(togglingIds.value)
  set.add(pool.id)
  togglingIds.value = set

  try {
    if (next) {
      await talentPoolsApi.addCreator(props.agencyId, pool.id, props.creatorUlid)
    } else {
      await talentPoolsApi.removeCreator(props.agencyId, pool.id, props.creatorUlid)
    }
    // Optimistically reflect the new state on the row.
    pools.value = pools.value.map((p) =>
      p.id === pool.id ? { ...p, attributes: { ...p.attributes, is_member: next } } : p,
    )
    emit(
      'changed',
      next
        ? t('app.pools.picker.added', { name: pool.attributes.name })
        : t('app.pools.picker.removed', { name: pool.attributes.name }),
    )
  } catch {
    error.value = t('app.pools.picker.toggleFailed')
  } finally {
    const cleared = new Set(togglingIds.value)
    cleared.delete(pool.id)
    togglingIds.value = cleared
  }
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="480"
    data-test="add-to-pool-dialog"
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <v-card>
      <v-card-title class="text-h6 pa-4 d-flex align-center justify-space-between">
        {{ t('app.pools.picker.title') }}
        <v-btn
          icon="mdi-close"
          variant="text"
          size="small"
          data-test="add-to-pool-close"
          @click="close"
        />
      </v-card-title>

      <v-card-text>
        <v-alert
          v-if="error"
          type="error"
          variant="tonal"
          class="mb-3"
          data-test="add-to-pool-error"
        >
          {{ error }}
        </v-alert>

        <v-skeleton-loader
          v-if="loading && pools.length === 0"
          type="list-item@3"
          data-test="add-to-pool-skeleton"
        />

        <div
          v-else-if="pools.length === 0"
          class="text-body-2 text-medium-emphasis py-4"
          data-test="add-to-pool-empty"
        >
          {{ t('app.pools.picker.empty') }}
        </div>

        <v-list v-else data-test="add-to-pool-list">
          <v-list-item
            v-for="pool in pools"
            :key="pool.id"
            :data-test="`add-to-pool-row-${pool.id}`"
          >
            <v-list-item-title>{{ pool.attributes.name }}</v-list-item-title>
            <v-list-item-subtitle v-if="pool.attributes.brand_name">
              {{ pool.attributes.brand_name }}
            </v-list-item-subtitle>
            <template #append>
              <v-switch
                :model-value="pool.attributes.is_member"
                color="primary"
                density="compact"
                hide-details
                :loading="togglingIds.has(pool.id)"
                :disabled="togglingIds.has(pool.id)"
                :data-test="`add-to-pool-toggle-${pool.id}`"
                @update:model-value="toggle(pool)"
              />
            </template>
          </v-list-item>
        </v-list>
      </v-card-text>

      <v-card-actions class="px-4 pb-4">
        <v-spacer />
        <v-btn variant="text" data-test="add-to-pool-done" @click="close">
          {{ t('app.pools.picker.done') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
