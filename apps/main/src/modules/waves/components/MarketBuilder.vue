<script setup lang="ts">
/**
 * PL-2.4 — the Markets field of a wave: each market is a named group of one or more countries. Add a market, pick its
 * countries (a country already in another market of this wave is unavailable and says which market has it), name it (a
 * single-country market is named for its country until the user types a name), edit its short code (suggested from the name),
 * rename, add and remove countries, remove a market. Limits: 20 markets, 40 countries per market.
 *
 * Names are free text and are only ever rendered as text. The list is a v-model of {@link MarketDraft}s; the parent turns
 * it into the request body and decides when errors are shown.
 */
import { COUNTRY_OPTIONS, labelForCountryCode } from '@catalyst/api-client'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { MAX_MARKETS, MENU_ABOVE_DIALOG_Z } from '../constants'
import {
  emptyDraft,
  MAX_COUNTRIES,
  MAX_NAME,
  ownerOf,
  refreshDraft,
  type MarketDraft,
  type MarketDraftErrors,
} from '../marketDrafts'

const props = defineProps<{
  modelValue: MarketDraft[]
  errors: Record<string, MarketDraftErrors>
  /** The overall message above the list (no market, too many, or the markets problem line). */
  errorMessage?: string | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: MarketDraft[]] }>()

const { t } = useI18n()

const openFor = ref<string | null>(null)
const search = ref('')

const atMarketLimit = computed(() => props.modelValue.length >= MAX_MARKETS)

function mutate(fn: (drafts: MarketDraft[]) => void): void {
  const next = props.modelValue.map((d) => ({ ...d, countries: [...d.countries] }))
  fn(next)
  emit('update:modelValue', next)
}

function addMarket(): void {
  if (atMarketLimit.value) return
  mutate((d) => d.push(emptyDraft()))
}

function removeMarket(index: number): void {
  mutate((d) => d.splice(index, 1))
}

function setName(index: number, value: string): void {
  mutate((d) => {
    const m = d[index]
    if (m === undefined) return
    m.name = value
    m.nameTouched = true
    refreshDraft(d, index)
  })
}

function setCode(index: number, value: string): void {
  mutate((d) => {
    const m = d[index]
    if (m === undefined) return
    m.code = value
      .toUpperCase()
      .replace(/[^A-Z0-9]/g, '')
      .slice(0, 6)
    m.codeTouched = true
  })
}

function addCountry(index: number, code: string): void {
  mutate((d) => {
    const m = d[index]
    if (m === undefined || m.countries.includes(code) || m.countries.length >= MAX_COUNTRIES) return
    if (ownerOf(d, code, m.key) !== undefined) return
    m.countries.push(code)
    refreshDraft(d, index)
  })
  // The list stays open (a market can have many countries); Escape or a click outside closes it.
  search.value = ''
}

function removeCountry(index: number, code: string): void {
  mutate((d) => {
    const m = d[index]
    if (m === undefined) return
    m.countries = m.countries.filter((c) => c !== code)
    refreshDraft(d, index)
  })
}

function options(draft: MarketDraft): { code: string; label: string; takenBy: string | null }[] {
  const needle = search.value.trim().toLowerCase()
  return COUNTRY_OPTIONS.filter((c) => !draft.countries.includes(c.code))
    .filter(
      (c) =>
        needle === '' || c.label.toLowerCase().includes(needle) || c.code.toLowerCase() === needle,
    )
    .map((c) => {
      const owner = ownerOf(props.modelValue, c.code, draft.key)
      return {
        code: c.code,
        label: c.label,
        takenBy: owner === undefined ? null : owner.name.trim() === '' ? '…' : owner.name,
      }
    })
}

const NAME_ERRORS = {
  required: 'app.waves.markets.nameRequired',
  tooLong: 'app.waves.markets.nameTooLong',
  taken: 'app.waves.markets.nameTaken',
} as const
const CODE_ERRORS = {
  invalid: 'app.waves.markets.codeInvalid',
  taken: 'app.waves.markets.codeTaken',
} as const

function nameError(d: MarketDraft): string[] {
  const e = props.errors[d.key]?.name
  return e === undefined ? [] : [t(NAME_ERRORS[e], { max: MAX_NAME })]
}
function codeError(d: MarketDraft): string[] {
  const e = props.errors[d.key]?.code
  return e === undefined ? [] : [t(CODE_ERRORS[e])]
}
</script>

<template>
  <div class="market-builder" data-test="market-picker">
    <div
      v-for="(d, i) in modelValue"
      :key="d.key"
      class="market-card"
      :data-test="`market-card-${i}`"
    >
      <div class="market-card__row">
        <v-text-field
          :model-value="d.name"
          :label="t('app.waves.markets.name')"
          variant="outlined"
          density="compact"
          hide-details="auto"
          :maxlength="MAX_NAME * 2"
          :error-messages="nameError(d)"
          class="market-card__name"
          data-test="market-name-input"
          @update:model-value="(v: string) => setName(i, v)"
        />
        <v-text-field
          :model-value="d.code"
          :label="t('app.waves.markets.code')"
          variant="outlined"
          density="compact"
          hide-details="auto"
          maxlength="6"
          :error-messages="codeError(d)"
          class="market-card__code"
          data-test="market-code-input"
          @update:model-value="(v: string) => setCode(i, v)"
        />
        <v-btn
          icon="mdi-delete-outline"
          variant="text"
          size="small"
          :aria-label="t('app.waves.markets.removeMarket', { market: d.name || `${i + 1}` })"
          data-test="market-remove"
          @click="removeMarket(i)"
        />
      </div>
      <p class="market-card__hint" data-test="market-code-hint">
        {{ t('app.waves.markets.codeHint', { example: `${d.code || 'UKI'}1` }) }}
      </p>

      <div class="market-card__countries">
        <v-chip
          v-for="c in d.countries"
          :key="c"
          size="small"
          closable
          class="market-chip"
          :close-label="
            t('app.waves.markets.removeCountry', {
              country: labelForCountryCode(c),
              market: d.name,
            })
          "
          :data-test="`market-chip-${c}`"
          @click:close="removeCountry(i, c)"
        >
          <strong class="market-chip__code">{{ c }}</strong>
          <span class="market-chip__name">{{ labelForCountryCode(c) }}</span>
        </v-chip>

        <v-menu
          :model-value="openFor === d.key"
          :close-on-content-click="false"
          :disabled="d.countries.length >= MAX_COUNTRIES"
          :z-index="MENU_ABOVE_DIALOG_Z"
          location="end bottom"
          max-height="calc(100dvh - 24px)"
          content-class="market-picker__menu"
          @update:model-value="
            (v: boolean) => {
              openFor = v ? d.key : null
              search = ''
            }
          "
        >
          <template #activator="{ props: activatorProps }">
            <v-chip
              v-bind="activatorProps"
              size="small"
              variant="outlined"
              prepend-icon="mdi-plus"
              class="market-add-chip"
              :disabled="d.countries.length >= MAX_COUNTRIES"
              data-test="market-add-country"
            >
              {{ t('app.waves.markets.addCountry') }}
            </v-chip>
          </template>
          <v-card min-width="260" max-width="320">
            <v-text-field
              v-model="search"
              density="compact"
              variant="outlined"
              hide-details
              autofocus
              class="ma-2"
              :placeholder="t('app.waves.markets.searchCountry')"
              data-test="market-search"
            />
            <v-list density="compact" class="market-picker__list">
              <v-list-item
                v-for="c in options(d)"
                :key="c.code"
                :title="c.label"
                :subtitle="
                  c.takenBy === null
                    ? undefined
                    : t('app.waves.markets.countryTaken', { market: c.takenBy })
                "
                :disabled="c.takenBy !== null"
                :data-test="`market-option-${c.code}`"
                @click="addCountry(i, c.code)"
              />
              <v-list-item
                v-if="options(d).length === 0"
                :title="t('app.waves.dialog.noMarketMatch')"
                disabled
                data-test="market-none"
              />
            </v-list>
          </v-card>
        </v-menu>
      </div>
      <p
        v-if="errors[d.key]?.countries"
        class="text-caption text-error mt-1 mb-0"
        data-test="market-countries-error"
      >
        {{ t('app.waves.markets.countriesRequired') }}
      </p>
      <p v-if="d.countries.length >= MAX_COUNTRIES" class="text-caption mt-1 mb-0">
        {{ t('app.waves.markets.countryLimit', { max: MAX_COUNTRIES }) }}
      </p>
    </div>

    <div class="market-builder__foot">
      <v-chip
        size="small"
        variant="outlined"
        prepend-icon="mdi-plus"
        :disabled="atMarketLimit"
        class="market-add-chip market-builder__add"
        data-test="market-add"
        @click="addMarket"
      >
        {{ t('app.waves.dialog.addMarket') }}
      </v-chip>
      <span v-if="atMarketLimit" class="text-caption" data-test="market-limit">{{
        t('app.waves.markets.marketLimit', { max: MAX_MARKETS })
      }}</span>
    </div>
    <p v-if="errorMessage" class="text-caption text-error mt-1 mb-0" data-test="market-error">
      {{ errorMessage }}
    </p>
  </div>
</template>

<style scoped>
.market-builder {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.market-card {
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 10px;
  padding: 12px;
}
.market-card__row {
  display: flex;
  align-items: flex-start;
  gap: 8px;
}
.market-card__name {
  flex: 1 1 auto;
  min-width: 0;
}
.market-card__code {
  flex: 0 0 110px;
}
@media (max-width: 559px) {
  .market-card__row {
    flex-wrap: wrap;
  }
  .market-card__name {
    flex: 1 1 100%;
  }
  .market-card__code {
    flex: 1 1 0;
  }
}
.market-card__hint {
  margin: 6px 0 10px;
  font-size: 12px;
  line-height: 1.3;
  color: rgb(var(--v-theme-on-surface-variant));
}
.market-card__countries {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}
.market-builder__foot {
  display: flex;
  align-items: center;
  gap: 10px;
}
.market-builder__add {
  border-style: dashed;
}
/* Frame 13:4026: market chip radius 8, pad 6/10, 13 / 500, code badge (10 / 700 on the primary colour, radius 4, pad 1/5),
   primary at 10 % with a 50 % border; "Add market" dashed, radius 8, 13 / 500 in the primary colour. */
.market-chip.v-chip,
.market-add-chip.v-chip {
  height: auto;
  min-height: 0;
  padding: 6px 10px;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 500;
  gap: 6px;
}
.market-chip.v-chip {
  background: color-mix(in srgb, rgb(var(--v-theme-primary)) 10%, transparent);
  border: 1px solid color-mix(in srgb, rgb(var(--v-theme-primary)) 50%, transparent);
  color: rgb(var(--v-theme-on-surface));
}
.market-chip :deep(.v-chip__underlay) {
  display: none;
}
.market-chip__code {
  padding: 1px 5px;
  margin-right: 6px;
  border-radius: 4px;
  background: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-primary));
  font-size: 10px;
  font-weight: 700;
  line-height: normal;
}
.market-chip :deep(.v-chip__close) {
  margin-inline: 6px 0;
  font-size: 12px;
}
.market-add-chip.v-chip {
  border-color: rgb(var(--v-theme-border-color));
  color: rgb(var(--v-theme-primary));
}
.market-add-chip :deep(.v-icon) {
  font-size: 13px;
  margin-inline-end: 0;
}
</style>

<style>
/* Teleported, so this cannot be scoped. The class exists only while the menu is open. */
.market-picker__menu {
  max-height: calc(100dvh - 24px);
  overflow: hidden;
}
.market-picker__menu > .v-card {
  display: flex;
  flex-direction: column;
  max-height: calc(100dvh - 24px);
}
.market-picker__list {
  min-height: 0;
  overflow-y: auto;
  max-height: min(260px, calc(100dvh - 120px));
}
</style>
