<script setup lang="ts">
/**
 * Delete-column safeguard dialog (Sprint 12 Chunk 2, D-7 / §14.3).
 *
 * A NON-empty column requires a destination (read from the store's bucketed
 * counts, NOT the Resource `card_count`) — the dropdown lists the OTHER columns.
 * The two server safeguards surface as `ApiError.code` BANNERS, deliberately NOT
 * `extractFieldErrors` field-pointers (load-bearing #3): a delete confirm has no
 * form field to pin them onto — they're a whole-operation refusal:
 *
 *   - `board.column.last_column`         → "keep at least one column"
 *   - `board.column.destination_required`→ "choose a destination first"
 */

import { ApiError } from '@catalyst/api-client'
import type { BoardColumnResource } from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { useBoardStore } from '../stores/useBoardStore'

const props = defineProps<{
  modelValue: boolean
  column: BoardColumnResource | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  deleted: []
}>()

const { t } = useI18n()
const store = useBoardStore()

const deleting = ref(false)
const banner = ref<string | null>(null)
const destinationId = ref<string | null>(null)

const cardCount = computed(() =>
  props.column !== null ? (store.cardCountByColumn[props.column.id] ?? 0) : 0,
)
const isNonEmpty = computed(() => cardCount.value > 0)

/** The OTHER columns a non-empty column's cards can re-home into. */
const destinationOptions = computed(() =>
  store.sortedColumns
    .filter((c) => c.id !== props.column?.id)
    .map((c) => ({ title: c.attributes.name, value: c.id })),
)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    banner.value = null
    destinationId.value = null
  },
)

function close(): void {
  emit('update:modelValue', false)
}

function bannerForCode(code: string): string {
  if (code === 'board.column.last_column') {
    return t('app.campaigns.board.column.errorLastColumn')
  }
  if (code === 'board.column.destination_required') {
    return t('app.campaigns.board.column.errorDestinationRequired')
  }
  return t('app.campaigns.board.column.errorGeneric')
}

async function confirm(): Promise<void> {
  if (deleting.value || props.column === null) return
  deleting.value = true
  banner.value = null
  try {
    await store.deleteColumn(props.column.id, destinationId.value ?? undefined)
    emit('deleted')
    close()
  } catch (err) {
    banner.value =
      err instanceof ApiError
        ? bannerForCode(err.code)
        : t('app.campaigns.board.column.errorGeneric')
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="460"
    data-test="board-column-delete-dialog"
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <v-card>
      <v-card-title>{{ t('app.campaigns.board.column.deleteTitle') }}</v-card-title>
      <v-divider />
      <v-card-text>
        <v-alert
          v-if="banner"
          type="error"
          variant="tonal"
          density="compact"
          class="mb-3"
          data-test="board-column-delete-banner"
        >
          {{ banner }}
        </v-alert>

        <p v-if="!isNonEmpty" class="mb-2" data-test="board-column-delete-body">
          {{ t('app.campaigns.board.column.deleteBody', { name: column?.attributes.name ?? '' }) }}
        </p>
        <template v-else>
          <p class="mb-3" data-test="board-column-delete-body-nonempty">
            {{
              t('app.campaigns.board.column.deleteBodyNonEmpty', {
                name: column?.attributes.name ?? '',
                count: cardCount,
              })
            }}
          </p>
          <v-select
            v-model="destinationId"
            :items="destinationOptions"
            :label="t('app.campaigns.board.column.deleteDestination')"
            variant="outlined"
            density="comfortable"
            data-test="board-column-delete-destination"
          />
        </template>
      </v-card-text>
      <v-divider />
      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" data-test="board-column-delete-cancel" @click="close">
          {{ t('app.campaigns.board.column.cancel') }}
        </v-btn>
        <v-btn
          color="error"
          variant="flat"
          :loading="deleting"
          data-test="board-column-delete-confirm"
          @click="confirm"
        >
          {{ t('app.campaigns.board.column.deleteConfirm') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
