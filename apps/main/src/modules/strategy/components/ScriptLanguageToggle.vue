<script setup lang="ts">
/**
 * PL-3.2b — the language control of the script head (frame 13:3372: a segmented control, border, radius 8, items "EN", "SK",
 * "Side by side"; the selected item has the raised fill and a 600 weight). One item per language, then "Side by side" (English and
 * one local language, two columns for the spoken text and the overlays). With several local languages a small select picks the one
 * that sits beside English. A market with English only draws nothing at all.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import { languageName, localLanguages, type LanguageView } from '../scriptsLogic'

const props = defineProps<{ languages: string[]; view: LanguageView }>()
const emit = defineEmits<{ 'update:view': [view: LanguageView] }>()
const { t, locale } = useI18n()

const local = computed(() => localLanguages(props.languages))
const sideLocal = computed(() =>
  props.view.mode === 'side' ? props.view.local : (local.value[0] ?? ''),
)

function single(language: string): void {
  emit('update:view', { mode: 'single', language })
}
function side(): void {
  if (sideLocal.value !== '') emit('update:view', { mode: 'side', local: sideLocal.value })
}
function pick(e: Event): void {
  emit('update:view', { mode: 'side', local: (e.target as HTMLSelectElement).value })
}
</script>

<template>
  <div v-if="local.length > 0" class="ltoggle" data-test="script-language-toggle">
    <div class="ltoggle__seg" role="group" :aria-label="t('app.strategy.scripts.lang.view')">
      <button
        v-for="l in languages"
        :key="l"
        type="button"
        class="ltoggle__item"
        :class="{ 'ltoggle__item--on': view.mode === 'single' && view.language === l }"
        :aria-pressed="view.mode === 'single' && view.language === l"
        :title="languageName(l, locale)"
        :data-test="`script-language-${l}`"
        @click="single(l)"
      >
        {{ l.toUpperCase() }}
      </button>
      <button
        type="button"
        class="ltoggle__item"
        :class="{ 'ltoggle__item--on': view.mode === 'side' }"
        :aria-pressed="view.mode === 'side'"
        data-test="script-language-side"
        @click="side"
      >
        {{ t('app.strategy.scripts.lang.sideBySide') }}
      </button>
    </div>
    <label v-if="local.length > 1 && view.mode === 'side'" class="ltoggle__with">
      <span class="ltoggle__with-label">{{ t('app.strategy.scripts.lang.with') }}</span>
      <select
        class="ltoggle__select"
        :value="sideLocal"
        data-test="script-language-with"
        @change="pick"
      >
        <option v-for="l in local" :key="l" :value="l">{{ languageName(l, locale) }}</option>
      </select>
    </label>
  </div>
</template>

<style scoped>
.ltoggle {
  display: inline-flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}
.ltoggle__seg {
  display: inline-flex;
  gap: 2px;
  padding: 3px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 8px;
}
.ltoggle__item {
  padding: 4px 10px;
  border: 0;
  border-radius: 6px;
  background: none;
  font: inherit;
  font-size: 12px;
  font-weight: 400;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface-variant));
  cursor: pointer;
}
.ltoggle__item--on {
  background: rgb(var(--v-theme-surface-bright));
  font-weight: 600;
  color: rgb(var(--v-theme-on-surface));
}
.ltoggle__item:focus-visible,
.ltoggle__select:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
}
.ltoggle__with {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: rgb(var(--v-theme-on-surface-variant));
}
.ltoggle__select {
  padding: 3px 6px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 6px;
  background: rgb(var(--v-theme-surface));
  font: inherit;
  font-size: 12px;
  color: rgb(var(--v-theme-on-surface));
}
</style>
