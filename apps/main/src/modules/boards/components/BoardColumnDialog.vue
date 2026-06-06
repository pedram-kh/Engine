<script setup lang="ts">
/**
 * Add / edit a board column (Sprint 12 Chunk 2, D-6). A v-dialog form: name +
 * colour (from the boardStatus palette) + the two terminal toggles. Binds the
 * canonical per-field 422 pattern (`extractFieldErrors` on `name` / `color_token`)
 * — this file is on `CANONICAL_422_FILES`. The ≤1-each terminal swap (§7.5) is
 * server-enforced; the store does a silent refresh so the UI reflects it.
 *
 * The colour swatch in the picker binds `:style="{ backgroundColor }"` from the
 * boardTokens helper (Q1 — hex in the .ts, camelCase object binding).
 */

import { ApiError, extractFieldErrors } from '@catalyst/api-client'
import type { BoardColumnResource } from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { boardColorOptions } from '../support/boardTokens'
import { useBoardStore } from '../stores/useBoardStore'

type ColumnField = 'name' | 'color_token'

const props = defineProps<{
  modelValue: boolean
  column: BoardColumnResource | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  saved: []
}>()

const { t } = useI18n()
const store = useBoardStore()

const isEdit = computed(() => props.column !== null)
const colorOptions = boardColorOptions()

const name = ref('')
const colorToken = ref<string>(colorOptions[0]?.token ?? 'status-todefine')
const isTerminalSuccess = ref(false)
const isTerminalFailure = ref(false)

const saving = ref(false)
const formError = ref<string | null>(null)
const fieldErrors = ref<Partial<Record<ColumnField, readonly string[]>>>({})

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    formError.value = null
    fieldErrors.value = {}
    const column = props.column
    if (column !== null) {
      name.value = column.attributes.name
      colorToken.value = column.attributes.color_token
      isTerminalSuccess.value = column.attributes.is_terminal_success
      isTerminalFailure.value = column.attributes.is_terminal_failure
    } else {
      name.value = ''
      colorToken.value = colorOptions[0]?.token ?? 'status-todefine'
      isTerminalSuccess.value = false
      isTerminalFailure.value = false
    }
  },
)

function close(): void {
  emit('update:modelValue', false)
}

async function save(): Promise<void> {
  if (saving.value) return
  saving.value = true
  formError.value = null
  fieldErrors.value = {}
  try {
    const payload = {
      name: name.value,
      color_token: colorToken.value,
      is_terminal_success: isTerminalSuccess.value,
      is_terminal_failure: isTerminalFailure.value,
    }
    if (props.column !== null) {
      await store.updateColumn(props.column.id, payload)
    } else {
      await store.createColumn(payload)
    }
    emit('saved')
    close()
  } catch (err) {
    if (err instanceof ApiError) {
      fieldErrors.value = extractFieldErrors<ColumnField>(err)
    }
    if (Object.keys(fieldErrors.value).length === 0) {
      formError.value = t('app.campaigns.board.column.saveFailed')
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="460"
    data-test="board-column-dialog"
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <v-card>
      <v-card-title>
        {{
          isEdit
            ? t('app.campaigns.board.column.editTitle')
            : t('app.campaigns.board.column.addTitle')
        }}
      </v-card-title>
      <v-divider />
      <v-card-text>
        <v-alert
          v-if="formError"
          type="error"
          variant="tonal"
          density="compact"
          class="mb-3"
          data-test="board-column-form-error"
        >
          {{ formError }}
        </v-alert>

        <v-text-field
          v-model="name"
          :label="t('app.campaigns.board.column.name')"
          variant="outlined"
          density="comfortable"
          maxlength="64"
          :error-messages="fieldErrors.name as string[]"
          data-test="board-column-name"
        />

        <v-select
          v-model="colorToken"
          :items="colorOptions"
          item-value="token"
          item-title="token"
          :label="t('app.campaigns.board.column.color')"
          variant="outlined"
          density="comfortable"
          :error-messages="fieldErrors.color_token as string[]"
          data-test="board-column-color"
        >
          <template #selection="{ item }">
            <span class="d-flex align-center ga-2">
              <span
                class="board-color-swatch"
                :style="{ backgroundColor: item.raw.hex }"
                aria-hidden="true"
              />
              {{ item.raw.token }}
            </span>
          </template>
          <template #item="{ props: itemProps, item }">
            <v-list-item v-bind="itemProps" :title="item.raw.token">
              <template #prepend>
                <span
                  class="board-color-swatch"
                  :style="{ backgroundColor: item.raw.hex }"
                  aria-hidden="true"
                />
              </template>
            </v-list-item>
          </template>
        </v-select>

        <v-switch
          v-model="isTerminalSuccess"
          :label="t('app.campaigns.board.column.terminalSuccess')"
          color="success"
          density="compact"
          hide-details
          data-test="board-column-terminal-success"
        />
        <v-switch
          v-model="isTerminalFailure"
          :label="t('app.campaigns.board.column.terminalFailure')"
          color="error"
          density="compact"
          hide-details
          data-test="board-column-terminal-failure"
        />
      </v-card-text>
      <v-divider />
      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" data-test="board-column-cancel" @click="close">
          {{ t('app.campaigns.board.column.cancel') }}
        </v-btn>
        <v-btn
          color="primary"
          variant="flat"
          :loading="saving"
          data-test="board-column-save"
          @click="save"
        >
          {{ t('app.campaigns.board.column.save') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.board-color-swatch {
  width: 14px;
  height: 14px;
  border-radius: 4px;
  display: inline-block;
}
</style>
