<script setup lang="ts">
/**
 * PL-3.1 — record the client's decision on a creator (admin and manager), the pattern of the concept decision dialog.
 * Approved: the client's name (required) and an optional note. Suggest another: the name and a comment (both required). The
 * client is a third party who does not use the product: what is typed here is stored as written, never put in an audit row,
 * and drawn as plain text. A decision is never undone; a later one is the current one and the earlier ones stay in the
 * history.
 */
import type { CreatorDecisionStatus, StrategyLimits, WaveCreatorSlot } from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { creatorLabel } from '@/core/creators/creatorLabel'
import { strategyApi, type WaveScope } from '../api/strategy.api'
import { fieldErrorsOf, strategyErrorMessage } from '../strategyErrors'

const props = defineProps<{
  modelValue: boolean
  scope: WaveScope
  row: WaveCreatorSlot | null
  limits: StrategyLimits
}>()
const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  saved: [meta: { moved: boolean; notMoved: string | null }]
}>()

const { t } = useI18n()
const session = ref(0)
const status = ref<CreatorDecisionStatus>('approved')
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
  { value: 'approved', color: 'success', test: 'creator-decision-approved' },
  { value: 'suggest_another', color: 'warning', test: 'creator-decision-suggest' },
] as const

const len = (v: string): number => Array.from(v.trim()).length
const subject = computed(() =>
  props.row === null ? '' : creatorLabel(props.row.creator, t, props.row.slot_code),
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
    clientName.value.trim() === '' ||
    len(clientName.value) > props.limits.client_name ||
    (status.value === 'approved'
      ? len(note.value) > props.limits.decision_note
      : comment.value.trim() === '' || len(comment.value) > props.limits.decision_comment),
)
const why = computed<string>(() => {
  const noName = clientName.value.trim() === ''
  const noComment = status.value === 'suggest_another' && comment.value.trim() === ''
  if (noName && noComment) return t('app.strategy.creators.decision.why.nameAndComment')
  if (noName) return t('app.strategy.creators.decision.why.name')
  if (noComment) return t('app.strategy.creators.decision.why.comment')
  return ''
})

function close(): void {
  if (!saving.value) emit('update:modelValue', false)
}

async function submit(): Promise<void> {
  submitted.value = true
  error.value = null
  fieldErrors.value = {}
  if (invalid.value || saving.value || props.row === null) return
  saving.value = true
  try {
    const res = await strategyApi.decideCreator(props.scope, props.row.id, {
      status: status.value,
      client_name: clientName.value.trim(),
      ...(status.value === 'approved'
        ? { note: note.value.trim() === '' ? null : note.value.trim() }
        : { comment: comment.value.trim() }),
    })
    emit('saved', { moved: res.meta.card_moved, notMoved: res.meta.card_not_moved })
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
    data-test="creator-decision-dialog"
    @update:model-value="
      (v: boolean) => {
        if (!v) close()
      }
    "
  >
    <v-card :key="session">
      <v-card-title class="text-h6 text-wrap text-break pt-4 px-6">
        {{ t('app.strategy.creators.decision.title') }}
      </v-card-title>
      <v-card-text class="px-6">
        <p class="text-body-2 mb-3 text-break" data-test="creator-decision-subject">
          {{ row?.slot_code }} · {{ subject }}
        </p>
        <div
          class="decision-choice mb-4"
          role="radiogroup"
          :aria-label="t('app.strategy.creators.decision.choice')"
          data-test="creator-decision-status"
        >
          <v-btn
            v-for="c in CHOICES"
            :key="c.value"
            role="radio"
            :aria-checked="status === c.value"
            :color="c.color"
            :variant="status === c.value ? 'flat' : 'outlined'"
            :prepend-icon="status === c.value ? 'mdi-check-circle' : 'mdi-circle-outline'"
            class="decision-choice__btn"
            :data-test="c.test"
            :data-selected="status === c.value ? 'true' : 'false'"
            @click="status = c.value"
          >
            {{ t(`app.strategy.creators.decision.choices.${c.value}`) }}
          </v-btn>
        </div>
        <v-text-field
          v-model="clientName"
          :label="t('app.strategy.creators.decision.clientName')"
          variant="outlined"
          density="comfortable"
          :counter="limits.client_name"
          :error-messages="nameError"
          hide-details="auto"
          data-test="creator-decision-name"
        />
        <v-textarea
          v-if="status === 'approved'"
          v-model="note"
          :label="t('app.strategy.creators.decision.note')"
          variant="outlined"
          density="comfortable"
          rows="2"
          auto-grow
          :counter="limits.decision_note"
          :error-messages="noteError"
          class="mt-3"
          hide-details="auto"
          data-test="creator-decision-note"
        />
        <v-textarea
          v-else
          v-model="comment"
          :label="t('app.strategy.creators.decision.comment')"
          variant="outlined"
          density="comfortable"
          rows="3"
          auto-grow
          :counter="limits.decision_comment"
          :error-messages="commentError"
          class="mt-3"
          hide-details="auto"
          data-test="creator-decision-comment"
        />
        <p class="text-caption text-medium-emphasis mt-3 mb-0">
          {{ t('app.strategy.creators.decision.replaces') }}
        </p>
      </v-card-text>
      <p
        v-if="why !== '' && !error"
        class="decision-why mx-6 mb-3"
        role="status"
        data-test="creator-decision-why"
      >
        <v-icon icon="mdi-information-outline" size="14" aria-hidden="true" />
        {{ why }}
      </p>
      <v-alert
        v-if="error"
        class="mx-6 mb-3"
        type="error"
        variant="tonal"
        density="compact"
        data-test="creator-decision-error"
      >
        {{ error }}
      </v-alert>
      <v-card-actions class="px-6 pb-4">
        <v-spacer />
        <v-btn variant="text" :disabled="saving" data-test="creator-decision-cancel" @click="close">
          {{ t('app.strategy.cancel') }}
        </v-btn>
        <v-btn
          color="primary"
          variant="flat"
          :loading="saving"
          data-test="creator-decision-save"
          @click="submit"
        >
          {{ t('app.strategy.save') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
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
.decision-why {
  display: flex;
  align-items: flex-start;
  gap: 6px;
  font-size: 12px;
  line-height: 16px;
  color: rgb(var(--v-theme-error));
}
</style>
