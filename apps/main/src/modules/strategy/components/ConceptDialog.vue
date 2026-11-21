<script setup lang="ts">
/**
 * PL-2.3 — create or edit a concept (frame 13:4412 shows the card, not this dialog; it follows the PL-2.2 editor).
 *
 * Persona, funnel stage and its note, then the title, the nine card fields and the key points. The USP chips are
 * pre-filled from the persona's matrix (Priority first, then Support) and can be changed by hand; on create the server
 * prefills them again when the chips were not touched. Changing the persona of a concept asks Replace or Keep for the chips.
 *
 * Saving an Approved concept with a change asks first (`useConfirmReset`): the client's approval will be cleared. Cancel
 * leaves the dialog open and writes nothing. Errors sit above the actions at full height (the dialog is `scrollable`).
 * Everything the person types is sent as text and drawn as text.
 */
import type {
  ConceptPayload,
  FunnelStage,
  StrategyLimits,
  WaveConceptResource,
  WavePersonaResource,
  WaveUspResource,
} from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { strategyApi, type WaveScope } from '../api/strategy.api'
import { FUNNEL_STAGES, nextFunnelNote } from '../conceptsLogic'
import { MENU_PROPS } from '../constants'
import { useConfirmReset } from '../composables/confirmReset'
import { fieldErrorsOf, strategyErrorMessage } from '../strategyErrors'
import ListField from './ListField.vue'

const props = defineProps<{
  modelValue: boolean
  scope: WaveScope
  market: string
  /** The concept being edited; null creates a new one. */
  concept: WaveConceptResource | null
  /** The personas of this market. */
  personas: WavePersonaResource[]
  usps: WaveUspResource[]
  limits: StrategyLimits
  /** A persona to preselect on create. */
  initialPersona?: string | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  saved: [concept: WaveConceptResource, created: boolean]
}>()

const { t } = useI18n()
const confirmReset = useConfirmReset()

const session = ref(0)
const saving = ref(false)
const error = ref<string | null>(null)
const submitted = ref(false)
const fieldErrors = ref<Record<string, string>>({})

const personaId = ref<string | null>(null)
const stage = ref<FunnelStage | null>(null)
const funnelNote = ref('')
const title = ref('')
const product = ref('')
const angle = ref('')
const primaryMessage = ref('')
const hypothesis = ref('')
const hook = ref('')
const formatVisual = ref('')
const keyPoints = ref<string[]>([])
const payoff = ref('')
const cta = ref('')
const chips = ref<string[]>([])
const chipsTouched = ref(false)
const uspMode = ref<'keep' | 'refill' | null>(null)

const editing = computed(() => props.concept !== null)
const persona = computed(() => props.personas.find((p) => p.id === personaId.value) ?? null)
const personaChanged = computed(
  () => editing.value && personaId.value !== props.concept?.attributes.persona.id,
)

/** The persona's matrix USPs in the order a card shows them: Priority, then Support, each in the wave's USP order. */
function matrixChips(p: WavePersonaResource | null): string[] {
  if (p === null) return []
  const order = new Map(props.usps.map((u, i) => [u.id, i]))
  const pick = (level: 'priority' | 'support'): string[] =>
    p.attributes.matrix_cells
      .filter((c) => c.level === level && order.has(c.usp))
      .sort((x, y) => (order.get(x.usp) ?? 0) - (order.get(y.usp) ?? 0))
      .map((c) => c.usp)
  return [...pick('priority'), ...pick('support')]
}

watch(
  () => props.modelValue,
  (open) => {
    session.value++
    if (!open) return
    const c = props.concept?.attributes ?? null
    saving.value = false
    error.value = null
    submitted.value = false
    fieldErrors.value = {}
    personaId.value = c?.persona.id ?? props.initialPersona ?? props.personas[0]?.id ?? null
    stage.value = c?.funnel_stage ?? null
    funnelNote.value = c?.funnel_note ?? ''
    title.value = c?.title ?? ''
    product.value = c?.product ?? ''
    angle.value = c?.angle ?? ''
    primaryMessage.value = c?.primary_message ?? ''
    hypothesis.value = c?.hypothesis ?? ''
    hook.value = c?.hook ?? ''
    formatVisual.value = c?.format_visual ?? ''
    keyPoints.value = c ? [...c.key_points] : []
    payoff.value = c?.payoff ?? ''
    cta.value = c?.cta ?? ''
    chipsTouched.value = false
    uspMode.value = null
    chips.value = c ? c.usps.map((u) => u.id) : matrixChips(persona.value)
  },
  { immediate: true },
)

// A new concept's chips follow the chosen persona until a chip is changed by hand.
watch(personaId, () => {
  if (!editing.value && !chipsTouched.value) chips.value = matrixChips(persona.value)
  if (!personaChanged.value) uspMode.value = null
})

function onStage(next: FunnelStage | null): void {
  funnelNote.value = nextFunnelNote(stage.value, funnelNote.value, next) ?? ''
  stage.value = next
}

function fe(key: string, otherwise: string[] = []): string[] {
  const m = fieldErrors.value[key]
  return m === undefined ? otherwise : [m]
}
const len = (v: string): number => Array.from(v.trim()).length
const L = computed(() => props.limits)
function over(v: string, max: number): string[] {
  return submitted.value && len(v) > max ? [t('app.strategy.validation.tooLong', { max })] : []
}
const titleErrors = computed(() => {
  if (fieldErrors.value['title']) return [fieldErrors.value['title']]
  if (submitted.value && title.value.trim() === '') return [t('app.strategy.validation.required')]
  return over(title.value, L.value.concept_title)
})
const personaItems = computed(() =>
  props.personas.map((p) => ({
    value: p.id,
    title: `${p.attributes.code} · ${p.attributes.title}`,
  })),
)
const uspItems = computed(() => props.usps.map((u) => ({ value: u.id, title: u.attributes.title })))
const stageItems = computed(() => [
  { value: null, title: t('app.strategy.concepts.stage.none') },
  ...FUNNEL_STAGES.map((s) => ({ value: s, title: t(`app.strategy.concepts.stage.${s}`) })),
])

const textFields = computed(() => [
  { key: 'product', model: product, max: L.value.concept_product, rows: 1 },
  { key: 'angle', model: angle, max: L.value.concept_angle, rows: 2 },
  { key: 'primary_message', model: primaryMessage, max: L.value.concept_primary_message, rows: 2 },
  { key: 'hypothesis', model: hypothesis, max: L.value.concept_hypothesis, rows: 2 },
  { key: 'hook', model: hook, max: L.value.concept_hook, rows: 2 },
  { key: 'format_visual', model: formatVisual, max: L.value.concept_format_visual, rows: 2 },
])
const tailFields = computed(() => [
  { key: 'payoff', model: payoff, max: L.value.concept_payoff, rows: 2 },
  { key: 'cta', model: cta, max: L.value.concept_cta, rows: 1 },
])

const orNull = (v: string): string | null => (v.trim() === '' ? null : v.trim())
const sameList = (x: string[], y: string[]): boolean =>
  x.length === y.length && x.every((v, i) => v === y[i])

/** What would be sent: everything for a new concept, only what changed for an edit. */
function buildPayload(): ConceptPayload {
  const cur = props.concept?.attributes ?? null
  const next: ConceptPayload = {}
  const set = <K extends keyof ConceptPayload>(
    k: K,
    v: ConceptPayload[K],
    was: ConceptPayload[K] | undefined,
  ): void => {
    if (
      cur === null
        ? v !== null && v !== undefined && !(Array.isArray(v) && v.length === 0)
        : v !== was
    )
      next[k] = v
  }
  if (cur === null) {
    next.market = props.market
    if (personaId.value !== null) next.persona = personaId.value
  } else if (personaChanged.value && personaId.value !== null) {
    next.persona = personaId.value
    if (uspMode.value !== null) next.usp_mode = uspMode.value
  }
  set('title', title.value.trim(), cur?.title)
  set('funnel_stage', stage.value, cur?.funnel_stage ?? null)
  set('funnel_note', orNull(funnelNote.value), cur?.funnel_note ?? null)
  set('product', orNull(product.value), cur?.product ?? null)
  set('angle', orNull(angle.value), cur?.angle ?? null)
  set('primary_message', orNull(primaryMessage.value), cur?.primary_message ?? null)
  set('hypothesis', orNull(hypothesis.value), cur?.hypothesis ?? null)
  set('hook', orNull(hook.value), cur?.hook ?? null)
  set('format_visual', orNull(formatVisual.value), cur?.format_visual ?? null)
  set('payoff', orNull(payoff.value), cur?.payoff ?? null)
  set('cta', orNull(cta.value), cur?.cta ?? null)
  const points = keyPoints.value.map((p) => p.trim()).filter((p) => p !== '')
  if (cur === null ? points.length > 0 : !sameList(points, cur.key_points)) next.key_points = points
  if (chipsTouched.value) {
    next.usps = chips.value
    if (personaChanged.value && uspMode.value === null) next.usp_mode = 'keep'
  }
  return next
}

const clientInvalid = computed(
  () =>
    title.value.trim() === '' ||
    persona.value === null ||
    len(title.value) > L.value.concept_title ||
    len(funnelNote.value) > L.value.concept_funnel_note ||
    textFields.value.some((f) => len(f.model.value) > f.max) ||
    tailFields.value.some((f) => len(f.model.value) > f.max) ||
    keyPoints.value.length > L.value.concept_key_points ||
    keyPoints.value.some((p) => len(p) > L.value.concept_key_point) ||
    (personaChanged.value && !chipsTouched.value && uspMode.value === null),
)

function close(): void {
  if (!saving.value) emit('update:modelValue', false)
}

async function submit(): Promise<void> {
  submitted.value = true
  error.value = null
  fieldErrors.value = {}
  if (clientInvalid.value || saving.value) return
  const payload = buildPayload()
  if (props.concept !== null) {
    // Nothing changed: nothing is written, nothing is audited and an approval stays.
    if (Object.keys(payload).length === 0) {
      emit('update:modelValue', false)
      return
    }
    if (!(await confirmReset(props.concept))) return
  }
  saving.value = true
  try {
    const res =
      props.concept === null
        ? await strategyApi.createConcept(props.scope, payload)
        : await strategyApi.updateConcept(props.scope, props.concept.id, payload)
    emit('saved', res.data, props.concept === null)
    emit('update:modelValue', false)
  } catch (err) {
    fieldErrors.value = fieldErrorsOf(err)
    error.value =
      Object.keys(fieldErrors.value).length > 0
        ? t('app.strategy.concepts.dialog.fixFields')
        : strategyErrorMessage(err, t)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="640"
    persistent
    scrollable
    data-test="concept-dialog"
    @update:model-value="
      (v: boolean) => {
        if (!v) close()
      }
    "
  >
    <v-card :key="session">
      <v-card-title class="text-h6 text-wrap text-break pt-4 px-6">
        {{
          editing
            ? t('app.strategy.concepts.dialog.editTitle')
            : t('app.strategy.concepts.dialog.addTitle', { market })
        }}
      </v-card-title>
      <v-card-text class="px-6">
        <div class="concept-dialog__row">
          <v-select
            v-model="personaId"
            :items="personaItems"
            :label="t('app.strategy.concepts.dialog.persona')"
            variant="outlined"
            density="comfortable"
            :menu-props="MENU_PROPS"
            :error-messages="fe('persona')"
            hide-details="auto"
            data-test="concept-field-persona"
          />
          <v-select
            :model-value="stage"
            :items="stageItems"
            :label="t('app.strategy.concepts.dialog.stage')"
            variant="outlined"
            density="comfortable"
            :menu-props="MENU_PROPS"
            hide-details="auto"
            data-test="concept-field-stage"
            @update:model-value="onStage"
          />
        </div>
        <v-text-field
          v-model="funnelNote"
          :label="t('app.strategy.concepts.dialog.stageNote')"
          variant="outlined"
          density="comfortable"
          :counter="L.concept_funnel_note"
          :error-messages="over(funnelNote, L.concept_funnel_note)"
          class="mt-3"
          hide-details="auto"
          data-test="concept-field-note"
        />
        <v-text-field
          v-model="title"
          :label="t('app.strategy.concepts.dialog.title')"
          variant="outlined"
          density="comfortable"
          :counter="L.concept_title"
          :error-messages="titleErrors"
          class="mt-3"
          hide-details="auto"
          data-test="concept-field-title"
        />

        <v-select
          v-model="chips"
          :items="uspItems"
          :label="t('app.strategy.concepts.dialog.usps')"
          :hint="t('app.strategy.concepts.dialog.uspsHint')"
          persistent-hint
          multiple
          chips
          closable-chips
          variant="outlined"
          density="comfortable"
          :menu-props="MENU_PROPS"
          :error-messages="fe('usps')"
          class="mt-3"
          data-test="concept-field-usps"
          @update:model-value="chipsTouched = true"
        />

        <fieldset
          v-if="personaChanged && !chipsTouched"
          class="concept-dialog__mode"
          data-test="concept-usp-mode"
        >
          <legend class="text-body-2">{{ t('app.strategy.concepts.dialog.uspMode') }}</legend>
          <v-radio-group
            v-model="uspMode"
            hide-details="auto"
            :error-messages="
              submitted && uspMode === null ? [t('app.strategy.validation.required')] : []
            "
          >
            <v-radio
              value="refill"
              :label="t('app.strategy.concepts.dialog.uspModeRefill')"
              data-test="concept-usp-mode-refill"
            />
            <v-radio
              value="keep"
              :label="t('app.strategy.concepts.dialog.uspModeKeep')"
              data-test="concept-usp-mode-keep"
            />
          </v-radio-group>
        </fieldset>

        <v-textarea
          v-for="f in textFields"
          :key="f.key"
          v-model="f.model.value"
          :label="t(`app.strategy.concepts.field.${f.key}`)"
          variant="outlined"
          density="comfortable"
          :rows="f.rows"
          auto-grow
          :counter="f.max"
          :error-messages="fe(f.key, over(f.model.value, f.max))"
          class="mt-3"
          hide-details="auto"
          :data-test="`concept-field-${f.key}`"
        />

        <ListField
          v-model="keyPoints"
          class="mt-3"
          :label="t('app.strategy.concepts.field.key_points')"
          :max="L.concept_key_points"
          :max-length="L.concept_key_point"
          test-id="concept-field-key_points"
          :submitted="submitted"
        />

        <v-textarea
          v-for="f in tailFields"
          :key="f.key"
          v-model="f.model.value"
          :label="t(`app.strategy.concepts.field.${f.key}`)"
          variant="outlined"
          density="comfortable"
          :rows="f.rows"
          auto-grow
          :counter="f.max"
          :error-messages="fe(f.key, over(f.model.value, f.max))"
          class="mt-3"
          hide-details="auto"
          :data-test="`concept-field-${f.key}`"
        />
      </v-card-text>
      <v-alert
        v-if="error"
        class="mx-6 mb-3 strategy-dialog-error"
        type="error"
        variant="tonal"
        density="compact"
        data-test="concept-dialog-error"
      >
        {{ error }}
      </v-alert>
      <v-card-actions class="px-6 pb-4">
        <v-spacer />
        <v-btn variant="text" :disabled="saving" data-test="concept-dialog-cancel" @click="close">
          {{ t('app.strategy.cancel') }}
        </v-btn>
        <v-btn
          color="primary"
          variant="flat"
          :loading="saving"
          data-test="concept-dialog-save"
          @click="submit"
        >
          {{ t('app.strategy.save') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.concept-dialog__row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}
@media (max-width: 599px) {
  .concept-dialog__row {
    grid-template-columns: 1fr;
  }
}
.concept-dialog__mode {
  margin: 12px 0 0;
  padding: 0;
  border: 0;
}
.strategy-dialog-error {
  flex: 0 0 auto;
}
</style>
