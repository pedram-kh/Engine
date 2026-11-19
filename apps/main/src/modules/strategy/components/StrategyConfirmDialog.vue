<script setup lang="ts">
/**
 * PL-2.1 — the confirm dialog of this module (the pattern of `WaveConfirmDialog`): a title, a body, an error line,
 * Cancel and a confirm button. The card is keyed by a counter that moves on every open and close, so nothing inside
 * survives a quick reopen. The default slot holds extra content (the delete-USP list).
 */
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDisplay } from 'vuetify'

const props = defineProps<{
  modelValue: boolean
  title: string
  body?: string
  confirmLabel: string
  busy: boolean
  error: string | null
  /** After "removed by someone else" only Close is offered. */
  confirmDisabled?: boolean
  testId: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  confirm: []
}>()

const { t } = useI18n()
const { xs } = useDisplay()
const session = ref(0)

watch(
  () => props.modelValue,
  () => {
    session.value++
  },
)

function close(): void {
  if (props.busy) return
  emit('update:modelValue', false)
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="520"
    persistent
    :fullscreen="xs"
    :data-test="testId"
    @update:model-value="
      (v: boolean) => {
        if (!v) close()
      }
    "
  >
    <v-card :key="session">
      <v-card-title class="text-h6 pt-4 px-6">{{ title }}</v-card-title>
      <v-card-text class="px-6">
        <p v-if="body" class="mb-3" data-test="confirm-body">{{ body }}</p>
        <slot />
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
        <v-btn variant="text" :disabled="busy" data-test="confirm-cancel" @click="close">
          {{ confirmDisabled ? t('app.strategy.close') : t('app.strategy.cancel') }}
        </v-btn>
        <v-btn
          v-if="!confirmDisabled"
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
