<script setup lang="ts">
/**
 * PL-2.1 — Add USP / Edit USP. No design frame exists for this dialog; it is a Vuetify dialog with text fields
 * and an explicit Save (no autosave). The colour is the server's: a new USP takes the first colour not in use,
 * so the dialog shows a read-only dot for an existing card and a note for a new one.
 *
 * Edit sends only the fields that changed (a partial PATCH), so two people editing different fields of one card
 * both keep their change. A 404 means the card was removed by someone else.
 */
import type { StrategyLimits, WaveUspPayload, WaveUspResource } from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDisplay } from 'vuetify'

import { brandColorVar } from '@/modules/agency/composables/brandColor'
import { strategyApi, type WaveScope } from '../api/strategy.api'
import { fieldErrorsOf, formErrorFor, isGone } from '../strategyErrors'

const props = defineProps<{
  modelValue: boolean
  scope: WaveScope
  limits: StrategyLimits
  usp?: WaveUspResource | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  saved: [usp: WaveUspResource]
  /** The card is gone on the server; the parent reloads the grid. */
  gone: []
}>()

const { t } = useI18n()
const { xs } = useDisplay()

/** Bumped on every open and close; the card is keyed by it so nothing inside survives a quick reopen. */
const session = ref(0)

const isEdit = computed(() => props.usp !== undefined && props.usp !== null)
const title = ref('')
const explainer = ref('')
const creativeAngle = ref('')
const copySeed = ref('')
const saving = ref(false)
const submitted = ref(false)
const formError = ref<string | null>(null)
const serverErrors = ref<Record<string, string>>({})
const gone = ref(false)

const length = (s: string): number => Array.from(s).length

const titleError = computed(() => {
  if (!submitted.value) return null
  const v = title.value.trim()
  if (v === '') return t('app.strategy.usp.validation.titleRequired')
  if (length(v) > props.limits.usp_title)
    return t('app.strategy.validation.tooLong', { max: props.limits.usp_title })
  return null
})
function longError(value: string, max: number): string | null {
  return submitted.value && length(value.trim()) > max
    ? t('app.strategy.validation.tooLong', { max })
    : null
}
const explainerError = computed(() => longError(explainer.value, props.limits.usp_explainer))
const angleError = computed(() => longError(creativeAngle.value, props.limits.usp_creative_angle))
const seedError = computed(() => longError(copySeed.value, props.limits.usp_copy_seed))

watch(
  () => props.modelValue,
  (open) => {
    session.value++
    if (!open) return
    const a = props.usp?.attributes
    title.value = a?.title ?? ''
    explainer.value = a?.explainer ?? ''
    creativeAngle.value = a?.creative_angle ?? ''
    copySeed.value = a?.copy_seed ?? ''
    saving.value = false
    submitted.value = false
    formError.value = null
    serverErrors.value = {}
    gone.value = false
  },
  { immediate: true },
)

function close(): void {
  if (saving.value) return
  emit('update:modelValue', false)
  if (gone.value) emit('gone')
}

/** `null` clears a text field; the title can never be cleared. */
function textOrNull(v: string): string | null {
  const x = v.trim()
  return x === '' ? null : x
}

async function submit(): Promise<void> {
  submitted.value = true
  formError.value = null
  serverErrors.value = {}
  if (titleError.value || explainerError.value || angleError.value || seedError.value) return

  saving.value = true
  try {
    let res
    if (isEdit.value && props.usp) {
      const a = props.usp.attributes
      const body: WaveUspPayload = {}
      if (title.value.trim() !== a.title) body.title = title.value.trim()
      if (textOrNull(explainer.value) !== a.explainer) body.explainer = textOrNull(explainer.value)
      if (textOrNull(creativeAngle.value) !== a.creative_angle)
        body.creative_angle = textOrNull(creativeAngle.value)
      if (textOrNull(copySeed.value) !== a.copy_seed) body.copy_seed = textOrNull(copySeed.value)
      if (Object.keys(body).length === 0) {
        emit('update:modelValue', false)
        return
      }
      res = await strategyApi.updateUsp(props.scope, props.usp.id, body)
    } else {
      res = await strategyApi.createUsp(props.scope, {
        title: title.value.trim(),
        explainer: textOrNull(explainer.value),
        creative_angle: textOrNull(creativeAngle.value),
        copy_seed: textOrNull(copySeed.value),
      })
    }
    emit('saved', res.data)
    emit('update:modelValue', false)
  } catch (err) {
    if (isGone(err) && isEdit.value) gone.value = true
    serverErrors.value = fieldErrorsOf(err)
    formError.value = formErrorFor(
      err,
      serverErrors.value,
      ['title', 'explainer', 'creative_angle', 'copy_seed'],
      t,
    )
  } finally {
    saving.value = false
  }
}

function fe(field: string, local: string | null): string[] {
  const m = local ?? serverErrors.value[field] ?? null
  return m === null ? [] : [m]
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="560"
    persistent
    :fullscreen="xs"
    data-test="usp-dialog"
    @update:model-value="
      (v: boolean) => {
        if (!v) close()
      }
    "
  >
    <v-card :key="session">
      <v-card-title class="text-h6 pt-4 px-6">
        {{
          isEdit ? t('app.strategy.usp.dialog.editTitle') : t('app.strategy.usp.dialog.addTitle')
        }}
      </v-card-title>
      <v-card-text class="d-flex flex-column ga-4 px-6">
        <v-text-field
          v-model="title"
          :label="t('app.strategy.usp.dialog.title')"
          variant="outlined"
          density="comfortable"
          :counter="limits.usp_title"
          hide-details="auto"
          :error-messages="fe('title', titleError)"
          data-test="usp-field-title"
        />
        <v-textarea
          v-model="explainer"
          :label="t('app.strategy.usp.explainer')"
          variant="outlined"
          density="comfortable"
          rows="3"
          auto-grow
          :counter="limits.usp_explainer"
          hide-details="auto"
          :error-messages="fe('explainer', explainerError)"
          data-test="usp-field-explainer"
        />
        <v-textarea
          v-model="creativeAngle"
          :label="t('app.strategy.usp.creativeAngle')"
          variant="outlined"
          density="comfortable"
          rows="2"
          auto-grow
          :counter="limits.usp_creative_angle"
          hide-details="auto"
          :error-messages="fe('creative_angle', angleError)"
          data-test="usp-field-angle"
        />
        <v-textarea
          v-model="copySeed"
          :label="t('app.strategy.usp.copySeed')"
          variant="outlined"
          density="comfortable"
          rows="2"
          auto-grow
          :counter="limits.usp_copy_seed"
          hide-details="auto"
          :error-messages="fe('copy_seed', seedError)"
          data-test="usp-field-seed"
        />
        <div
          class="d-flex align-center ga-2 text-body-2 text-medium-emphasis"
          data-test="usp-colour-note"
        >
          <span
            v-if="isEdit && usp"
            class="usp-dialog__dot"
            :style="{ '--usp-color': brandColorVar(usp.attributes.color_key) }"
            data-test="usp-colour-dot"
          />
          {{
            isEdit
              ? t('app.strategy.usp.dialog.colourFixed')
              : t('app.strategy.usp.dialog.colourAuto')
          }}
        </div>
        <v-alert
          v-if="formError"
          type="error"
          variant="tonal"
          density="compact"
          data-test="usp-dialog-error"
        >
          {{ formError }}
        </v-alert>
      </v-card-text>
      <v-card-actions class="px-6 pb-4">
        <v-spacer />
        <v-btn variant="text" :disabled="saving" data-test="usp-dialog-cancel" @click="close">
          {{ gone ? t('app.strategy.close') : t('app.strategy.cancel') }}
        </v-btn>
        <v-btn
          v-if="!gone"
          color="primary"
          variant="flat"
          :loading="saving"
          data-test="usp-dialog-save"
          @click="submit"
        >
          {{ t('app.strategy.save') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.usp-dialog__dot {
  display: inline-block;
  width: 12px;
  height: 12px;
  border-radius: 9999px;
  background: var(--usp-color);
}
</style>
