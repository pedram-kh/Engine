<script setup lang="ts">
/**
 * PL-2.3 — record the client's decision on a concept (admin and manager). Approved: the client's name (required) and an
 * optional note. Changes requested: the name and a comment (both required). The client is a third party who does not use
 * the product: what is typed here is stored as written, never put in an audit row, and drawn as plain text. A decision is
 * never undone; a later one replaces the status and the earlier ones stay in the record.
 *
 * Opened from a card it is about that card; opened from the market banner it first asks which concept.
 */
import type { StrategyLimits, WaveConceptResource } from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { strategyApi, type WaveScope } from '../api/strategy.api'
import { MENU_PROPS } from '../constants'
import { fieldErrorsOf, strategyErrorMessage } from '../strategyErrors'

const props = defineProps<{
  modelValue: boolean
  scope: WaveScope
  /** The concept the card menu was opened on; null when opened from the banner. */
  concept: WaveConceptResource | null
  /** The concepts of the market, for the banner's picker. */
  concepts: WaveConceptResource[]
  limits: StrategyLimits
}>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()
const session = ref(0)
const conceptId = ref<string | null>(null)
const status = ref<'approved' | 'changes_requested'>('approved')
const clientName = ref('')
const note = ref('')
const comment = ref('')
const submitted = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const fieldErrors = ref<Record<string, string>>({})

watch(
  () => props.modelValue,
  (open) => {
    session.value++
    if (!open) return
    conceptId.value =
      props.concept?.id ??
      props.concepts.find((c) => c.attributes.status === 'awaiting')?.id ??
      props.concepts[0]?.id ??
      null
    status.value = 'approved'
    clientName.value = ''
    note.value = ''
    comment.value = ''
    submitted.value = false
    saving.value = false
    error.value = null
    fieldErrors.value = {}
  },
  { immediate: true },
)

const CHOICES = [
  {
    value: 'approved',
    color: 'success',
    icon: 'mdi-circle-outline',
    test: 'concept-decision-approved',
  },
  {
    value: 'changes_requested',
    color: 'error',
    icon: 'mdi-circle-outline',
    test: 'concept-decision-changes',
  },
] as const

const len = (v: string): number => Array.from(v.trim()).length
const items = computed(() =>
  props.concepts.map((c) => ({
    value: c.id,
    title: `${c.attributes.number} · ${c.attributes.title}`,
  })),
)
const nameError = computed(() => {
  if (fieldErrors.value['client_name']) return [fieldErrors.value['client_name']]
  if (submitted.value && clientName.value.trim() === '')
    return [t('app.strategy.validation.required')]
  if (len(clientName.value) > props.limits.client_name)
    return [t('app.strategy.validation.tooLong', { max: props.limits.client_name })]
  return []
})
const commentError = computed(() => {
  if (fieldErrors.value['comment']) return [fieldErrors.value['comment']]
  if (submitted.value && comment.value.trim() === '') return [t('app.strategy.validation.required')]
  if (len(comment.value) > props.limits.decision_comment)
    return [t('app.strategy.validation.tooLong', { max: props.limits.decision_comment })]
  return []
})
const noteError = computed(() =>
  fieldErrors.value['note']
    ? [fieldErrors.value['note']]
    : len(note.value) > props.limits.decision_note
      ? [t('app.strategy.validation.tooLong', { max: props.limits.decision_note })]
      : [],
)
const invalid = computed(
  () =>
    conceptId.value === null ||
    clientName.value.trim() === '' ||
    len(clientName.value) > props.limits.client_name ||
    (status.value === 'approved'
      ? len(note.value) > props.limits.decision_note
      : comment.value.trim() === '' || len(comment.value) > props.limits.decision_comment),
)

/**
 * Why Save cannot proceed, in words and in the error colour (as the matrix hint does): the first thing missing, by status.
 * Empty when Save would go ahead.
 */
const why = computed<string>(() => {
  if (conceptId.value === null) return t('app.strategy.concepts.decision.why.concept')
  const noName = clientName.value.trim() === ''
  const noComment = status.value === 'changes_requested' && comment.value.trim() === ''
  if (noName && noComment) return t('app.strategy.concepts.decision.why.nameAndComment')
  if (noName) return t('app.strategy.concepts.decision.why.name')
  if (noComment) return t('app.strategy.concepts.decision.why.comment')
  return ''
})

function close(): void {
  if (!saving.value) emit('update:modelValue', false)
}

async function submit(): Promise<void> {
  submitted.value = true
  error.value = null
  fieldErrors.value = {}
  if (invalid.value || saving.value || conceptId.value === null) return
  saving.value = true
  try {
    await strategyApi.decideConcept(props.scope, conceptId.value, {
      status: status.value,
      client_name: clientName.value.trim(),
      ...(status.value === 'approved'
        ? { note: note.value.trim() === '' ? null : note.value.trim() }
        : { comment: comment.value.trim() }),
    })
    emit('saved')
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
    max-width="520"
    persistent
    scrollable
    data-test="concept-decision-dialog"
    @update:model-value="
      (v: boolean) => {
        if (!v) close()
      }
    "
  >
    <v-card :key="session">
      <v-card-title class="text-h6 text-wrap text-break pt-4 px-6">
        {{ t('app.strategy.concepts.decision.title') }}
      </v-card-title>
      <v-card-text class="px-6">
        <v-select
          v-if="concept === null"
          v-model="conceptId"
          :items="items"
          :label="t('app.strategy.concepts.decision.concept')"
          variant="outlined"
          density="comfortable"
          :menu-props="MENU_PROPS"
          class="mb-3"
          hide-details="auto"
          data-test="concept-decision-concept"
        />
        <p v-else class="text-body-2 mb-3 text-break" data-test="concept-decision-subject">
          {{ concept.attributes.number }} · {{ concept.attributes.title }}
        </p>
        <div
          class="decision-choice mb-4"
          role="radiogroup"
          :aria-label="t('app.strategy.concepts.decision.choice')"
          data-test="concept-decision-status"
        >
          <v-btn
            v-for="c in CHOICES"
            :key="c.value"
            role="radio"
            :aria-checked="status === c.value"
            :color="c.color"
            :variant="status === c.value ? 'flat' : 'outlined'"
            :prepend-icon="status === c.value ? 'mdi-check-circle' : c.icon"
            class="decision-choice__btn"
            :class="{ 'decision-choice__btn--on': status === c.value }"
            :data-test="c.test"
            :data-selected="status === c.value ? 'true' : 'false'"
            @click="status = c.value"
          >
            {{ t(`app.strategy.concepts.status.${c.value}`) }}
          </v-btn>
        </div>
        <v-text-field
          v-model="clientName"
          :label="t('app.strategy.concepts.decision.clientName')"
          variant="outlined"
          density="comfortable"
          :counter="limits.client_name"
          :error-messages="nameError"
          hide-details="auto"
          data-test="concept-decision-name"
        />
        <v-textarea
          v-if="status === 'approved'"
          v-model="note"
          :label="t('app.strategy.concepts.decision.note')"
          variant="outlined"
          density="comfortable"
          rows="2"
          auto-grow
          :counter="limits.decision_note"
          :error-messages="noteError"
          class="mt-3"
          hide-details="auto"
          data-test="concept-decision-note-field"
        />
        <v-textarea
          v-else
          v-model="comment"
          :label="t('app.strategy.concepts.decision.comment')"
          variant="outlined"
          density="comfortable"
          rows="3"
          auto-grow
          :counter="limits.decision_comment"
          :error-messages="commentError"
          class="mt-3"
          hide-details="auto"
          data-test="concept-decision-comment-field"
        />
        <p class="text-caption text-medium-emphasis mt-3 mb-0">
          {{ t('app.strategy.concepts.decision.replaces') }}
        </p>
      </v-card-text>
      <p
        v-if="why !== '' && !error"
        class="decision-why mx-6 mb-3"
        role="status"
        data-test="concept-decision-why"
      >
        <v-icon icon="mdi-information-outline" size="14" aria-hidden="true" />
        {{ why }}
      </p>
      <v-alert
        v-if="error"
        class="mx-6 mb-3 strategy-dialog-error"
        type="error"
        variant="tonal"
        density="compact"
        data-test="concept-decision-error"
      >
        {{ error }}
      </v-alert>
      <v-card-actions class="px-6 pb-4">
        <v-spacer />
        <v-btn variant="text" :disabled="saving" data-test="concept-decision-cancel" @click="close">
          {{ t('app.strategy.cancel') }}
        </v-btn>
        <v-btn
          color="primary"
          variant="flat"
          :loading="saving"
          data-test="concept-decision-save"
          @click="submit"
        >
          {{ t('app.strategy.save') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.strategy-dialog-error {
  flex: 0 0 auto;
}
.decision-choice {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}
.decision-choice__btn {
  min-width: 0;
  height: auto;
  min-height: 40px;
  padding: 6px 10px;
  white-space: normal;
  text-transform: none;
  letter-spacing: normal;
  font-weight: 500;
}
.decision-choice__btn :deep(.v-btn__content) {
  white-space: normal;
  text-align: center;
  min-width: 0;
}
.decision-choice__btn--on {
  font-weight: 700;
}
.decision-why {
  display: flex;
  align-items: flex-start;
  gap: 6px;
  margin-top: 0;
  font-size: 12px;
  line-height: 16px;
  color: rgb(var(--v-theme-error));
}
.decision-why .v-icon {
  margin-top: 1px;
}
</style>
