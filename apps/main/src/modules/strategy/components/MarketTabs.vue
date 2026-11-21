<script setup lang="ts">
/**
 * PL-2.4 — the market bar of Personas, Matrix and Concepts (frame 13:828): one tab per market of the wave, showing the
 * market's NAME (truncated when long; the full name and the countries are in the list). Hovering a tab shows the countries
 * inside (code and name); the info button after the tab opens the same list on tap and by keyboard. The `extra` slot is the
 * step's own count or progress. Names are plain text.
 */
import { labelForCountryCode } from '@catalyst/api-client'
import { useI18n } from 'vue-i18n'

import MarketCountriesInfo from '@/modules/waves/components/MarketCountriesInfo.vue'
import type { StrategyMarket } from '../useMarkets'

defineProps<{ markets: StrategyMarket[]; active: string }>()
const emit = defineEmits<{ select: [id: string] }>()

const { t } = useI18n()
</script>

<template>
  <div class="market-bar">
    <span class="market-bar__label" aria-hidden="true">{{
      t('app.strategy.persona.marketLabel')
    }}</span>
    <div
      role="tablist"
      class="market-tabs"
      :aria-label="t('app.strategy.persona.markets')"
      data-test="market-tabs"
    >
      <div
        v-for="m in markets"
        :key="m.id"
        class="market-tab-wrap"
        :class="{ 'market-tab-wrap--active': m.id === active }"
      >
        <button
          type="button"
          role="tab"
          class="market-tab"
          :aria-selected="m.id === active"
          :data-test="`market-tab-${m.code}`"
          @click="emit('select', m.id)"
        >
          <span class="market-tab__name" :data-test="`market-name-${m.code}`">{{ m.name }}</span>
          <slot name="extra" :market="m" />
          <v-tooltip location="bottom" activator="parent" max-width="320">
            <div class="market-tip__name">{{ m.name }}</div>
            <div v-for="c in m.countries" :key="c" class="market-tip__row">
              <span class="market-tip__code">{{ c }}</span> {{ labelForCountryCode(c) }}
            </div>
          </v-tooltip>
        </button>
        <MarketCountriesInfo :id="m.code" :name="m.name" :countries="m.countries" />
      </div>
    </div>
  </div>
</template>

<style scoped>
.market-bar {
  display: flex;
  align-items: flex-end;
  gap: 4px 14px;
  border-bottom: 1px solid rgb(var(--v-theme-border-color));
}
.market-bar__label {
  padding-bottom: 12px;
  font-size: 11px;
  font-weight: 600;
  line-height: normal;
  letter-spacing: 0.6px;
  text-transform: uppercase;
  color: rgb(var(--v-theme-text-tertiary));
}
.market-tabs {
  display: flex;
  gap: 4px;
  overflow-x: auto;
  min-width: 0;
}
.market-tab-wrap {
  flex: 0 0 auto;
  display: inline-flex;
  align-items: center;
  border-bottom: 2px solid transparent;
  color: rgb(var(--v-theme-on-surface-variant));
}
.market-tab-wrap--active {
  border-bottom-color: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-surface));
  font-weight: 600;
}
.market-tab {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 2px 12px 14px;
  border: 0;
  background: transparent;
  color: inherit;
  font: inherit;
  font-size: 14px;
  line-height: normal;
  cursor: pointer;
  white-space: nowrap;
}
.market-tab__name {
  max-width: 180px;
  overflow: hidden;
  text-overflow: ellipsis;
}
.market-tip__name {
  font-weight: 600;
  margin-bottom: 4px;
  overflow-wrap: anywhere;
}
.market-tip__row {
  font-size: 12px;
}
.market-tip__code {
  display: inline-block;
  min-width: 24px;
  font-weight: 700;
}
</style>
