<script setup lang="ts">
/**
 * PL-1.1 — the New wave dialog, in two modes.
 *
 *  - Create: name, go-live date, markets, and an optional campaign (this brand's, no wave yet, never
 *    Cancelled). "No campaign" sends `campaign_id: null`.
 *  - Edit (`wave` given): the same fields. The campaign field is editable only while the wave has no link;
 *    once linked it is a read-only name (the server answers 422 `wave.campaign_link_locked` to any change).
 *
 * No design frame exists for this dialog; it is built from the design system's dialog and field styles.
 * Server codes map to strings; anything else is the generic save error.
 */
import { ApiError } from '@catalyst/api-client'
import type { CreateWavePayload, WaveCampaignOption, WaveResource } from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { MAX_MARKETS } from '../constants'
import { wavesApi } from '../api/waves.api'
import MarketPicker from './MarketPicker.vue'

const props = defineProps<{
  modelValue: boolean
  agencyId: string
  brandId: string
  brandName: string
  wave?: WaveResource | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  saved: [wave: WaveResource]
}>()

const { t, te } = useI18n()

const isEdit = computed(() => props.wave !== undefined && props.wave !== null)
const linkLocked = computed(() => props.wave?.relationships.campaign != null)

const name = ref('')
const goLiveOn = ref('')
const markets = ref<string[]>([])
const campaignId = ref<string | null>(null)
const options = ref<WaveCampaignOption[]>([])
const saving = ref(false)
const submitted = ref(false)
const formError = ref<string | null>(null)

const nameError = computed(() =>
  submitted.value && name.value.trim() === '' ? t('app.waves.validation.nameRequired') : null,
)
const goLiveError = computed(() =>
  submitted.value && goLiveOn.value === '' ? t('app.waves.validation.goLiveRequired') : null,
)
const marketsError = computed(() => {
  if (!submitted.value) return null
  if (markets.value.length === 0) return t('app.waves.validation.marketsRequired')
  if (markets.value.length > MAX_MARKETS) return t('app.waves.validation.marketsMax')
  return null
})

const campaignItems = computed(() => [
  { value: null as string | null, title: t('app.waves.dialog.noCampaign') },
  ...options.value.map((o) => {
    const key = `app.campaigns.status.${o.attributes.status}`
    const status = te(key) ? t(key) : o.attributes.status
    return { value: o.id as string | null, title: `${o.attributes.name} · ${status}` }
  }),
])

async function loadOptions(): Promise<void> {
  if (linkLocked.value) return
  try {
    const res = await wavesApi.campaignOptions(props.agencyId, props.brandId)
    options.value = res.data
  } catch {
    // The field still works as "No campaign"; a failed list is not a reason to block the wave.
    options.value = []
  }
}

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    submitted.value = false
    formError.value = null
    saving.value = false
    name.value = props.wave?.attributes.name ?? ''
    goLiveOn.value = props.wave?.attributes.go_live_on ?? ''
    markets.value = [...(props.wave?.attributes.markets ?? [])]
    campaignId.value = props.wave?.relationships.campaign?.data.id ?? null
    void loadOptions()
  },
  { immediate: true },
)

const CODE_KEYS: Record<string, string> = {
  'wave.campaign_not_available': 'app.waves.errors.campaignNotAvailable',
  'wave.campaign_already_linked': 'app.waves.errors.campaignAlreadyLinked',
  'wave.campaign_link_locked': 'app.waves.errors.campaignLinkLocked',
  'wave.brand_archived': 'app.waves.errors.brandArchived',
}

function messageFor(err: unknown): string {
  if (err instanceof ApiError) {
    const key = CODE_KEYS[err.code]
    if (key !== undefined) return t(key)
  }
  return t('app.waves.errors.saveFailed')
}

function close(): void {
  if (saving.value) return
  emit('update:modelValue', false)
}

async function submit(): Promise<void> {
  submitted.value = true
  formError.value = null
  if (nameError.value || goLiveError.value || marketsError.value) return

  saving.value = true
  try {
    let res
    if (isEdit.value && props.wave) {
      const body: Partial<CreateWavePayload> = {}
      if (name.value.trim() !== props.wave.attributes.name) body.name = name.value.trim()
      if (goLiveOn.value !== props.wave.attributes.go_live_on) body.go_live_on = goLiveOn.value
      if (markets.value.join(',') !== props.wave.attributes.markets.join(','))
        body.markets = [...markets.value]
      if (!linkLocked.value && campaignId.value !== null) body.campaign_id = campaignId.value
      res = await wavesApi.update(props.agencyId, props.brandId, props.wave.id, body)
    } else {
      res = await wavesApi.create(props.agencyId, props.brandId, {
        name: name.value.trim(),
        go_live_on: goLiveOn.value,
        markets: [...markets.value],
        campaign_id: campaignId.value,
      })
    }
    emit('saved', res.data)
    emit('update:modelValue', false)
  } catch (err) {
    formError.value = messageFor(err)
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
    data-test="wave-dialog"
    @update:model-value="
      (v: boolean) => {
        if (!saving) emit('update:modelValue', v)
      }
    "
  >
    <v-card>
      <v-card-title class="text-h6 pt-4 px-6">
        {{ isEdit ? t('app.waves.dialog.editTitle') : t('app.waves.dialog.createTitle') }}
      </v-card-title>
      <v-card-subtitle class="px-6" data-test="wave-dialog-brand">{{ brandName }}</v-card-subtitle>
      <v-card-text class="d-flex flex-column ga-4 px-6">
        <v-text-field
          v-model="name"
          :label="t('app.waves.dialog.name')"
          variant="outlined"
          density="comfortable"
          maxlength="120"
          hide-details="auto"
          :error-messages="nameError ? [nameError] : []"
          data-test="wave-name"
        />
        <v-text-field
          v-model="goLiveOn"
          type="date"
          :label="t('app.waves.dialog.goLive')"
          variant="outlined"
          density="comfortable"
          hide-details="auto"
          :error-messages="goLiveError ? [goLiveError] : []"
          data-test="wave-go-live"
        />
        <div>
          <div class="text-body-2 mb-2">{{ t('app.waves.dialog.markets') }}</div>
          <MarketPicker v-model="markets" :error-message="marketsError" />
        </div>

        <v-text-field
          v-if="linkLocked"
          :model-value="wave?.relationships.campaign?.data.attributes.name"
          :label="t('app.waves.dialog.campaign')"
          :hint="t('app.waves.dialog.campaignLocked')"
          persistent-hint
          variant="outlined"
          density="comfortable"
          readonly
          data-test="wave-campaign-locked"
        />
        <v-select
          v-else
          v-model="campaignId"
          :items="campaignItems"
          item-title="title"
          item-value="value"
          :label="t('app.waves.dialog.campaign')"
          :hint="t('app.waves.dialog.campaignHint')"
          persistent-hint
          variant="outlined"
          density="comfortable"
          data-test="wave-campaign"
        />

        <v-alert v-if="formError" type="error" variant="tonal" data-test="wave-form-error">
          {{ formError }}
        </v-alert>
      </v-card-text>
      <v-card-actions class="px-6 pb-4">
        <v-spacer />
        <v-btn variant="text" :disabled="saving" data-test="wave-cancel" @click="close">
          {{ t('app.brands.archive.cancel') }}
        </v-btn>
        <v-btn
          color="primary"
          variant="flat"
          :loading="saving"
          data-test="wave-submit"
          @click="submit"
        >
          {{ isEdit ? t('app.waves.dialog.save') : t('app.waves.dialog.create') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
