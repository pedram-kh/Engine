<script setup lang="ts">
/**
 * PL-1.1 — the Waves panel on the brand page: a table of the brand's waves (name with go-live date,
 * markets, progress, status, updated) and "New wave". Rendered only while `features.waves` is on.
 *
 * Staff see the list; New wave is for an admin or manager on an active brand. A row opens the wave page.
 */
import { formatDateTime } from '@catalyst/api-client'
import type { WaveResource } from '@catalyst/api-client'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import AgencyStatusPill from '@/modules/agency/components/AgencyStatusPill.vue'
import { wavesApi } from '@/modules/waves/api/waves.api'
import NewWaveDialog from '@/modules/waves/components/NewWaveDialog.vue'
import WaveMarketsInfo from '@/modules/waves/components/WaveMarketsInfo.vue'
import WaveProgressSegments from '@/modules/waves/components/WaveProgressSegments.vue'

const props = defineProps<{
  agencyId: string
  brandId: string
  brandName: string
  /** Admin or manager, and the brand is active. */
  canWrite: boolean
}>()

const { t, locale } = useI18n()
const router = useRouter()

const waves = ref<WaveResource[]>([])
const total = ref(0)
const loading = ref(true)
const error = ref<string | null>(null)
const dialog = ref(false)

const MAX_VISIBLE_MARKETS = 3

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await wavesApi.list(props.agencyId, props.brandId, { per_page: 25 })
    waves.value = res.data
    total.value = res.meta.total
  } catch {
    error.value = t('app.waves.errors.loadFailed')
  } finally {
    loading.value = false
  }
}

function marketsLabel(w: WaveResource): { shown: string[]; more: number } {
  const all = w.attributes.markets.map((m) => m.name)
  return {
    shown: all.slice(0, MAX_VISIBLE_MARKETS),
    more: Math.max(0, all.length - MAX_VISIBLE_MARKETS),
  }
}

const countLabel = computed(() => t('app.waves.panel.count', { count: total.value }, total.value))

function open(w: WaveResource): void {
  void router.push({ name: 'waves.detail', params: { ulid: props.brandId, waveUlid: w.id } })
}

async function onSaved(w: WaveResource): Promise<void> {
  await load()
  open(w)
}

onMounted(load)
defineExpose({ load })
</script>

<template>
  <section class="agency-card" data-test="brand-waves-panel">
    <div class="agency-panel__head">
      <div>
        <h2 class="agency-card__title">{{ t('app.waves.panel.title') }}</h2>
        <p class="agency-panel__subtitle">{{ t('app.waves.panel.subtitle') }}</p>
      </div>
      <v-btn
        v-if="canWrite"
        color="primary"
        variant="flat"
        prepend-icon="mdi-plus"
        data-test="wave-new-btn"
        @click="dialog = true"
      >
        {{ t('app.waves.panel.newWave') }}
      </v-btn>
    </div>

    <v-skeleton-loader v-if="loading" type="table-row@2" data-test="brand-waves-loading" />
    <v-alert v-else-if="error" type="error" variant="tonal" data-test="brand-waves-error">
      {{ error }}
    </v-alert>
    <div v-else-if="waves.length === 0" data-test="brand-waves-empty">
      <h3 class="text-subtitle-1">{{ t('app.waves.empty.heading') }}</h3>
      <p class="agency-panel__empty">{{ t('app.waves.empty.body') }}</p>
    </div>
    <template v-else>
      <table class="agency-panel__table" data-test="brand-waves-table">
        <thead>
          <tr>
            <th>{{ t('app.waves.table.wave') }}</th>
            <th>{{ t('app.waves.table.markets') }}</th>
            <th>{{ t('app.waves.table.progress') }}</th>
            <th>{{ t('app.brands.fields.status') }}</th>
            <th>{{ t('app.waves.table.updated') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="w in waves"
            :key="w.id"
            class="cursor-pointer"
            :data-test="`wave-row-${w.id}`"
            @click="open(w)"
          >
            <td>
              <div class="font-weight-medium">{{ w.attributes.name }}</div>
              <div class="text-caption text-medium-emphasis">
                {{
                  t('app.waves.table.goLive', {
                    date: formatDateTime(`${w.attributes.go_live_on}T00:00:00`, locale, {
                      dateStyle: 'medium',
                    }),
                  })
                }}
              </div>
            </td>
            <td data-test="wave-row-markets">
              <span
                class="wave-row-markets__names"
                :title="w.attributes.markets.map((m) => m.name).join(', ')"
                >{{ marketsLabel(w).shown.join(', ') }}</span
              >
              <span v-if="marketsLabel(w).more > 0" class="text-medium-emphasis">
                +{{ marketsLabel(w).more }}
              </span>
              <WaveMarketsInfo :id="w.id" :markets="w.attributes.markets" />
            </td>
            <td><WaveProgressSegments :progress="w.attributes.progress" /></td>
            <td><AgencyStatusPill kind="wave" :status="w.attributes.status" /></td>
            <td>{{ formatDateTime(w.attributes.updated_at, locale, { dateStyle: 'medium' }) }}</td>
          </tr>
        </tbody>
      </table>
      <p class="agency-panel__subtitle" data-test="brand-waves-count">{{ countLabel }}</p>
    </template>

    <NewWaveDialog
      v-model="dialog"
      :agency-id="agencyId"
      :brand-id="brandId"
      :brand-name="brandName"
      @saved="onSaved"
    />
  </section>
</template>

<style scoped>
.wave-row-markets__names {
  display: inline-block;
  max-width: 240px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  vertical-align: bottom;
}
</style>
