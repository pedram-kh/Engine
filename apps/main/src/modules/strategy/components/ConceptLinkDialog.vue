<script setup lang="ts">
/**
 * PL-2.3 — add or edit one reference link: a typed title and an https address. The address is checked here only so the
 * message can appear before a request (https only, no credentials, a real host); the server repeats the check and never
 * fetches the page. Nothing is shown from the address except its domain, which the server derives.
 */
import type { ConceptLink, StrategyLimits, WaveConceptResource } from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { strategyApi, type WaveScope } from '../api/strategy.api'
import { linkProblem } from '../conceptsLogic'
import { fieldErrorsOf, strategyErrorMessage } from '../strategyErrors'

const props = defineProps<{
  modelValue: boolean
  scope: WaveScope
  concept: WaveConceptResource
  /** The link being edited; null adds a new one. */
  link: ConceptLink | null
  limits: StrategyLimits
}>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()

const { t } = useI18n()
const session = ref(0)
const title = ref('')
const url = ref('')
const touched = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)

watch(
  () => props.modelValue,
  (open) => {
    session.value++
    if (!open) return
    title.value = props.link?.title ?? ''
    url.value = props.link?.url ?? ''
    touched.value = false
    saving.value = false
    error.value = null
  },
  { immediate: true },
)

const titleTooLong = computed(() => Array.from(title.value.trim()).length > props.limits.link_title)
const titleMissing = computed(() => title.value.trim() === '')
const urlProblem = computed(() => linkProblem(url.value))
const urlMessage = computed(() => {
  if (!touched.value || urlProblem.value === null) return []
  return [t(`app.strategy.concepts.link.problem.${urlProblem.value}`)]
})
const invalid = computed(
  () => titleMissing.value || titleTooLong.value || urlProblem.value !== null,
)

function close(): void {
  if (!saving.value) emit('update:modelValue', false)
}

async function submit(): Promise<void> {
  touched.value = true
  if (invalid.value || saving.value) return
  saving.value = true
  error.value = null
  try {
    const body = { title: title.value.trim(), url: url.value.trim() }
    if (props.link) await strategyApi.updateLink(props.scope, props.concept.id, props.link.id, body)
    else await strategyApi.addLink(props.scope, props.concept.id, body)
    emit('saved')
    emit('update:modelValue', false)
  } catch (err) {
    const fields = fieldErrorsOf(err)
    error.value = fields['url'] ?? fields['title'] ?? strategyErrorMessage(err, t)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="480"
    persistent
    scrollable
    data-test="concept-link-dialog"
    @update:model-value="
      (v: boolean) => {
        if (!v) close()
      }
    "
  >
    <v-card :key="session">
      <v-card-title class="text-h6 text-wrap text-break pt-4 px-6">
        {{
          link
            ? t('app.strategy.concepts.link.editTitle')
            : t('app.strategy.concepts.link.addTitle')
        }}
      </v-card-title>
      <v-card-text class="px-6">
        <v-text-field
          v-model="title"
          :label="t('app.strategy.concepts.link.titleLabel')"
          variant="outlined"
          density="comfortable"
          :counter="limits.link_title"
          :error-messages="
            touched && titleMissing
              ? [t('app.strategy.validation.required')]
              : titleTooLong
                ? [t('app.strategy.validation.tooLong', { max: limits.link_title })]
                : []
          "
          class="mb-3"
          hide-details="auto"
          data-test="concept-link-title"
        />
        <v-text-field
          v-model="url"
          :label="t('app.strategy.concepts.link.urlLabel')"
          :placeholder="t('app.strategy.concepts.link.urlPlaceholder')"
          variant="outlined"
          density="comfortable"
          inputmode="url"
          autocomplete="off"
          :error-messages="urlMessage"
          hide-details="auto"
          data-test="concept-link-url"
          @blur="touched = true"
          @keydown.enter.prevent="submit"
        />
      </v-card-text>
      <v-alert
        v-if="error"
        class="mx-6 mb-3 strategy-dialog-error"
        type="error"
        variant="tonal"
        density="compact"
        data-test="concept-link-error"
      >
        {{ error }}
      </v-alert>
      <v-card-actions class="px-6 pb-4">
        <v-spacer />
        <v-btn variant="text" :disabled="saving" data-test="concept-link-cancel" @click="close">
          {{ t('app.strategy.cancel') }}
        </v-btn>
        <v-btn
          color="primary"
          variant="flat"
          :loading="saving"
          data-test="concept-link-save"
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
</style>
