<script setup lang="ts">
/**
 * PL-3.2a — shown inside a block when the server answered 409: someone else saved this block first. The person's own text stays in
 * the fields above untouched; this shows the other version as plain text (line breaks kept), with "Copy my text", "Use their
 * version" and "Keep mine". Nothing is chosen for the person.
 */
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

export interface ConflictLine {
  label: string
  text: string
}

const props = defineProps<{ theirs: ConflictLine[]; mine: ConflictLine[]; testId: string }>()
const emit = defineEmits<{ keep: []; theirs: [] }>()
const { t } = useI18n()
const copied = ref(false)
const copyFailed = ref(false)

async function copyMine(): Promise<void> {
  copyFailed.value = false
  const text = props.mine
    .filter((l) => l.text !== '')
    .map((l) => (props.mine.length > 1 ? `${l.label}: ${l.text}` : l.text))
    .join('\n')
  try {
    await navigator.clipboard.writeText(text)
    copied.value = true
  } catch {
    // The text is still in the field to select by hand; say so instead of pretending it was copied.
    copyFailed.value = true
    copied.value = false
  }
}
</script>

<template>
  <div class="bconf" role="alert" :data-test="testId">
    <p class="bconf__title">{{ t('app.strategy.scripts.conflict.title') }}</p>
    <p class="bconf__hint">{{ t('app.strategy.scripts.conflict.hint') }}</p>
    <div class="bconf__theirs" :data-test="`${testId}-theirs`">
      <div class="bconf__theirs-head">{{ t('app.strategy.scripts.conflict.theirs') }}</div>
      <div v-for="l in theirs" :key="l.label" class="bconf__line">
        <span class="bconf__label">{{ l.label }}</span>
        <span class="bconf__text">{{
          l.text === '' ? t('app.strategy.scripts.conflict.empty') : l.text
        }}</span>
      </div>
    </div>
    <div class="bconf__actions">
      <v-btn size="small" variant="outlined" :data-test="`${testId}-copy`" @click="copyMine">
        {{
          copied
            ? t('app.strategy.scripts.conflict.copied')
            : t('app.strategy.scripts.conflict.copy')
        }}
      </v-btn>
      <v-btn
        size="small"
        variant="outlined"
        :data-test="`${testId}-use-theirs`"
        @click="emit('theirs')"
      >
        {{ t('app.strategy.scripts.conflict.useTheirs') }}
      </v-btn>
      <v-btn
        size="small"
        color="primary"
        variant="flat"
        :data-test="`${testId}-keep`"
        @click="emit('keep')"
      >
        {{ t('app.strategy.scripts.conflict.keepMine') }}
      </v-btn>
    </div>
    <p v-if="copyFailed" class="bconf__hint">{{ t('app.strategy.scripts.conflict.copyFailed') }}</p>
  </div>
</template>

<style scoped>
.bconf {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 12px;
  border: 1px solid color-mix(in srgb, rgb(var(--v-theme-warning)) 45%, transparent);
  border-radius: 8px;
  background: color-mix(in srgb, rgb(var(--v-theme-warning)) 8%, transparent);
}
.bconf__title {
  margin: 0;
  font-size: 13px;
  font-weight: 600;
  color: rgb(var(--v-theme-warning));
}
.bconf__hint {
  margin: 0;
  font-size: 12px;
  line-height: 18px;
  color: rgb(var(--v-theme-on-surface-variant));
}
.bconf__theirs {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 8px 10px;
  border-radius: 6px;
  background: rgb(var(--v-theme-background));
}
.bconf__theirs-head {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.4px;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
}
.bconf__line {
  display: flex;
  gap: 10px;
  font-size: 13px;
  line-height: 19px;
}
.bconf__label {
  flex: none;
  width: 90px;
  color: rgb(var(--v-theme-on-surface-variant));
}
.bconf__text {
  min-width: 0;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  color: rgb(var(--v-theme-on-surface));
}
.bconf__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
</style>
