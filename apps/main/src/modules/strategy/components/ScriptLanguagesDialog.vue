<script setup lang="ts">
/**
 * PL-3.2b — the script languages of one market. English is always there and cannot be removed; the market holds up to three more
 * (the server's `max_local`). Removing a language HIDES its texts: nothing is deleted, and the texts come back if the language is
 * added again (said in the dialog, with the number of texts when there are some). The list is edited and saved with one button; the
 * editor's unsaved blocks are saved first (`beforeSave`), so no typed text is caught between two languages. Every agency role may.
 * The card is keyed by a counter that moves on every open and close, so nothing survives a quick reopen.
 */
import type { ScriptLanguagesResource } from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { strategyApi, type WaveScope } from '../api/strategy.api'
import { languageName, SCRIPT_LANGUAGE_OPTIONS } from '../scriptsLogic'
import { strategyErrorMessage } from '../strategyErrors'

const props = defineProps<{
  modelValue: boolean
  scope: WaveScope
  market: string
  marketName: string
  current: ScriptLanguagesResource | null
  /** Saves what the editor holds before the languages change; false stops the change. */
  beforeSave: () => Promise<boolean>
}>()
const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  saved: [resource: ScriptLanguagesResource]
}>()
const { t, locale } = useI18n()

const session = ref(0)
const picked = ref<string[]>([])
const busy = ref(false)
const error = ref<string | null>(null)
const adding = ref<string | null>(null)

watch(
  () => props.modelValue,
  (open) => {
    session.value++
    if (open) {
      picked.value = (props.current?.languages ?? []).map((l) => l.code)
      error.value = null
      adding.value = null
    }
  },
)

const max = computed(() => props.current?.max_local ?? 3)
const usage = computed(() => new Map((props.current?.languages ?? []).map((l) => [l.code, l])))
const removed = computed(() =>
  (props.current?.languages ?? []).map((l) => l.code).filter((c) => !picked.value.includes(c)),
)
const hiddenTexts = computed(() =>
  removed.value.reduce((n, c) => n + (usage.value.get(c)?.texts ?? 0), 0),
)
const changed = computed(
  () => (props.current?.languages ?? []).map((l) => l.code).join() !== picked.value.join(),
)
const full = computed(() => picked.value.length >= max.value)
const options = computed(() =>
  SCRIPT_LANGUAGE_OPTIONS.filter((c) => !picked.value.includes(c)).map((c) => ({
    value: c,
    title: languageName(c, locale.value),
  })),
)

function addLanguage(code: string | null): void {
  if (code === null || full.value || picked.value.includes(code)) return
  picked.value = [...picked.value, code]
  adding.value = null
}
function removeLanguage(code: string): void {
  picked.value = picked.value.filter((c) => c !== code)
}
function close(): void {
  if (busy.value) return
  emit('update:modelValue', false)
}
async function save(): Promise<void> {
  if (busy.value || !changed.value) return
  busy.value = true
  error.value = null
  try {
    if (!(await props.beforeSave())) {
      error.value = t('app.strategy.scripts.lang.unsaved')
      return
    }
    const res = await strategyApi.setScriptLanguages(props.scope, props.market, picked.value)
    emit('saved', res.data)
    emit('update:modelValue', false)
  } catch (err) {
    error.value = strategyErrorMessage(err, t)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="520"
    scrollable
    data-test="script-languages-dialog"
    @update:model-value="(v: boolean) => (v ? undefined : close())"
  >
    <v-card :key="session">
      <v-card-title class="text-h6 text-wrap text-break pt-4 px-6">{{
        t('app.strategy.scripts.lang.dialogTitle', { market: marketName })
      }}</v-card-title>
      <v-card-text class="px-6">
        <p class="ldlg__body">
          {{ t('app.strategy.scripts.lang.dialogBody', { max }) }}
        </p>
        <ul class="ldlg__list" data-test="script-languages-list">
          <li class="ldlg__row" data-test="script-languages-en">
            <span class="ldlg__name">{{ languageName('en', locale) }}</span>
            <span class="ldlg__tag">{{ t('app.strategy.scripts.lang.alwaysOn') }}</span>
          </li>
          <li
            v-for="code in picked"
            :key="code"
            class="ldlg__row"
            :data-test="`script-languages-row-${code}`"
          >
            <span class="ldlg__name">{{ languageName(code, locale) }}</span>
            <span v-if="(usage.get(code)?.texts ?? 0) > 0" class="ldlg__tag">{{
              t('app.strategy.scripts.lang.used', { texts: usage.get(code)?.texts ?? 0 })
            }}</span>
            <v-spacer />
            <v-btn
              size="small"
              variant="text"
              :aria-label="
                t('app.strategy.scripts.lang.remove', { language: languageName(code, locale) })
              "
              :data-test="`script-languages-remove-${code}`"
              @click="removeLanguage(code)"
              >{{ t('app.strategy.scripts.lang.removeShort') }}</v-btn
            >
          </li>
        </ul>
        <v-select
          v-if="!full"
          v-model="adding"
          :items="options"
          item-title="title"
          item-value="value"
          density="compact"
          variant="outlined"
          hide-details
          :label="t('app.strategy.scripts.lang.add')"
          data-test="script-languages-add"
          @update:model-value="addLanguage"
        />
        <p v-else class="ldlg__note" data-test="script-languages-full">
          {{ t('app.strategy.scripts.lang.full', { max }) }}
        </p>
        <p class="ldlg__note" data-test="script-languages-keep">
          {{ t('app.strategy.scripts.lang.keepNote') }}
        </p>
        <p v-if="removed.length > 0" class="ldlg__warn" data-test="script-languages-removing">
          {{ t('app.strategy.scripts.lang.removing', { texts: hiddenTexts }) }}
        </p>
        <v-alert
          v-if="error !== null"
          type="error"
          variant="tonal"
          density="compact"
          class="mt-3"
          data-test="script-languages-error"
          >{{ error }}</v-alert
        >
      </v-card-text>
      <v-card-actions class="px-6 pb-4">
        <v-spacer />
        <v-btn variant="text" :disabled="busy" data-test="script-languages-cancel" @click="close">{{
          t('app.strategy.cancel')
        }}</v-btn>
        <v-btn
          color="primary"
          variant="flat"
          :loading="busy"
          :disabled="!changed"
          data-test="script-languages-save"
          @click="save"
          >{{ t('app.strategy.save') }}</v-btn
        >
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.ldlg__body,
.ldlg__note {
  margin: 0 0 12px;
  font-size: 13px;
  line-height: 1.45;
  color: rgb(var(--v-theme-on-surface-variant));
}
.ldlg__note {
  margin-top: 12px;
  font-size: 12px;
}
.ldlg__warn {
  margin: 8px 0 0;
  font-size: 12px;
  color: rgb(var(--v-theme-warning, var(--v-theme-on-surface)));
}
.ldlg__list {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin: 0 0 12px;
  padding: 0;
  list-style: none;
}
.ldlg__row {
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 36px;
  padding: 2px 10px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 8px;
}
.ldlg__name {
  font-size: 13px;
  font-weight: 500;
  color: rgb(var(--v-theme-on-surface));
}
.ldlg__tag {
  font-size: 11.5px;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
}
</style>
