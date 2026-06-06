<script setup lang="ts">
/**
 * Automation-config dialog (Sprint 12 Chunk 2, D-8 / §8). A table of the board's
 * events; each row sets a target column (or "No automation") + an enable toggle.
 * Manual drags NEVER trigger these (Q2) — the subtitle says so.
 *
 * Broken-state affordance (§14.4): when a target column is deleted the backend
 * nulls `target_column_id`. An enabled `move_to_column` automation with a null
 * target is BROKEN — it would fire and do nothing — so the row flags a warning
 * prompting a re-pick. Picking a column repairs it (action_type → move_to_column,
 * target set); "No automation" makes it inert on purpose (action_type → none).
 */

import { ApiError } from '@catalyst/api-client'
import type { BoardAutomationResource } from '@catalyst/api-client'
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { useBoardStore } from '../stores/useBoardStore'

defineProps<{
  modelValue: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
}>()

const { t } = useI18n()
const store = useBoardStore()

const banner = ref<string | null>(null)

function isBroken(auto: BoardAutomationResource): boolean {
  return (
    auto.attributes.is_enabled &&
    auto.attributes.action_type === 'move_to_column' &&
    auto.attributes.target_column_id === null
  )
}

function targetOptions(): Array<{ title: string; value: string | null }> {
  return [
    { title: t('app.campaigns.board.automation.none'), value: null },
    ...store.sortedColumns.map((c) => ({ title: c.attributes.name, value: c.id })),
  ]
}

async function onTargetChange(auto: BoardAutomationResource, value: string | null): Promise<void> {
  banner.value = null
  try {
    await store.updateAutomation(auto.id, {
      target_column_id: value,
      action_type: value === null ? 'none' : 'move_to_column',
    })
  } catch (err) {
    banner.value =
      err instanceof ApiError ? err.message : t('app.campaigns.board.automation.updateFailed')
  }
}

async function onEnableChange(auto: BoardAutomationResource, value: boolean | null): Promise<void> {
  banner.value = null
  try {
    await store.updateAutomation(auto.id, { is_enabled: value === true })
  } catch {
    banner.value = t('app.campaigns.board.automation.updateFailed')
  }
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="720"
    data-test="board-automation-dialog"
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <v-card>
      <v-card-title>{{ t('app.campaigns.board.automation.title') }}</v-card-title>
      <v-card-subtitle>{{ t('app.campaigns.board.automation.subtitle') }}</v-card-subtitle>
      <v-divider />
      <v-card-text>
        <v-alert
          v-if="banner"
          type="error"
          variant="tonal"
          density="compact"
          class="mb-3"
          data-test="board-automation-banner"
        >
          {{ banner }}
        </v-alert>

        <v-table density="comfortable">
          <thead>
            <tr>
              <th>{{ t('app.campaigns.board.automation.event') }}</th>
              <th>{{ t('app.campaigns.board.automation.target') }}</th>
              <th>{{ t('app.campaigns.board.automation.enabled') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="auto in store.automations"
              :key="auto.id"
              :data-test="`board-automation-row-${auto.id}`"
            >
              <td>
                <div>{{ auto.attributes.event_key }}</div>
                <div
                  v-if="isBroken(auto)"
                  class="text-caption text-warning"
                  :data-test="`board-automation-broken-${auto.id}`"
                >
                  <v-icon icon="mdi-alert" size="x-small" />
                  {{ t('app.campaigns.board.automation.broken') }}
                </div>
              </td>
              <td style="min-width: 220px">
                <v-select
                  :model-value="auto.attributes.target_column_id"
                  :items="targetOptions()"
                  variant="outlined"
                  density="compact"
                  hide-details
                  :data-test="`board-automation-target-${auto.id}`"
                  @update:model-value="(v) => onTargetChange(auto, v)"
                />
              </td>
              <td>
                <v-switch
                  :model-value="auto.attributes.is_enabled"
                  color="primary"
                  density="compact"
                  hide-details
                  :data-test="`board-automation-enabled-${auto.id}`"
                  @update:model-value="(v) => onEnableChange(auto, v)"
                />
              </td>
            </tr>
          </tbody>
        </v-table>
      </v-card-text>
      <v-divider />
      <v-card-actions>
        <v-spacer />
        <v-btn
          variant="text"
          data-test="board-automation-close"
          @click="emit('update:modelValue', false)"
        >
          {{ t('app.campaigns.board.automation.close') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
