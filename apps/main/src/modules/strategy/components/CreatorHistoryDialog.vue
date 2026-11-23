<script setup lang="ts">
/**
 * PL-3.1 — the client's decisions on one creator, newest first. Read when the dialog opens (a fresh load on every open).
 * The client's name, note and comment are drawn as plain text.
 */
import type { WaveCreatorDecisionData, WaveCreatorSlot } from '@catalyst/api-client'
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { strategyApi, type WaveScope } from '../api/strategy.api'
import { strategyErrorMessage } from '../strategyErrors'

const props = defineProps<{ modelValue: boolean; scope: WaveScope; row: WaveCreatorSlot | null }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()

const { t } = useI18n()
const items = ref<WaveCreatorDecisionData[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
let ticket = 0

watch(
  () => props.modelValue,
  async (open) => {
    items.value = []
    error.value = null
    if (!open || props.row === null) return
    const mine = ++ticket
    loading.value = true
    try {
      const res = await strategyApi.creatorDecisions(props.scope, props.row.id)
      if (mine === ticket) items.value = res.data
    } catch (err) {
      if (mine === ticket) error.value = strategyErrorMessage(err, t)
    } finally {
      if (mine === ticket) loading.value = false
    }
  },
  { immediate: true },
)
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="520"
    scrollable
    data-test="creator-history-dialog"
    @update:model-value="(v: boolean) => emit('update:modelValue', v)"
  >
    <v-card>
      <v-card-title class="text-h6 text-wrap pt-4 px-6">
        {{ t('app.strategy.creators.decision.historyTitle') }}
      </v-card-title>
      <v-card-text class="px-6">
        <v-skeleton-loader v-if="loading" type="list-item-two-line, list-item-two-line" />
        <v-alert v-else-if="error" type="error" variant="tonal" density="compact">{{
          error
        }}</v-alert>
        <ol v-else class="history" data-test="creator-history-list">
          <li
            v-for="d in items"
            :key="d.id"
            class="history__item"
            :data-test="`creator-history-${d.id}`"
          >
            <div class="history__head">
              {{ t(`app.strategy.creators.decision.status.${d.status}`, { name: d.client_name }) }}
            </div>
            <p v-if="d.comment || d.note" class="history__text">{{ d.comment ?? d.note }}</p>
            <span class="history__meta">
              {{ new Date(d.recorded_at).toLocaleString() }}
              <template v-if="d.recorded_by"> · {{ d.recorded_by }}</template>
            </span>
          </li>
        </ol>
      </v-card-text>
      <v-card-actions class="px-6 pb-4">
        <v-spacer />
        <v-btn
          variant="text"
          data-test="creator-history-close"
          @click="emit('update:modelValue', false)"
        >
          {{ t('app.strategy.creators.close') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.history {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin: 0;
  padding: 0;
  list-style: none;
}
.history__item {
  padding: 10px 12px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 8px;
}
.history__head {
  font-size: 13px;
  font-weight: 600;
  overflow-wrap: anywhere;
}
.history__text {
  margin: 4px 0 0;
  font-size: 13px;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  color: rgb(var(--v-theme-on-surface-variant));
}
.history__meta {
  display: block;
  margin-top: 4px;
  font-size: 11px;
  color: rgb(var(--v-theme-on-surface-variant));
}
</style>
