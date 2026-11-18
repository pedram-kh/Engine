<script setup lang="ts">
/**
 * PL-1.2 (P4) — the Wave card on a campaign's Overview: which wave this campaign belongs to, with Link, Change and
 * Unlink for an admin or manager while the link is not locked.
 *
 * Drawn only with `features.waves` on (the page mounts it through a one-item list, so with the flag off no node and no
 * comment node is left). No design frame exists for it; it is built from the agency card, field and button styles.
 *
 * The lock reads where cards are now (a card in Selected or any later working column). The server decides; the card
 * only explains a disabled control, and a 422 `wave.campaign_link_locked` that races a move is shown the same way.
 */
import { ApiError, formatDateTime } from '@catalyst/api-client'
import type { CampaignResource, CampaignWaveOption } from '@catalyst/api-client'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { campaignsApi } from '../api/campaigns.api'

const props = defineProps<{
  agencyId: string
  campaign: CampaignResource
  canManage: boolean
}>()

const emit = defineEmits<{ changed: [] }>()

const { t, locale } = useI18n()

const wave = computed(() => props.campaign.relationships.wave?.data ?? null)
const locked = computed(() => wave.value?.attributes.link_locked === true)

const picking = ref(false)
const options = ref<CampaignWaveOption[]>([])
const choice = ref<string | null>(null)
const busy = ref(false)
const error = ref<string | null>(null)

const CODE_KEYS: Record<string, string> = {
  'wave.campaign_not_available': 'app.waves.errors.waveNotAvailable',
  'wave.campaign_already_linked': 'app.waves.errors.campaignAlreadyLinked',
  'wave.campaign_link_locked': 'app.campaigns.waveCard.locked',
  'wave.brand_archived': 'app.waves.errors.brandArchived',
}

function messageFor(err: unknown): string {
  if (err instanceof ApiError) {
    const key = CODE_KEYS[err.code]
    if (key !== undefined) return t(key)
  }
  return t('app.campaigns.waveCard.failed')
}

async function openPicker(): Promise<void> {
  error.value = null
  choice.value = null
  picking.value = true
  try {
    options.value = (await campaignsApi.waveOptions(props.agencyId, props.campaign.id)).data
  } catch (err) {
    options.value = []
    error.value = messageFor(err)
  }
}

async function confirm(): Promise<void> {
  if (choice.value === null) return
  busy.value = true
  error.value = null
  try {
    await campaignsApi.linkWave(props.agencyId, props.campaign.id, choice.value)
    picking.value = false
    emit('changed')
  } catch (err) {
    error.value = messageFor(err)
    // A refused change may mean the link just locked: re-read so the card shows the true state.
    if (err instanceof ApiError && err.code === 'wave.campaign_link_locked') {
      picking.value = false
      emit('changed')
    }
  } finally {
    busy.value = false
  }
}

async function unlink(): Promise<void> {
  busy.value = true
  error.value = null
  try {
    await campaignsApi.unlinkWave(props.agencyId, props.campaign.id)
    emit('changed')
  } catch (err) {
    error.value = messageFor(err)
    if (err instanceof ApiError && err.code === 'wave.campaign_link_locked') emit('changed')
  } finally {
    busy.value = false
  }
}

const items = computed(() =>
  options.value.map((o) => ({
    value: o.id,
    title: `${o.attributes.name} · ${t('app.waves.table.goLive', {
      date: formatDateTime(`${o.attributes.go_live_on}T00:00:00`, locale.value, {
        dateStyle: 'medium',
      }),
    })}`,
  })),
)
</script>

<template>
  <section class="agency-card" data-test="campaign-wave-card">
    <h2 class="agency-card__title">{{ t('app.waves.detail.title') }}</h2>

    <div v-if="wave" class="agency-field" data-test="campaign-wave-current">
      <router-link
        :to="{
          name: 'waves.detail',
          params: { ulid: campaign.relationships.brand.data.id, waveUlid: wave.id },
        }"
        class="agency-entity-link"
        data-test="campaign-wave-link"
        >{{ wave.attributes.name }}</router-link
      >
      <div class="text-caption text-medium-emphasis" data-test="campaign-wave-go-live">
        {{
          t('app.waves.table.goLive', {
            date: formatDateTime(`${wave.attributes.go_live_on}T00:00:00`, locale, {
              dateStyle: 'medium',
            }),
          })
        }}
      </div>
    </div>
    <p v-else class="text-medium-emphasis" data-test="campaign-wave-none">
      {{ t('app.campaigns.waveCard.none') }}
    </p>

    <p v-if="locked" class="text-caption mt-2 mb-0" data-test="campaign-wave-locked">
      {{ t('app.campaigns.waveCard.locked') }}
    </p>

    <div v-if="canManage && !picking" class="d-flex ga-2 mt-3">
      <v-btn
        v-if="wave === null"
        variant="outlined"
        size="small"
        :disabled="busy"
        data-test="campaign-wave-link-btn"
        @click="openPicker"
      >
        {{ t('app.campaigns.waveCard.link') }}
      </v-btn>
      <template v-else>
        <v-btn
          variant="outlined"
          size="small"
          :disabled="busy || locked"
          data-test="campaign-wave-change-btn"
          @click="openPicker"
        >
          {{ t('app.campaigns.waveCard.change') }}
        </v-btn>
        <v-btn
          variant="text"
          size="small"
          :disabled="busy || locked"
          :loading="busy"
          data-test="campaign-wave-unlink-btn"
          @click="unlink"
        >
          {{ t('app.campaigns.waveCard.unlink') }}
        </v-btn>
      </template>
    </div>

    <div v-if="picking" class="mt-3" data-test="campaign-wave-picker">
      <v-select
        v-model="choice"
        :items="items"
        item-title="title"
        item-value="value"
        :label="t('app.campaigns.waveCard.pick')"
        :hint="items.length === 0 ? t('app.campaigns.waveCard.noOptions') : undefined"
        persistent-hint
        variant="outlined"
        density="comfortable"
        data-test="campaign-wave-select"
      />
      <div class="d-flex ga-2 mt-2">
        <v-btn
          color="primary"
          variant="flat"
          size="small"
          :loading="busy"
          :disabled="choice === null"
          data-test="campaign-wave-confirm"
          @click="confirm"
        >
          {{ t('app.waves.dialog.save') }}
        </v-btn>
        <v-btn
          variant="text"
          size="small"
          :disabled="busy"
          data-test="campaign-wave-cancel"
          @click="picking = false"
        >
          {{ t('app.brands.archive.cancel') }}
        </v-btn>
      </div>
    </div>

    <v-alert
      v-if="error"
      type="error"
      variant="tonal"
      density="compact"
      class="mt-3"
      data-test="campaign-wave-error"
    >
      {{ error }}
    </v-alert>
  </section>
</template>
