<script setup lang="ts">
/**
 * PL-2.4 — every market of a wave with the countries inside it (code and name), behind one info button: on the Brand
 * page waves table, where a row has room for market names but not for their countries. Opens on tap and by keyboard.
 * Names are plain text.
 */
import { labelForCountryCode } from '@catalyst/api-client'
import type { WaveMarket } from '@catalyst/api-client'
import { useI18n } from 'vue-i18n'

import { MENU_ABOVE_DIALOG_Z } from '../constants'

defineProps<{ markets: WaveMarket[]; id: string }>()

const { t } = useI18n()
</script>

<template>
  <v-menu location="bottom start" :z-index="MENU_ABOVE_DIALOG_Z" content-class="market-info-menu">
    <template #activator="{ props: activatorProps }">
      <v-btn
        v-bind="activatorProps"
        icon="mdi-information-outline"
        size="x-small"
        variant="text"
        density="comfortable"
        class="market-info-btn"
        :aria-label="t('app.waves.markets.countriesOfWave')"
        :data-test="`wave-markets-info-${id}`"
        @click.stop
      />
    </template>
    <v-card
      min-width="240"
      max-width="340"
      class="market-info-card"
      data-test="wave-markets-countries"
    >
      <v-list density="compact" class="market-info-card__list">
        <template v-for="m in markets" :key="m.id">
          <v-list-subheader class="market-info-card__title">{{ m.name }}</v-list-subheader>
          <v-list-item
            v-for="c in m.countries"
            :key="c"
            :data-test="`wave-market-country-${m.code}-${c}`"
          >
            <span class="market-info-card__code">{{ c }}</span>
            <span>{{ labelForCountryCode(c) }}</span>
          </v-list-item>
        </template>
      </v-list>
    </v-card>
  </v-menu>
</template>
