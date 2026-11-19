<script setup lang="ts">
/**
 * PL-2.1 — Add persona / Edit persona (a persona belongs to one market and never changes it). No design frame
 * exists for this dialog; it is a scrollable Vuetify dialog (fullscreen on a narrow screen) in the order of the
 * card: code, title, name, pronoun, summary, core job, the four lists, need.
 *
 * Required: title, name and pronoun (default "they"). The code is optional on create (the server numbers it,
 * for example SK3); typed, it is upper-cased and must be 2 to 8 letters or digits and free in the market. The
 * name is invented, not a real person. Edit sends only the fields that changed.
 */
import type {
  PersonaPronoun,
  StrategyLimits,
  WavePersonaPayload,
  WavePersonaResource,
} from '@catalyst/api-client'
import { ApiError, labelForCountryCode } from '@catalyst/api-client'
import { computed, ref, watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDisplay } from 'vuetify'

import { strategyApi, type WaveScope } from '../api/strategy.api'
import { MENU_PROPS } from '../constants'
import { fieldErrorsOf, formErrorFor, isGone, strategyErrorMessage } from '../strategyErrors'
import { revealFirstError } from '../revealError'
import ListField from './ListField.vue'

const props = defineProps<{
  modelValue: boolean
  scope: WaveScope
  limits: StrategyLimits
  market: string
  persona?: WavePersonaResource | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  saved: [persona: WavePersonaResource]
  gone: []
}>()

const { t } = useI18n()
const { xs } = useDisplay()

const session = ref(0)
const isEdit = computed(() => props.persona !== undefined && props.persona !== null)

const code = ref('')
const title = ref('')
const name = ref('')
const pronoun = ref<PersonaPronoun>('they')
const summary = ref('')
const coreJob = ref('')
const need = ref('')
const drivers = ref<string[]>([])
const goals = ref<string[]>([])
const selfTalk = ref<string[]>([])
const barriers = ref<string[]>([])

const saving = ref(false)
const submitted = ref(false)
const formError = ref<string | null>(null)
const serverErrors = ref<Record<string, string>>({})
const gone = ref(false)
/** The market the dialog was opened for: a tab switch while it is open must not move the save. */
const openedMarket = ref('')

const PRONOUNS: PersonaPronoun[] = ['she', 'he', 'they']
const pronounItems = computed(() =>
  PRONOUNS.map((p) => ({ value: p, title: t(`app.strategy.persona.pronounName.${p}`) })),
)

const len = (s: string): number => Array.from(s).length
const CODE_RE = /^[A-Z0-9]+$/

watch(
  () => props.modelValue,
  (open) => {
    session.value++
    if (!open) return
    const a = props.persona?.attributes
    openedMarket.value = a?.market ?? props.market
    code.value = a?.code ?? ''
    title.value = a?.title ?? ''
    name.value = a?.name ?? ''
    pronoun.value = a?.pronoun ?? 'they'
    summary.value = a?.summary ?? ''
    coreJob.value = a?.core_job ?? ''
    need.value = a?.need ?? ''
    drivers.value = [...(a?.drivers ?? [])]
    goals.value = [...(a?.goals ?? [])]
    selfTalk.value = [...(a?.self_talk ?? [])]
    barriers.value = [...(a?.barriers ?? [])]
    saving.value = false
    submitted.value = false
    formError.value = null
    serverErrors.value = {}
    gone.value = false
  },
  { immediate: true },
)

const codeError = computed(() => {
  if (!submitted.value) return null
  const c = code.value.trim().toUpperCase()
  if (c === '') return isEdit.value ? t('app.strategy.persona.validation.codeRequired') : null
  if (
    !CODE_RE.test(c) ||
    len(c) < props.limits.persona_code_min ||
    len(c) > props.limits.persona_code_max
  )
    return t('app.strategy.persona.validation.codeFormat', {
      min: props.limits.persona_code_min,
      max: props.limits.persona_code_max,
    })
  return null
})
function requiredOrLong(v: string, max: number, requiredKey: string | null): string | null {
  if (!submitted.value) return null
  const x = v.trim()
  if (x === '' && requiredKey !== null) return t(requiredKey)
  return len(x) > max ? t('app.strategy.validation.tooLong', { max }) : null
}
const titleError = computed(() =>
  requiredOrLong(
    title.value,
    props.limits.persona_title,
    'app.strategy.persona.validation.titleRequired',
  ),
)
const nameError = computed(() =>
  requiredOrLong(
    name.value,
    props.limits.persona_name,
    'app.strategy.persona.validation.nameRequired',
  ),
)
const summaryError = computed(() =>
  requiredOrLong(summary.value, props.limits.persona_summary, null),
)
const coreJobError = computed(() =>
  requiredOrLong(coreJob.value, props.limits.persona_paragraph, null),
)
const needError = computed(() => requiredOrLong(need.value, props.limits.persona_paragraph, null))
const listsTooLong = computed(() =>
  [drivers.value, goals.value, selfTalk.value, barriers.value].some((l) =>
    l.some((v) => len(v.trim()) > props.limits.list_item),
  ),
)

const marketLabel = computed(() => labelForCountryCode(openedMarket.value))

function close(): void {
  if (saving.value) return
  emit('update:modelValue', false)
  if (gone.value) emit('gone')
}

const clean = (l: string[]): string[] => l.map((v) => v.trim()).filter((v) => v !== '')
const orNull = (v: string): string | null => (v.trim() === '' ? null : v.trim())
const same = (a: string[], b: string[]): boolean => JSON.stringify(a) === JSON.stringify(b)

async function submit(): Promise<void> {
  submitted.value = true
  formError.value = null
  serverErrors.value = {}
  if (
    codeError.value ||
    titleError.value ||
    nameError.value ||
    summaryError.value ||
    coreJobError.value ||
    needError.value ||
    listsTooLong.value
  ) {
    reveal()
    return
  }

  saving.value = true
  try {
    let res
    if (isEdit.value && props.persona) {
      const a = props.persona.attributes
      const body: WavePersonaPayload = {}
      const c = code.value.trim().toUpperCase()
      if (c !== a.code) body.code = c
      if (title.value.trim() !== a.title) body.title = title.value.trim()
      if (name.value.trim() !== a.name) body.name = name.value.trim()
      if (pronoun.value !== a.pronoun) body.pronoun = pronoun.value
      if (orNull(summary.value) !== a.summary) body.summary = orNull(summary.value)
      if (orNull(coreJob.value) !== a.core_job) body.core_job = orNull(coreJob.value)
      if (orNull(need.value) !== a.need) body.need = orNull(need.value)
      if (!same(clean(drivers.value), a.drivers)) body.drivers = clean(drivers.value)
      if (!same(clean(goals.value), a.goals)) body.goals = clean(goals.value)
      if (!same(clean(selfTalk.value), a.self_talk)) body.self_talk = clean(selfTalk.value)
      if (!same(clean(barriers.value), a.barriers)) body.barriers = clean(barriers.value)
      if (Object.keys(body).length === 0) {
        emit('update:modelValue', false)
        return
      }
      res = await strategyApi.updatePersona(props.scope, props.persona.id, body)
    } else {
      const c = code.value.trim().toUpperCase()
      res = await strategyApi.createPersona(props.scope, {
        market: openedMarket.value,
        ...(c === '' ? {} : { code: c }),
        title: title.value.trim(),
        name: name.value.trim(),
        pronoun: pronoun.value,
        summary: orNull(summary.value),
        core_job: orNull(coreJob.value),
        need: orNull(need.value),
        drivers: clean(drivers.value),
        goals: clean(goals.value),
        self_talk: clean(selfTalk.value),
        barriers: clean(barriers.value),
      })
    }
    emit('saved', res.data)
    emit('update:modelValue', false)
  } catch (err) {
    if (isGone(err) && isEdit.value) gone.value = true
    serverErrors.value =
      err instanceof ApiError && err.code === 'persona.code_taken'
        ? { code: strategyErrorMessage(err, t) }
        : fieldErrorsOf(err)
    formError.value = formErrorFor(
      err,
      serverErrors.value,
      ['code', 'title', 'name', 'summary', 'core_job', 'need'],
      t,
    )
    reveal()
  } finally {
    saving.value = false
  }
}

const card = ref<{ $el: HTMLElement } | null>(null)
/** Scroll the first field in error into view once it is drawn (the body scrolls, Save stays put). */
function reveal(): void {
  void nextTick(() => revealFirstError(card.value?.$el))
}

function fe(field: string, local: string | null): string[] {
  const m = local ?? serverErrors.value[field] ?? null
  return m === null ? [] : [m]
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="720"
    persistent
    scrollable
    :fullscreen="xs"
    data-test="persona-dialog"
    @update:model-value="
      (v: boolean) => {
        if (!v) close()
      }
    "
  >
    <v-card ref="card" :key="session">
      <v-card-title class="text-h6 text-wrap text-break pt-4 px-6">
        {{
          isEdit
            ? t('app.strategy.persona.dialog.editTitle')
            : t('app.strategy.persona.dialog.addTitle', { market: marketLabel })
        }}
      </v-card-title>
      <v-card-text class="d-flex flex-column ga-4 px-6">
        <div class="persona-dialog__row">
          <v-text-field
            v-model="code"
            :label="t('app.strategy.persona.dialog.code')"
            :placeholder="isEdit ? '' : t('app.strategy.persona.dialog.codeAuto')"
            :hint="
              t('app.strategy.persona.dialog.codeHint', {
                min: limits.persona_code_min,
                max: limits.persona_code_max,
              })
            "
            persistent-hint
            variant="outlined"
            density="comfortable"
            hide-details="auto"
            :error-messages="fe('code', codeError)"
            data-test="persona-field-code"
          />
          <v-text-field
            v-model="title"
            :label="t('app.strategy.persona.dialog.title')"
            variant="outlined"
            density="comfortable"
            :counter="limits.persona_title"
            hide-details="auto"
            :error-messages="fe('title', titleError)"
            data-test="persona-field-title"
          />
        </div>
        <div class="persona-dialog__row">
          <v-text-field
            v-model="name"
            :label="t('app.strategy.persona.dialog.name')"
            :hint="t('app.strategy.persona.dialog.nameHint')"
            persistent-hint
            variant="outlined"
            density="comfortable"
            :counter="limits.persona_name"
            hide-details="auto"
            :error-messages="fe('name', nameError)"
            data-test="persona-field-name"
          />
          <v-select
            v-model="pronoun"
            :items="pronounItems"
            :label="t('app.strategy.persona.dialog.pronoun')"
            :hint="t('app.strategy.persona.dialog.pronounHint')"
            persistent-hint
            variant="outlined"
            density="comfortable"
            :menu-props="MENU_PROPS"
            hide-details="auto"
            data-test="persona-field-pronoun"
          />
        </div>
        <v-textarea
          v-model="summary"
          :label="t('app.strategy.persona.dialog.summary')"
          variant="outlined"
          density="comfortable"
          rows="2"
          auto-grow
          :counter="limits.persona_summary"
          hide-details="auto"
          :error-messages="fe('summary', summaryError)"
          data-test="persona-field-summary"
        />
        <v-textarea
          v-model="coreJob"
          :label="t('app.strategy.persona.coreJob')"
          variant="outlined"
          density="comfortable"
          rows="2"
          auto-grow
          :counter="limits.persona_paragraph"
          hide-details="auto"
          :error-messages="fe('core_job', coreJobError)"
          data-test="persona-field-core-job"
        />
        <ListField
          v-model="drivers"
          :label="t('app.strategy.persona.drivers')"
          :max="limits.list_items"
          :max-length="limits.list_item"
          :submitted="submitted"
          test-id="persona-list-drivers"
        />
        <ListField
          v-model="goals"
          :label="t('app.strategy.persona.goals')"
          :max="limits.list_items"
          :max-length="limits.list_item"
          :submitted="submitted"
          test-id="persona-list-goals"
        />
        <ListField
          v-model="selfTalk"
          :label="t(`app.strategy.persona.selfTalk.${pronoun}`)"
          :max="limits.list_items"
          :max-length="limits.list_item"
          :submitted="submitted"
          test-id="persona-list-self-talk"
        />
        <v-textarea
          v-model="need"
          :label="t(`app.strategy.persona.need.${pronoun}`)"
          variant="outlined"
          density="comfortable"
          rows="2"
          auto-grow
          :counter="limits.persona_paragraph"
          hide-details="auto"
          :error-messages="fe('need', needError)"
          data-test="persona-field-need"
        />
        <ListField
          v-model="barriers"
          :label="t('app.strategy.persona.barriers')"
          :max="limits.list_items"
          :max-length="limits.list_item"
          :submitted="submitted"
          test-id="persona-list-barriers"
        />
      </v-card-text>
      <v-alert
        v-if="formError"
        class="mx-6 mb-3 strategy-dialog-error"
        type="error"
        variant="tonal"
        density="compact"
        data-test="persona-dialog-error"
      >
        {{ formError }}
      </v-alert>
      <v-card-actions class="px-6 pb-4">
        <v-spacer />
        <v-btn variant="text" :disabled="saving" data-test="persona-dialog-cancel" @click="close">
          {{ gone ? t('app.strategy.close') : t('app.strategy.cancel') }}
        </v-btn>
        <v-btn
          v-if="!gone"
          color="primary"
          variant="flat"
          :loading="saving"
          data-test="persona-dialog-save"
          @click="submit"
        >
          {{ t('app.strategy.save') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
/* The dialog is `scrollable`: only the body scrolls. The alert sits between that body and the actions and keeps its
   full height, so the message is never cut off. */
.strategy-dialog-error {
  flex: 0 0 auto;
}

.persona-dialog__row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}
@media (max-width: 599px) {
  .persona-dialog__row {
    grid-template-columns: 1fr;
  }
}
</style>
