<script setup lang="ts">
/**
 * PL-3.2a — the brief card (frame 13:3108, node 13:3405): persona, its Priority and Support USPs, the angle, the duration with the
 * estimate, and (walk ruling, no frame: built from the rows above) "The overall setting of the video" under the duration. Persona, USPs and the angle line are read LIVE from steps 2 and 3 (the server sends them with the script); the angle
 * and the duration are the strategist's to edit and autosave as one block with its own revision. A creator with no persona gets
 * the prompt in place of the persona and USPs, and the angle and duration still work.
 */
import type { ScriptDuration, WaveScript } from '@catalyst/api-client'
import { SCRIPT_DURATIONS, SCRIPT_TEXT_LIMITS } from '@catalyst/api-client'
import { computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { brandColorVar } from '@/modules/agency/composables/brandColor'
import { strategyApi, type WaveScope } from '../api/strategy.api'
import { useBlockAutosave } from '../composables/useBlockAutosave'
import { useRegisterBlock } from '../composables/useSaveRegistry'
import { MENU_PROPS } from '../constants'
import {
  briefPayload,
  briefValue,
  estimateFallbackLanguage,
  languageName,
  sameBrief,
  type BriefValue,
} from '../scriptsLogic'
import AutoGrowTextarea from './AutoGrowTextarea.vue'
import BlockConflict from './BlockConflict.vue'
import BlockStatusLine from './BlockStatusLine.vue'

const props = defineProps<{
  script: WaveScript
  scope: WaveScope
  matrixTo: { name: string; params: Record<string, string>; query: Record<string, string> }
  creatorsTo: { name: string; params: Record<string, string>; query: Record<string, string> }
  slotCode: string | null
  angleMax: number
  debounceMs?: number
}>()
const emit = defineEmits<{ saved: [script: WaveScript] }>()
const { t, locale } = useI18n()

const block = useBlockAutosave<BriefValue>({
  remote: () => ({ value: briefValue(props.script.brief), revision: props.script.brief.revision }),
  save: async (value, revision, base) => {
    const res = (
      await strategyApi.saveBrief(props.scope, props.script.id, briefPayload(value, base, revision))
    ).data
    emit('saved', res)
    return { value: briefValue(res.brief), revision: res.brief.revision }
  },
  same: sameBrief,
  fromConflict: (current) =>
    'duration_seconds' in current
      ? { value: briefValue(current), revision: current.revision }
      : null,
  clone: (v) => ({ ...v }),
  debounceMs: props.debounceMs,
})
useRegisterBlock(`brief:${props.script.id}`, { status: block.status, flush: block.flush })
watch(
  () => props.script.brief,
  () => block.syncRemote(),
)

const brief = computed(() => props.script.brief)
const persona = computed(() => brief.value.persona)
const custom = computed(
  () => block.draft.value.reset === false && (brief.value.angle.custom || block.dirty.value),
)
const angleOver = computed(() => block.draft.value.angle.length > props.angleMax)
const settingMax = SCRIPT_TEXT_LIMITS.setting
const settingOver = computed(() => block.draft.value.setting.length > settingMax)
const settingNear = computed(() => block.draft.value.setting.length >= settingMax * 0.8)

function setAngle(v: string): void {
  block.edit((d) => {
    d.angle = v
    d.reset = false
  })
}
function setSetting(v: string): void {
  block.edit((d) => {
    d.setting = v
  })
}
function setDuration(n: ScriptDuration): void {
  block.edit((d) => {
    d.duration = n
  })
}
function useLive(): void {
  block.edit((d) => {
    d.reset = true
  })
  // The server answers with the live line; the block shows it as soon as the save returns.
  void block.flush()
}

const estimate = computed(() => props.script.estimate)
const fallbackLanguage = computed(() => estimateFallbackLanguage(props.script))
const sourceLabel = computed(() => {
  const a = brief.value.angle
  if (a.custom && !block.draft.value.reset) return t('app.strategy.scripts.brief.angleOwn')
  if (a.source === 'persona') return t('app.strategy.scripts.brief.angleFromPersona')
  if (a.source === 'matrix') return t('app.strategy.scripts.brief.angleFromMatrix')
  return ''
})

const mineLines = computed(() => [
  { label: t('app.strategy.scripts.brief.angle'), text: block.draft.value.angle },
  {
    label: t('app.strategy.scripts.brief.duration'),
    text: t('app.strategy.scripts.brief.seconds', { n: block.draft.value.duration }),
  },
  { label: t('app.strategy.scripts.brief.setting'), text: block.draft.value.setting },
])
const theirsLines = computed(() => {
  const th = block.theirs.value
  return th === null
    ? []
    : [
        { label: t('app.strategy.scripts.brief.angle'), text: th.angle },
        {
          label: t('app.strategy.scripts.brief.duration'),
          text: t('app.strategy.scripts.brief.seconds', { n: th.duration }),
        },
        { label: t('app.strategy.scripts.brief.setting'), text: th.setting },
      ]
})
</script>

<template>
  <section class="sbrief" data-test="script-brief">
    <div class="sbrief__head">
      <span class="sbrief__title">
        <v-icon icon="mdi-link-variant" size="14" aria-hidden="true" />
        {{ t('app.strategy.scripts.brief.title') }}
      </span>
      <router-link :to="matrixTo" class="sbrief__link" data-test="script-brief-step3">{{
        t('app.strategy.scripts.brief.editInStep3')
      }}</router-link>
    </div>

    <div v-if="persona" class="sbrief__row">
      <span class="sbrief__label">{{ t('app.strategy.scripts.brief.persona') }}</span>
      <span class="sbrief__persona" data-test="script-brief-persona">
        <span class="sbrief__chip" :style="{ '--chip': brandColorVar(persona.color_key) }">{{
          slotCode ?? persona.code
        }}</span>
        <span class="sbrief__persona-title">{{ persona.title }}</span>
      </span>
    </div>
    <div v-if="persona" class="sbrief__row">
      <span class="sbrief__label">{{ t('app.strategy.scripts.brief.usps') }}</span>
      <span class="sbrief__usps" data-test="script-brief-usps">
        <span
          v-for="u in brief.usps"
          :key="u.id"
          class="sbrief__usp"
          :style="{ '--usp': brandColorVar(u.color_key) }"
          :data-level="u.level"
        >
          <span class="sbrief__usp-dot" aria-hidden="true" />
          <span class="sbrief__usp-name">{{ u.title }}</span>
          <span class="sbrief__usp-level">{{
            u.level === 'priority'
              ? t('app.strategy.scripts.brief.priority')
              : t('app.strategy.scripts.brief.support')
          }}</span>
        </span>
        <span v-if="brief.usps.length === 0" class="sbrief__none">{{
          t('app.strategy.scripts.brief.noUsps')
        }}</span>
      </span>
    </div>
    <div v-else class="sbrief__prompt" data-test="script-brief-no-persona">
      <v-icon icon="mdi-account-question-outline" size="16" aria-hidden="true" />
      <span>{{ t('app.strategy.scripts.brief.noPersona') }}</span>
      <router-link :to="creatorsTo" class="sbrief__link" data-test="script-brief-step5">{{
        t('app.strategy.scripts.brief.openStep5')
      }}</router-link>
    </div>

    <div class="sbrief__row sbrief__row--top">
      <label class="sbrief__label" :for="`angle-${script.id}`">{{
        t('app.strategy.scripts.brief.angle')
      }}</label>
      <div class="sbrief__angle">
        <AutoGrowTextarea
          :id="`angle-${script.id}`"
          :model-value="block.draft.value.angle"
          class="sbrief__field"
          :placeholder="t('app.strategy.scripts.brief.anglePlaceholder')"
          :error="angleOver"
          data-test="script-angle"
          @update:model-value="(v: string) => setAngle(v)"
        />
        <div class="sbrief__meta">
          <span v-if="sourceLabel" class="sbrief__source" data-test="script-angle-source">{{
            sourceLabel
          }}</span>
          <button
            v-if="persona && custom"
            type="button"
            class="sbrief__reset"
            data-test="script-angle-live"
            @click="useLive"
          >
            {{ t('app.strategy.scripts.brief.useLive') }}
          </button>
          <span
            class="sbrief__count"
            :class="{ 'sbrief__count--over': angleOver }"
            data-test="script-angle-count"
          >
            {{ block.draft.value.angle.length }} / {{ angleMax }}
          </span>
        </div>
      </div>
    </div>

    <div class="sbrief__row">
      <span class="sbrief__label">{{ t('app.strategy.scripts.brief.duration') }}</span>
      <v-menu v-bind="MENU_PROPS" location="bottom start">
        <template #activator="{ props: act }">
          <button
            v-bind="act"
            type="button"
            class="sbrief__duration"
            :aria-label="t('app.strategy.scripts.brief.duration')"
            data-test="script-duration"
          >
            <v-icon icon="mdi-clock-outline" size="13" aria-hidden="true" />
            <span>{{
              t('app.strategy.scripts.brief.seconds', { n: block.draft.value.duration })
            }}</span>
            <v-icon icon="mdi-chevron-down" size="13" aria-hidden="true" />
          </button>
        </template>
        <v-list density="compact" data-test="script-duration-menu">
          <v-list-item
            v-for="n in SCRIPT_DURATIONS"
            :key="n"
            :active="n === block.draft.value.duration"
            :data-test="`script-duration-${n}`"
            @click="setDuration(n)"
          >
            <v-list-item-title>{{
              t('app.strategy.scripts.brief.seconds', { n })
            }}</v-list-item-title>
          </v-list-item>
        </v-list>
      </v-menu>
      <span class="sbrief__estimate" data-test="script-estimate">{{
        estimate.words > 0
          ? t('app.strategy.scripts.brief.estimate', { n: estimate.seconds })
          : t('app.strategy.scripts.brief.noEstimate')
      }}</span>
      <span
        v-if="estimate.words > 0 && fallbackLanguage !== null"
        class="sbrief__estimate-hint"
        data-test="script-estimate-hint"
        >{{
          t('app.strategy.scripts.lang.estimateFallback', {
            language: languageName(fallbackLanguage, locale),
          })
        }}</span
      >
    </div>

    <div class="sbrief__row sbrief__row--top">
      <label class="sbrief__label" :for="`setting-${script.id}`">{{
        t('app.strategy.scripts.brief.setting')
      }}</label>
      <div class="sbrief__angle">
        <AutoGrowTextarea
          :id="`setting-${script.id}`"
          :model-value="block.draft.value.setting"
          class="sbrief__field"
          :placeholder="t('app.strategy.scripts.brief.settingPlaceholder')"
          :error="settingOver"
          data-test="script-setting"
          @update:model-value="(v: string) => setSetting(v)"
        />
        <div v-if="settingNear" class="sbrief__meta">
          <span
            class="sbrief__count"
            :class="{ 'sbrief__count--over': settingOver }"
            data-test="script-setting-count"
          >
            {{ block.draft.value.setting.length }} / {{ settingMax }}
          </span>
        </div>
      </div>
    </div>

    <BlockConflict
      v-if="block.status.value === 'conflict'"
      :theirs="theirsLines"
      :mine="mineLines"
      test-id="script-brief-conflict"
      @keep="block.keepMine()"
      @theirs="block.useTheirs()"
    />
    <div class="sbrief__foot">
      <BlockStatusLine
        :status="block.status.value"
        :failure="block.failure.value"
        test-id="script-brief-status"
        @retry="block.retry()"
      />
    </div>
  </section>
</template>

<style scoped>
.sbrief {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 16px;
  border: 1px solid color-mix(in srgb, rgb(var(--v-theme-primary)) 30%, transparent);
  border-radius: 10px;
  background: color-mix(in srgb, rgb(var(--v-theme-primary)) 5%, transparent);
}
.sbrief__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.sbrief__title {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  font-weight: 600;
  line-height: normal;
  color: rgb(var(--v-theme-primary));
}
.sbrief__link {
  font-size: 12px;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface-variant));
  white-space: nowrap;
}
.sbrief__link:hover {
  color: rgb(var(--v-theme-on-surface));
}
.sbrief__row {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}
.sbrief__row--top {
  align-items: flex-start;
}
.sbrief__label {
  flex: none;
  width: 80px;
  font-size: 12px;
  line-height: normal;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
}
.sbrief__persona {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-width: 0;
}
.sbrief__chip {
  flex: none;
  padding: 1px 5px;
  border: 1px solid color-mix(in srgb, var(--chip) 70%, transparent);
  border-radius: 4px;
  font-size: 10px;
  font-weight: 700;
  line-height: normal;
  color: var(--chip);
}
.sbrief__persona-title {
  font-size: 13px;
  font-weight: 500;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface));
  overflow-wrap: anywhere;
}
.sbrief__usps {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  min-width: 0;
}
.sbrief__usp {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  max-width: 100%;
  padding: 3px 9px;
  border-radius: 9999px;
  background: color-mix(in srgb, var(--usp) 14%, transparent);
}
.sbrief__usp-dot {
  flex: none;
  width: 6px;
  height: 6px;
  border-radius: 9999px;
  background: var(--usp);
}
.sbrief__usp-name {
  min-width: 0;
  font-size: 12px;
  font-weight: 500;
  line-height: normal;
  color: var(--usp);
  overflow-wrap: anywhere;
}
.sbrief__usp-level {
  flex: none;
  font-size: 11px;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface-variant));
}
.sbrief__usp[data-level='priority'] .sbrief__usp-level {
  color: rgb(var(--v-theme-success));
}
.sbrief__none {
  font-size: 12px;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
}
.sbrief__prompt {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  padding: 10px 12px;
  border: 1px dashed rgb(var(--v-theme-border-color));
  border-radius: 8px;
  font-size: 13px;
  line-height: 19px;
  color: rgb(var(--v-theme-on-surface-variant));
}
.sbrief__angle {
  flex: 1 1 0;
  min-width: 0;
}
.sbrief__field {
  margin: 0;
}
.sbrief__meta {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 4px;
  font-size: 11px;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
}
.sbrief__reset {
  padding: 0;
  border: 0;
  background: none;
  font: inherit;
  color: rgb(var(--v-theme-primary));
  cursor: pointer;
  text-decoration: underline;
}
.sbrief__count {
  margin-left: auto;
}
.sbrief__count--over {
  color: rgb(var(--v-theme-error));
  font-weight: 600;
}
.sbrief__duration {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 10px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 6px;
  background: none;
  font: inherit;
  font-size: 13px;
  font-weight: 500;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface));
  cursor: pointer;
}
.sbrief__duration:hover {
  background: rgb(var(--v-theme-surface-bright));
}
.sbrief__estimate-hint {
  font-size: 11.5px;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
}
.sbrief__estimate {
  font-size: 12px;
  line-height: normal;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
}
.sbrief__foot {
  display: flex;
  justify-content: flex-end;
  min-height: 16px;
  margin-top: -4px;
}
@media (max-width: 599px) {
  .sbrief__row {
    flex-wrap: wrap;
  }
  .sbrief__label {
    width: 100%;
  }
}
</style>
