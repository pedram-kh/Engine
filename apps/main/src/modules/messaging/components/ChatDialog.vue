<script setup lang="ts">
import { useI18n } from 'vue-i18n'

import type { ChatTransport } from '../api/messaging.api'
import ChatPanel from './ChatPanel.vue'

defineProps<{
  modelValue: boolean
  transport: ChatTransport | null
  title?: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
}>()

const { t } = useI18n()

function close(): void {
  emit('update:modelValue', false)
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="760"
    scrollable
    data-test="chat-dialog"
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <v-card>
      <v-card-title class="d-flex align-center justify-space-between">
        <span>{{ title ?? t('app.messaging.title') }}</span>
        <v-btn
          icon="mdi-close"
          variant="text"
          size="small"
          data-test="chat-dialog-close"
          @click="close"
        />
      </v-card-title>
      <v-divider />
      <v-card-text>
        <ChatPanel v-if="modelValue" :transport="transport" />
      </v-card-text>
    </v-card>
  </v-dialog>
</template>
