<script setup lang="ts">
/**
 * PL-3.1 (P1) — the Creators step of a wave with no campaign: it says why creators need one and offers the two ways out.
 * LINK: a select of this brand's for-a-wave campaigns that no wave uses (the same list the New wave dialog offers). CREATE:
 * a name, and the server makes the draft for-a-wave campaign and links it in one transaction (the PL-1.2 rules, not a second
 * set). Admin and manager only; anyone else reads the explanation.
 */
import type { WaveCampaignOption, WaveResource } from '@catalyst/api-client'
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { wavesApi } from '@/modules/waves/api/waves.api'
import { MENU_PROPS } from '../constants'
import { fieldErrorsOf, strategyErrorMessage } from '../strategyErrors'
import type { WaveScope } from '../api/strategy.api'

const props = defineProps<{ scope: WaveScope; canLink: boolean }>()
const emit = defineEmits<{ linked: [wave: WaveResource] }>()

const { t } = useI18n()
const options = ref<WaveCampaignOption[]>([])
const optionsLoading = ref(true)
const optionsFailed = ref(false)
const choice = ref<string | null>(null)
const name = ref('')
const busy = ref<'link' | 'create' | null>(null)
const error = ref<string | null>(null)
const nameError = ref<string | null>(null)

async function loadOptions(): Promise<void> {
  optionsLoading.value = true
  optionsFailed.value = false
  try {
    options.value = (await wavesApi.campaignOptions(props.scope.agencyId, props.scope.brandId)).data
  } catch {
    optionsFailed.value = true
  } finally {
    optionsLoading.value = false
  }
}
onMounted(() => {
  if (props.canLink) void loadOptions()
  else optionsLoading.value = false
})

async function link(): Promise<void> {
  if (choice.value === null || busy.value !== null) return
  busy.value = 'link'
  error.value = null
  try {
    const res = await wavesApi.update(
      props.scope.agencyId,
      props.scope.brandId,
      props.scope.waveId,
      {
        campaign_id: choice.value,
      },
    )
    emit('linked', res.data)
  } catch (err) {
    error.value = strategyErrorMessage(err, t)
    void loadOptions()
  } finally {
    busy.value = null
  }
}

async function create(): Promise<void> {
  const trimmed = name.value.trim()
  nameError.value = null
  if (trimmed === '') {
    nameError.value = t('app.strategy.validation.required')
    return
  }
  if (busy.value !== null) return
  busy.value = 'create'
  error.value = null
  try {
    const res = await wavesApi.createCampaign(
      props.scope.agencyId,
      props.scope.brandId,
      props.scope.waveId,
      trimmed,
    )
    emit('linked', res.data)
  } catch (err) {
    const fields = fieldErrorsOf(err)
    if (fields['name']) nameError.value = fields['name']
    else error.value = strategyErrorMessage(err, t)
  } finally {
    busy.value = null
  }
}
</script>

<template>
  <section class="campaign-panel agency-card" data-test="creators-no-campaign">
    <h3 class="campaign-panel__title">{{ t('app.strategy.creators.noCampaign.title') }}</h3>
    <p class="campaign-panel__body">{{ t('app.strategy.creators.noCampaign.body') }}</p>
    <p v-if="!canLink" class="campaign-panel__body" data-test="creators-no-campaign-readonly">
      {{ t('app.strategy.creators.noCampaign.askAdmin') }}
    </p>
    <v-alert
      v-if="error"
      type="error"
      variant="tonal"
      density="compact"
      data-test="creators-link-error"
    >
      {{ error }}
    </v-alert>
    <div v-if="canLink" class="campaign-panel__ways">
      <div class="campaign-panel__way">
        <h4>{{ t('app.strategy.creators.noCampaign.linkTitle') }}</h4>
        <v-skeleton-loader v-if="optionsLoading" type="text" />
        <p v-else-if="optionsFailed" class="campaign-panel__hint">
          {{ t('app.strategy.errors.loadFailed') }}
        </p>
        <p
          v-else-if="options.length === 0"
          class="campaign-panel__hint"
          data-test="creators-link-none"
        >
          {{ t('app.strategy.creators.noCampaign.linkNone') }}
        </p>
        <template v-else>
          <v-select
            v-model="choice"
            :items="options.map((o) => ({ value: o.id, title: o.attributes.name }))"
            :label="t('app.strategy.creators.noCampaign.linkLabel')"
            variant="outlined"
            density="comfortable"
            hide-details
            :menu-props="MENU_PROPS"
            data-test="creators-link-select"
          />
          <v-btn
            color="primary"
            variant="flat"
            :disabled="choice === null || busy !== null"
            :loading="busy === 'link'"
            data-test="creators-link-submit"
            @click="link"
          >
            {{ t('app.strategy.creators.noCampaign.link') }}
          </v-btn>
        </template>
      </div>
      <div class="campaign-panel__way">
        <h4>{{ t('app.strategy.creators.noCampaign.createTitle') }}</h4>
        <v-text-field
          v-model="name"
          :label="t('app.strategy.creators.noCampaign.createLabel')"
          variant="outlined"
          density="comfortable"
          maxlength="160"
          :error-messages="nameError ? [nameError] : []"
          hide-details="auto"
          data-test="creators-create-name"
          @keydown.enter.prevent="create"
        />
        <v-btn
          variant="outlined"
          prepend-icon="mdi-plus"
          :disabled="busy !== null"
          :loading="busy === 'create'"
          data-test="creators-create-submit"
          @click="create"
        >
          {{ t('app.strategy.creators.noCampaign.create') }}
        </v-btn>
      </div>
    </div>
  </section>
</template>

<style scoped>
.campaign-panel {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 24px;
}
.campaign-panel__title {
  margin: 0;
  font-size: 18px;
  font-weight: 600;
}
.campaign-panel__body {
  margin: 0;
  max-width: 70ch;
  font-size: 14px;
  color: rgb(var(--v-theme-on-surface-variant));
}
.campaign-panel__ways {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 24px;
}
.campaign-panel__way {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 12px;
}
.campaign-panel__way > :deep(.v-input),
.campaign-panel__way > :deep(.v-skeleton-loader) {
  width: 100%;
}
.campaign-panel__way h4 {
  margin: 0;
  font-size: 14px;
  font-weight: 600;
}
.campaign-panel__hint {
  margin: 0;
  font-size: 13px;
  color: rgb(var(--v-theme-on-surface-variant));
}
</style>
