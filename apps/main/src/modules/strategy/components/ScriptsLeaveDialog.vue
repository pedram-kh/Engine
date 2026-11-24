<script setup lang="ts">
/**
 * PL-3.2a — the leave guard's dialog: the person is leaving the scripts step (another step, another market, another script,
 * another page) while something is not saved even after one more attempt. They choose: stay (and see what failed), try
 * again, or leave and lose it, knowingly. Nothing here decides for them.
 */
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{ modelValue: boolean; busy: boolean }>()
const emit = defineEmits<{ stay: []; retry: []; leave: [] }>()
const { t } = useI18n()
const session = ref(0)
watch(
  () => props.modelValue,
  () => session.value++,
)
</script>

<template>
  <v-dialog :model-value="modelValue" max-width="480" persistent data-test="scripts-leave-dialog">
    <v-card :key="session">
      <v-card-title class="text-h6 text-wrap text-break pt-4 px-6">{{
        t('app.strategy.scripts.leave.title')
      }}</v-card-title>
      <v-card-text class="px-6">{{ t('app.strategy.scripts.leave.body') }}</v-card-text>
      <v-card-actions class="px-6 pb-4 flex-wrap">
        <v-btn
          color="error"
          variant="text"
          :disabled="busy"
          data-test="scripts-leave-discard"
          @click="emit('leave')"
          >{{ t('app.strategy.scripts.leave.leave') }}</v-btn
        >
        <v-spacer />
        <v-btn
          variant="text"
          :loading="busy"
          data-test="scripts-leave-retry"
          @click="emit('retry')"
          >{{ t('app.strategy.scripts.leave.retry') }}</v-btn
        >
        <v-btn
          color="primary"
          variant="flat"
          :disabled="busy"
          data-test="scripts-leave-stay"
          @click="emit('stay')"
          >{{ t('app.strategy.scripts.leave.stay') }}</v-btn
        >
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
