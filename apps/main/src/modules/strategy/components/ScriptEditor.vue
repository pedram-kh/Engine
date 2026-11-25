<script setup lang="ts">
/**
 * PL-3.2a — the editor of one script (frame 13:3108, the middle column): the title row, the workflow bar (one read-only "Draft"
 * pill until PL-3.3), the brief and the hooks. The script's Lines, B-roll and images are PL-3.2b: nothing is drawn for them
 * here. It loads the script it is given and is re-created (keyed) for another script, after the page flushed the old one.
 * Every block autosaves on its own; the chip in the title row is the worst state of all of them.
 */
import type { ScriptsOverview, WaveScript } from '@catalyst/api-client'
import { SCRIPT_TEXT_LIMITS } from '@catalyst/api-client'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { creatorLabel } from '@/core/creators/creatorLabel'
import { brandColorVar } from '@/modules/agency/composables/brandColor'
import { strategyApi, type WaveScope } from '../api/strategy.api'
import { provideSaveRegistry } from '../composables/useSaveRegistry'
import { isGone, strategyErrorMessage } from '../strategyErrors'
import ScriptBrief from './ScriptBrief.vue'
import ScriptHookCard from './ScriptHookCard.vue'
import StrategyConfirmDialog from './StrategyConfirmDialog.vue'

type Route = { name: string; params: Record<string, string>; query: Record<string, string> }

const props = defineProps<{
  scope: WaveScope
  scriptId: string
  hooksMax: number
  matrixTo: Route
  creatorsTo: Route
  debounceMs?: number
}>()
const emit = defineEmits<{
  /** The script was archived (the answer is the new overview of the wave). */
  archived: [overview: ScriptsOverview]
  /** Somebody else removed it (404). */
  gone: []
}>()
const { t, locale } = useI18n()

const registry = provideSaveRegistry()
const script = ref<WaveScript | null>(null)
const loading = ref(true)
const loadError = ref<string | null>(null)
const actionError = ref<string | null>(null)
const addingHook = ref(false)

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    script.value = (await strategyApi.script(props.scope, props.scriptId)).data
  } catch (err) {
    if (isGone(err)) {
      emit('gone')
      return
    }
    loadError.value = strategyErrorMessage(err, t)
  } finally {
    loading.value = false
  }
}
onMounted(load)

// Only what a save changed is merged in: a late answer must never put an older hook list or brief back.
function onBriefSaved(s: WaveScript): void {
  const cur = script.value
  if (cur === null) return
  script.value = {
    ...cur,
    brief: s.brief,
    estimate: s.estimate,
    updated_at: s.updated_at,
    revision: s.revision,
  }
}
function onHookSaved(s: WaveScript): void {
  const cur = script.value
  if (cur === null) return
  const byId = new Map(s.hooks.map((h) => [h.id, h]))
  script.value = {
    ...cur,
    hooks: cur.hooks.map((h) => {
      const next = byId.get(h.id)
      return next !== undefined && next.revision > h.revision ? next : h
    }),
    estimate: s.estimate,
    updated_at: s.updated_at,
    revision: s.revision,
  }
}

async function structural(call: () => Promise<{ data: WaveScript }>): Promise<string | null> {
  actionError.value = null
  try {
    script.value = (await call()).data
    return null
  } catch (err) {
    if (isGone(err)) {
      emit('gone')
      return null
    }
    return strategyErrorMessage(err, t)
  }
}
async function addHook(): Promise<void> {
  if (addingHook.value) return
  addingHook.value = true
  actionError.value = await structural(() => strategyApi.addHook(props.scope, props.scriptId))
  addingHook.value = false
}
async function duplicateHook(hookId: string): Promise<void> {
  actionError.value = await structural(() =>
    strategyApi.duplicateHook(props.scope, props.scriptId, hookId),
  )
}
function removeHook(hookId: string): Promise<string | null> {
  return structural(() => strategyApi.deleteHook(props.scope, props.scriptId, hookId))
}

// Archive the script (every agency role): it moves to the archived list with its hooks and texts, and can be restored there.
const archiveOpen = ref(false)
const archiving = ref(false)
const archiveError = ref<string | null>(null)
async function doArchive(): Promise<void> {
  if (archiving.value) return
  archiving.value = true
  archiveError.value = null
  try {
    const res = await strategyApi.archiveScript(props.scope, props.scriptId)
    archiveOpen.value = false
    emit('archived', res.data)
  } catch (err) {
    if (isGone(err)) {
      archiveOpen.value = false
      emit('gone')
    } else archiveError.value = strategyErrorMessage(err, t)
  } finally {
    archiving.value = false
  }
}
function askArchive(): void {
  archiveError.value = null
  archiveOpen.value = true
}

const chip = computed(() => registry.state.value)
const lastEdited = computed(() => {
  const at = script.value?.updated_at
  if (at === undefined) return ''
  return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(
    new Date(at),
  )
})
const slotCode = computed(() => script.value?.slot?.slot_code ?? null)
const personaColor = computed(() => {
  const p = script.value?.brief.persona
  return p ? brandColorVar(p.color_key) : 'rgb(var(--v-theme-on-surface-variant))'
})
const creatorName = computed(() =>
  script.value === null
    ? ''
    : creatorLabel(script.value.creator, t, t('app.strategy.creators.card.unnamed')),
)

// A page that is being left or a script that is being switched asks the editor.
function beforeUnload(e: BeforeUnloadEvent): void {
  if (registry.unsaved.value) {
    e.preventDefault()
    e.returnValue = ''
  }
}
onMounted(() => window.addEventListener('beforeunload', beforeUnload))
onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload))

defineExpose({ unsaved: registry.unsaved, flushAll: registry.flushAll })
</script>

<template>
  <div class="sed" data-test="script-editor" :data-script="scriptId">
    <v-skeleton-loader v-if="loading" type="article" data-test="script-editor-loading" />
    <div
      v-else-if="loadError !== null || script === null"
      class="sed__error"
      role="alert"
      data-test="script-editor-error"
    >
      <p>{{ loadError ?? t('app.strategy.scripts.editor.loadFailed') }}</p>
      <v-btn size="small" variant="outlined" data-test="script-editor-retry" @click="load">{{
        t('app.strategy.scripts.save.retry')
      }}</v-btn>
    </div>
    <template v-else>
      <header class="sed__head">
        <div class="sed__who">
          <span class="sed__chip" :style="{ '--chip': personaColor }" data-test="script-slot">{{
            slotCode ?? '—'
          }}</span>
          <div class="sed__titles">
            <h2 class="sed__title" data-test="script-title">
              {{ t('app.strategy.scripts.editor.title', { name: creatorName, n: script.number }) }}
            </h2>
            <p class="sed__edited" data-test="script-last-edited">
              {{ t('app.strategy.scripts.editor.lastEdited', { when: lastEdited }) }}
            </p>
          </div>
        </div>
        <div class="sed__tools">
          <span
            class="sed__save"
            :class="`sed__save--${chip.kind}`"
            :role="chip.kind === 'stuck' ? 'alert' : 'status'"
            :data-state="chip.kind"
            data-test="script-save-chip"
          >
            <template v-if="chip.kind === 'saving'">{{
              t('app.strategy.scripts.save.saving')
            }}</template>
            <template v-else-if="chip.kind === 'stuck'">{{
              t('app.strategy.scripts.save.stuck', { n: chip.stuck }, chip.stuck)
            }}</template>
            <template v-else
              ><v-icon icon="mdi-check" size="13" aria-hidden="true" />{{
                t('app.strategy.scripts.save.allSaved')
              }}</template
            >
          </span>
          <v-btn
            variant="text"
            size="small"
            prepend-icon="mdi-archive-outline"
            data-test="script-archive"
            @click="askArchive"
            >{{ t('app.strategy.scripts.editor.archive') }}</v-btn
          >
        </div>
      </header>

      <div class="sed__flow" data-test="script-workflow">
        <span class="sed__pill" data-test="script-status-pill">{{
          t('app.strategy.scripts.rail.draft')
        }}</span>
      </div>

      <v-alert
        v-if="actionError !== null"
        type="error"
        variant="tonal"
        density="compact"
        closable
        data-test="script-action-error"
        @click:close="actionError = null"
        >{{ actionError }}</v-alert
      >

      <ScriptBrief
        :script="script"
        :scope="scope"
        :matrix-to="matrixTo"
        :creators-to="creatorsTo"
        :slot-code="slotCode"
        :angle-max="SCRIPT_TEXT_LIMITS.angle"
        :debounce-ms="debounceMs"
        @saved="onBriefSaved"
      />

      <div class="sed__section">
        <h3 class="sed__section-title">{{ t('app.strategy.scripts.hooks.title') }}</h3>
        <v-btn
          variant="outlined"
          class="sed__add"
          size="small"
          prepend-icon="mdi-plus"
          :disabled="script.hooks.length >= hooksMax || addingHook"
          :loading="addingHook"
          data-test="script-add-hook"
          @click="addHook"
          >{{
            script.hooks.length >= hooksMax
              ? t('app.strategy.scripts.hooks.limit', { max: hooksMax })
              : t('app.strategy.scripts.hooks.add', { n: script.hooks.length, max: hooksMax })
          }}</v-btn
        >
      </div>

      <div class="sed__hooks" data-test="script-hooks">
        <ScriptHookCard
          v-for="h in script.hooks"
          :key="h.id"
          :hook="h"
          :script-id="script.id"
          :scope="scope"
          :can-delete="script.hooks.length > 1"
          :can-add="script.hooks.length < hooksMax"
          :limits="SCRIPT_TEXT_LIMITS"
          :remove-fn="() => removeHook(h.id)"
          :debounce-ms="debounceMs"
          @saved="onHookSaved"
          @duplicate="duplicateHook(h.id)"
        />
      </div>

      <StrategyConfirmDialog
        v-model="archiveOpen"
        :title="
          t('app.strategy.scripts.editor.archiveTitle', { name: creatorName, n: script.number })
        "
        :body="
          t(
            'app.strategy.scripts.editor.archiveBody',
            { count: script.hooks.length },
            script.hooks.length,
          )
        "
        :confirm-label="t('app.strategy.scripts.editor.archiveConfirm')"
        :busy="archiving"
        :error="archiveError"
        confirm-color="primary"
        test-id="script-archive-dialog"
        @confirm="doArchive"
      />
    </template>
  </div>
</template>

<style scoped>
.sed {
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-width: 0;
}
.sed__error {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 16px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 10px;
  color: rgb(var(--v-theme-error));
}
.sed__error p {
  margin: 0;
}
.sed__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}
.sed__who {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}
.sed__chip {
  flex: none;
  padding: 2px 6px;
  border: 1px solid color-mix(in srgb, var(--chip) 70%, transparent);
  border-radius: 4px;
  font-size: 11px;
  font-weight: 700;
  line-height: normal;
  color: var(--chip);
}
.sed__titles {
  min-width: 0;
}
.sed__title {
  margin: 0;
  font-size: 20px;
  font-weight: 600;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface));
  overflow-wrap: anywhere;
}
.sed__edited {
  margin: 4px 0 0;
  font-size: 12px;
  line-height: normal;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
}
.sed__tools {
  display: flex;
  align-items: center;
  flex: none;
  gap: 8px;
}
.sed__save {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
}
.sed__save--stuck {
  color: rgb(var(--v-theme-error));
  font-weight: 600;
}
.sed__flow {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 10px;
  background: rgb(var(--v-theme-surface));
}
.sed__pill {
  padding: 5px 6px;
  border-radius: 9999px;
  background: rgb(var(--v-theme-surface-bright));
  font-size: 12px;
  font-weight: 600;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface-variant));
}
.sed__hooks {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.sed__section {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding-top: 6px;
}
.sed__section-title {
  margin: 0;
  font-size: 17px;
  font-weight: 600;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface));
}
.sed__add {
  flex: none;
  height: auto !important;
  padding: 6px 12px !important;
  border-color: rgb(var(--v-theme-border-color)) !important;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 500;
  line-height: normal;
  text-transform: none;
  letter-spacing: normal;
}
.sed__add :deep(.v-btn__prepend) {
  margin-inline: 0 8px;
}
.sed__add :deep(.v-icon) {
  font-size: 14px;
  width: 14px;
  height: 14px;
}
@media (max-width: 599px) {
  .sed__head {
    flex-direction: column;
    align-items: flex-start;
  }
}
</style>
