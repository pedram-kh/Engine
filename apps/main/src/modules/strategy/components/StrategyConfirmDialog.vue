<script setup lang="ts">
/**
 * PL-2.1 — the confirm dialog of this module (the pattern of `WaveConfirmDialog`): a title, a body, an error line,
 * Cancel and a confirm button. The card is keyed by a counter that moves on every open and close, so nothing inside
 * survives a quick reopen. The default slot holds extra content (the delete-USP list).
 */
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

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
  /** The confirm button's colour: `error` for a delete (the default), `primary` for anything else. */
  confirmColor?: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  confirm: []
}>()

const { t } = useI18n()
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
    scrollable
    :data-test="testId"
    @update:model-value="
      (v: boolean) => {
        if (!v) close()
      }
    "
  >
    <v-card :key="session">
      <v-card-title class="text-h6 text-wrap text-break pt-4 px-6">{{ title }}</v-card-title>
      <v-card-text class="px-6">
        <p v-if="body" class="mb-3" data-test="confirm-body">{{ body }}</p>
        <slot />
      </v-card-text>
      <v-alert
        v-if="error"
        class="mx-6 mb-3 strategy-dialog-error"
        type="error"
        variant="tonal"
        density="compact"
        data-test="confirm-error"
      >
        {{ error }}
      </v-alert>
      <v-card-actions class="px-6 pb-4">
        <v-spacer />
        <v-btn variant="text" :disabled="busy" data-test="confirm-cancel" @click="close">
          {{ confirmDisabled ? t('app.strategy.close') : t('app.strategy.cancel') }}
        </v-btn>
        <v-btn
          v-if="!confirmDisabled"
          :color="confirmColor ?? 'error'"
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

<style scoped>
/* The dialog is `scrollable`: only the body scrolls. The alert sits between that body and the actions and keeps its
   full height, so the message is never cut off. */
.strategy-dialog-error {
  flex: 0 0 auto;
}
</style>
