<script setup lang="ts">
/**
 * PL-2.4 — the countries inside a market: an info button that opens the list (code and name) on tap and by keyboard
 * (Tab to the button, Enter or Space). The same list is the one every market tab, the waves table and the wave page use.
 * The market's full name is the list's title, so a name truncated where it is shown can still be read in full. Names are
 * plain text, never HTML.
 */
import { labelForCountryCode } from '@catalyst/api-client'
import { useI18n } from 'vue-i18n'

import { MENU_ABOVE_DIALOG_Z } from '../constants'

defineProps<{ name: string; countries: string[]; id?: string }>()

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
        :aria-label="t('app.waves.markets.countriesOf', { market: name })"
        :data-test="`market-info${id ? `-${id}` : ''}`"
        @click.stop
      />
    </template>
    <v-card min-width="220" max-width="320" class="market-info-card" data-test="market-countries">
      <v-card-title
        class="text-subtitle-2 market-info-card__title"
        data-test="market-countries-name"
        >{{ name }}</v-card-title
      >
      <v-list density="compact" class="market-info-card__list">
        <v-list-item v-for="code in countries" :key="code" :data-test="`market-country-${code}`">
          <span class="market-info-card__code">{{ code }}</span>
          <span>{{ labelForCountryCode(code) }}</span>
        </v-list-item>
      </v-list>
    </v-card>
  </v-menu>
</template>

<style>
/* A finger needs more than 20 px. */
@media (pointer: coarse) {
  .market-info-btn.v-btn {
    width: 36px;
    height: 36px;
  }
}

/* Teleported, so not scoped. */
.market-info-card__title {
  white-space: normal;
  overflow-wrap: anywhere;
}
.market-info-card__list {
  max-height: min(280px, calc(100dvh - 140px));
  overflow-y: auto;
}
.market-info-card__code {
  display: inline-block;
  min-width: 28px;
  margin-right: 8px;
  font-size: 11px;
  font-weight: 700;
  color: rgb(var(--v-theme-on-surface-variant));
}
.market-info-btn {
  flex: 0 0 auto;
}
</style>
