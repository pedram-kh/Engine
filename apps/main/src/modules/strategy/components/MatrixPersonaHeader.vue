<script setup lang="ts">
/**
 * PL-2.2 — the header of one persona column (frame 13:1375): the code chip in the persona's colour, the title, the
 * name with the first line of the summary, and "Lead with:" followed by the persona's Priority USPs joined with " + ".
 * A persona with no Priority cell reads "Lead with: —" in the muted colour (Q8).
 */
import type { MatrixPersona } from '@catalyst/api-client'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import { brandColorVar } from '@/modules/agency/composables/brandColor'

const props = defineProps<{ persona: MatrixPersona; lead: string[] }>()
const { t } = useI18n()

const nameLine = computed(() => {
  const first = (props.persona.summary ?? '').split('\n')[0]?.trim() ?? ''
  return first === '' ? props.persona.name : `${props.persona.name} · ${first}`
})
</script>

<template>
  <div
    class="mx-phead"
    :style="{ '--usp-color': brandColorVar(persona.color_key) }"
    data-test="matrix-persona-header"
    :data-persona-id="persona.id"
  >
    <div class="mx-phead__line">
      <span class="mx-phead__code" data-test="matrix-persona-code">{{ persona.code }}</span>
      <span class="mx-phead__title" data-test="matrix-persona-title">{{ persona.title }}</span>
    </div>
    <p class="mx-phead__name" :title="nameLine" data-test="matrix-persona-name">{{ nameLine }}</p>
    <p class="mx-phead__lead" data-test="matrix-lead">
      <span class="mx-phead__lead-label">{{ t('app.strategy.matrix.leadWith') }}</span>
      <span v-if="lead.length > 0" class="mx-phead__lead-names" data-test="matrix-lead-names">{{
        lead.join(' + ')
      }}</span>
      <span v-else class="mx-phead__lead-none" data-test="matrix-lead-none">—</span>
    </p>
  </div>
</template>

<style scoped>
/*
 * Values from frame 13:1375: padding 16 / 20, gap 6, a left border; the chip has a 1 px border in the persona colour at
 * 60 %, radius 4, padding 2 / 6, 11 / 700; the title is 16 / 600; the name line 12; "Lead with:" 12 with the names in
 * 12 / 600 green.
 */
.mx-phead {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 6px;
  min-width: 0;
  padding: 16px 20px;
  border-left: 1px solid rgb(var(--v-theme-border-color));
  background: rgb(var(--v-theme-surface-bright));
}
.mx-phead__line {
  display: flex;
  align-items: center;
  gap: 8px;
  max-width: 100%;
  min-width: 0;
}
.mx-phead__code {
  flex: none;
  padding: 2px 6px;
  border: 1px solid color-mix(in srgb, var(--usp-color) 60%, transparent);
  border-radius: 4px;
  font-size: 11px;
  font-weight: 700;
  line-height: normal;
  color: var(--usp-color);
}
.mx-phead__title {
  min-width: 0;
  font-size: 16px;
  font-weight: 600;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface));
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.mx-phead__name {
  margin: 0;
  max-width: 100%;
  font-size: 12px;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface-variant));
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.mx-phead__lead {
  display: flex;
  flex-wrap: wrap;
  gap: 0 6px;
  margin: 0;
  font-size: 12px;
  line-height: normal;
}
.mx-phead__lead-label {
  color: rgb(var(--v-theme-text-tertiary));
}
.mx-phead__lead-names {
  font-weight: 600;
  color: var(--mx-priority);
}
.mx-phead__lead-none {
  color: rgb(var(--v-theme-text-tertiary));
}
</style>
