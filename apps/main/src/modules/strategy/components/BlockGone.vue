<script setup lang="ts">
/**
 * "This was deleted by someone else" for a line or a hook card that was open for writing: the person's own text stays on the card
 * and here they can copy it before closing. Nothing typed here is ever sent to another block.
 */
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{
  title: string
  hint: string
  /** The person's own words, copied as one text. */
  text: () => string
  /** `${prefix}-gone-${id}`, `…-gone-copy-…`, `…-gone-close-…`. */
  prefix: string
  id: string
}>()
const emit = defineEmits<{ dismiss: [] }>()
const { t } = useI18n()

const copied = ref(false)
const copyFailed = ref(false)
async function copyMine(): Promise<void> {
  copyFailed.value = false
  try {
    await navigator.clipboard.writeText(props.text())
    copied.value = true
  } catch {
    copyFailed.value = true
    copied.value = false
  }
}
</script>

<template>
  <div class="bgone" role="alert" :data-test="`${prefix}-gone-${id}`">
    <p class="bgone__title">{{ title }}</p>
    <p class="bgone__hint">{{ hint }}</p>
    <div class="bgone__actions">
      <v-btn
        size="small"
        variant="outlined"
        :data-test="`${prefix}-gone-copy-${id}`"
        @click="copyMine"
        >{{
          copied
            ? t('app.strategy.scripts.conflict.copied')
            : t('app.strategy.scripts.conflict.copy')
        }}</v-btn
      >
      <v-btn
        size="small"
        variant="text"
        :data-test="`${prefix}-gone-close-${id}`"
        @click="emit('dismiss')"
        >{{ t('app.strategy.close') }}</v-btn
      >
    </div>
    <p v-if="copyFailed" class="bgone__hint">
      {{ t('app.strategy.scripts.conflict.copyFailed') }}
    </p>
  </div>
</template>

<style scoped>
.bgone {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 10px 12px;
  border: 1px solid rgb(var(--v-theme-error));
  border-radius: 8px;
}
.bgone__title {
  margin: 0;
  font-size: 13px;
  font-weight: 600;
  color: rgb(var(--v-theme-error));
}
.bgone__hint {
  margin: 0;
  font-size: 12px;
  color: rgb(var(--v-theme-on-surface-variant));
}
.bgone__actions {
  display: flex;
  gap: 8px;
}
</style>
