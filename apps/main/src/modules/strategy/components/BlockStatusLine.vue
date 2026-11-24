<script setup lang="ts">
/**
 * PL-3.2a — the status of one autosaving block: Saving…, Saved, Could not save (with Retry) or "Removed by someone else". An idle
 * block says nothing. The text is announced politely; a failed block is an alert.
 */
import { useI18n } from 'vue-i18n'

import type { BlockStatus } from '../scriptsLogic'

defineProps<{ status: BlockStatus; failure: 'refused' | 'retry' | null; testId: string }>()
const emit = defineEmits<{ retry: [] }>()
const { t } = useI18n()
</script>

<template>
  <span
    class="bstatus"
    :class="`bstatus--${status}`"
    :role="status === 'failed' || status === 'gone' ? 'alert' : 'status'"
    :data-test="testId"
    :data-status="status"
  >
    <template v-if="status === 'dirty' || status === 'saving'">{{
      t('app.strategy.scripts.save.saving')
    }}</template>
    <template v-else-if="status === 'saved'">
      <v-icon icon="mdi-check" size="12" aria-hidden="true" />{{
        t('app.strategy.scripts.save.saved')
      }}
    </template>
    <template v-else-if="status === 'failed'">
      {{
        failure === 'refused'
          ? t('app.strategy.scripts.save.refused')
          : t('app.strategy.scripts.save.failed')
      }}
      <button
        type="button"
        class="bstatus__retry"
        :data-test="`${testId}-retry`"
        @click="emit('retry')"
      >
        {{ t('app.strategy.scripts.save.retry') }}
      </button>
    </template>
    <template v-else-if="status === 'gone'">{{ t('app.strategy.scripts.save.gone') }}</template>
  </span>
</template>

<style scoped>
.bstatus {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  min-height: 16px;
  font-size: 11px;
  line-height: 16px;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
}
.bstatus--saved {
  color: rgb(var(--v-theme-success));
}
.bstatus--failed,
.bstatus--gone {
  color: rgb(var(--v-theme-error));
}
.bstatus__retry {
  padding: 0;
  border: 0;
  background: none;
  font: inherit;
  color: inherit;
  text-decoration: underline;
  cursor: pointer;
}
</style>
