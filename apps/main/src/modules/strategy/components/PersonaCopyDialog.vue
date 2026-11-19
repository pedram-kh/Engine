<script setup lang="ts">
/**
 * PL-2.1 — Copy from another market (S4). Choose the source market (the wave's other markets that have personas),
 * tick the personas (all ticked at first) and read the live line "Copying 3 → Slovakia will have 6 of 8". Over the
 * maximum the Copy button is off and the line says why; the server refuses it too (all or nothing). Angles come
 * with the personas, and the copies get new codes. No design frame exists for this dialog.
 */
import type { StrategyLimits, WavePersonaResource } from '@catalyst/api-client'
import { labelForCountryCode } from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { strategyApi, type WaveScope } from '../api/strategy.api'
import { MENU_PROPS } from '../constants'
import { strategyErrorMessage } from '../strategyErrors'

const props = defineProps<{
  modelValue: boolean
  scope: WaveScope
  limits: StrategyLimits
  /** The market that receives the copies. */
  target: string
  personas: WavePersonaResource[]
  markets: string[]
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  copied: []
}>()

const { t } = useI18n()
const session = ref(0)
const source = ref<string | null>(null)
const ticked = ref<string[]>([])
const saving = ref(false)
const error = ref<string | null>(null)

const sources = computed(() =>
  props.markets
    .filter((m) => m !== props.target && props.personas.some((p) => p.attributes.market === m))
    .map((m) => ({ value: m, title: labelForCountryCode(m) })),
)
const sourcePersonas = computed(() =>
  props.personas.filter((p) => p.attributes.market === source.value),
)
const targetCount = computed(
  () => props.personas.filter((p) => p.attributes.market === props.target).length,
)
const total = computed(() => targetCount.value + ticked.value.length)
const over = computed(() => total.value > props.limits.persona_max)
const canCopy = computed(() => ticked.value.length > 0 && !over.value && !saving.value)

function pickSource(market: string | null): void {
  source.value = market
  ticked.value = props.personas.filter((p) => p.attributes.market === market).map((p) => p.id)
  error.value = null
}

watch(
  () => props.modelValue,
  (open) => {
    session.value++
    if (!open) return
    saving.value = false
    error.value = null
    pickSource(sources.value[0]?.value ?? null)
  },
  { immediate: true },
)

function close(): void {
  if (!saving.value) emit('update:modelValue', false)
}

async function submit(): Promise<void> {
  if (!canCopy.value || source.value === null) return
  saving.value = true
  error.value = null
  try {
    const all = ticked.value.length === sourcePersonas.value.length
    await strategyApi.copyPersonas(props.scope, {
      from_market: source.value,
      to_market: props.target,
      ...(all ? {} : { persona_ids: [...ticked.value] }),
    })
    emit('copied')
    emit('update:modelValue', false)
  } catch (err) {
    error.value = strategyErrorMessage(err, t)
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
    data-test="copy-dialog"
    @update:model-value="
      (v: boolean) => {
        if (!v) close()
      }
    "
  >
    <v-card :key="session">
      <v-card-title class="text-h6 text-wrap text-break pt-4 px-6">
        {{ t('app.strategy.copy.title', { market: labelForCountryCode(target) }) }}
      </v-card-title>
      <v-card-text class="px-6">
        <v-select
          :model-value="source"
          :items="sources"
          :label="t('app.strategy.copy.source')"
          variant="outlined"
          density="comfortable"
          :menu-props="MENU_PROPS"
          hide-details
          data-test="copy-source"
          @update:model-value="pickSource"
        />
        <div class="mt-3" data-test="copy-list">
          <v-checkbox
            v-for="p in sourcePersonas"
            :key="p.id"
            v-model="ticked"
            :value="p.id"
            density="compact"
            hide-details
            data-test="copy-item"
          >
            <template #label>
              <span class="copy-label"
                >{{ p.attributes.code }} · {{ p.attributes.title }} · {{ p.attributes.name }}</span
              >
            </template>
          </v-checkbox>
        </div>
        <p
          class="mt-3 mb-0 text-body-2"
          :class="over ? 'text-error' : 'text-medium-emphasis'"
          role="status"
          data-test="copy-line"
        >
          {{
            over
              ? t('app.strategy.copy.over', {
                  count: ticked.length,
                  market: labelForCountryCode(target),
                  total,
                  max: limits.persona_max,
                })
              : t('app.strategy.copy.line', {
                  count: ticked.length,
                  market: labelForCountryCode(target),
                  total,
                  max: limits.persona_max,
                })
          }}
        </p>
        <v-alert
          v-if="error"
          type="error"
          variant="tonal"
          density="compact"
          class="mt-3"
          data-test="copy-error"
        >
          {{ error }}
        </v-alert>
      </v-card-text>
      <v-card-actions class="px-6 pb-4">
        <v-spacer />
        <v-btn variant="text" :disabled="saving" data-test="copy-cancel" @click="close">
          {{ t('app.strategy.cancel') }}
        </v-btn>
        <v-btn
          color="primary"
          variant="flat"
          :loading="saving"
          :disabled="!canCopy"
          data-test="copy-submit"
          @click="submit"
        >
          {{ t('app.strategy.copy.submit') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.copy-label {
  overflow-wrap: anywhere;
  white-space: normal;
}
</style>
