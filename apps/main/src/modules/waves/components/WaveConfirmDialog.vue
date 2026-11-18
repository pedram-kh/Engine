<script setup lang="ts">
/**
 * PL-1.3 — a confirmation for Remove step and Remove stream (plan 4.2). Names what goes; the confirm button is the
 * destructive one. The card is keyed by a counter bumped on every open and close, like the step dialog.
 */
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{
  modelValue: boolean
  title: string
  body: string
  confirmLabel: string
  busy: boolean
  error: string | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean]; confirm: [] }>()

const { t } = useI18n()
const session = ref(0)

watch(
  () => props.modelValue,
  () => {
    session.value += 1
  },
)
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="440"
    persistent
    data-test="confirm-dialog"
    @update:model-value="(v: boolean) => !busy && emit('update:modelValue', v)"
  >
    <v-card :key="session">
      <v-card-title class="text-h6 pt-4 px-6">{{ title }}</v-card-title>
      <v-card-text class="px-6">
        <p data-test="confirm-body">{{ body }}</p>
        <v-alert
          v-if="error"
          type="error"
          variant="tonal"
          density="compact"
          class="mt-3"
          data-test="confirm-error"
        >
          {{ error }}
        </v-alert>
      </v-card-text>
      <v-card-actions class="px-6 pb-4">
        <v-spacer />
        <v-btn
          variant="text"
          :disabled="busy"
          data-test="confirm-cancel"
          @click="emit('update:modelValue', false)"
        >
          {{ t('app.waves.tracker.dialog.cancel') }}
        </v-btn>
        <v-btn
          color="error"
          variant="flat"
          :loading="busy"
          data-test="confirm-ok"
          @click="emit('confirm')"
        >
          {{ confirmLabel }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
