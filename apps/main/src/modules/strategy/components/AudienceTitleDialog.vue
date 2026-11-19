<script setup lang="ts">
/**
 * PL-2.1 — the audience title of the USP set (one per wave, typed by hand, optional). No design frame exists for
 * this dialog. An empty value clears the title.
 */
import type { StrategySummaryResource } from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDisplay } from 'vuetify'

import { strategyApi, type WaveScope } from '../api/strategy.api'
import { fieldErrorsOf, strategyErrorMessage } from '../strategyErrors'

const props = defineProps<{
  modelValue: boolean
  scope: WaveScope
  current: string | null
  max: number
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  saved: [summary: StrategySummaryResource]
}>()

const { t } = useI18n()
const { xs } = useDisplay()
const session = ref(0)
const value = ref('')
const saving = ref(false)
const error = ref<string | null>(null)
const tooLong = computed(() => Array.from(value.value.trim()).length > props.max)

watch(
  () => props.modelValue,
  (open) => {
    session.value++
    if (!open) return
    value.value = props.current ?? ''
    saving.value = false
    error.value = null
  },
  { immediate: true },
)

function close(): void {
  if (!saving.value) emit('update:modelValue', false)
}

async function submit(): Promise<void> {
  if (tooLong.value) return
  saving.value = true
  error.value = null
  try {
    const v = value.value.trim()
    const res = await strategyApi.setAudienceTitle(props.scope, v === '' ? null : v)
    emit('saved', res.data)
    emit('update:modelValue', false)
  } catch (err) {
    error.value = fieldErrorsOf(err)['usp_audience_title'] ?? strategyErrorMessage(err, t)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="440"
    persistent
    :fullscreen="xs"
    data-test="audience-dialog"
    @update:model-value="
      (v: boolean) => {
        if (!v) close()
      }
    "
  >
    <v-card :key="session">
      <v-card-title class="text-h6 pt-4 px-6">{{
        t('app.strategy.audience.dialogTitle')
      }}</v-card-title>
      <v-card-text class="px-6">
        <v-text-field
          v-model="value"
          :label="t('app.strategy.audience.label')"
          :placeholder="t('app.strategy.audience.placeholder')"
          variant="outlined"
          density="comfortable"
          :counter="max"
          hide-details="auto"
          :error-messages="tooLong ? [t('app.strategy.validation.tooLong', { max })] : []"
          data-test="audience-field"
          @keydown.enter.prevent="submit"
        />
        <v-alert
          v-if="error"
          type="error"
          variant="tonal"
          density="compact"
          class="mt-3"
          data-test="audience-error"
        >
          {{ error }}
        </v-alert>
      </v-card-text>
      <v-card-actions class="px-6 pb-4">
        <v-spacer />
        <v-btn variant="text" :disabled="saving" data-test="audience-cancel" @click="close">
          {{ t('app.strategy.cancel') }}
        </v-btn>
        <v-btn
          color="primary"
          variant="flat"
          :loading="saving"
          :disabled="tooLong"
          data-test="audience-save"
          @click="submit"
        >
          {{ t('app.strategy.save') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
