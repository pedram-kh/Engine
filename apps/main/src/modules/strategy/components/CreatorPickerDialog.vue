<script setup lang="ts">
/**
 * PL-3.1 — the creator picker (frame 13:2572): the creators who applied to or were invited on the wave's campaign, as compact
 * Discover-style cards. The market's countries are the opening filter, as chips that can be removed; search is the server's.
 * Multi-select adds creators to the market (with one persona for all, or "choose per creator later"); the single-select mode
 * is "Replace": one creator takes the slot, which keeps its code and persona and loses the old creator's decisions.
 *
 * What is eligible is the SERVER's rule (plan 1.5): a creator who is blacklisted, exclusive to another brand or erased is not
 * in this list, and the server refuses them again on add. The caps are shown before the request, but never as a target: the
 * line says "n creators in <market>", and the ceiling only from 5 below it ("Up to 30 per market · n places left").
 * The dialog is rebuilt on every open (a counter keys the card), so a quick close and reopen leaves nothing behind.
 */
import type {
  ScriptsOnReplace,
  WaveCreatorCandidate,
  WaveCreatorSlot,
  WavePersonaResource,
} from '@catalyst/api-client'
import { CEmptyState } from '@catalyst/ui'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { creatorLabel } from '@/core/creators/creatorLabel'
import { strategyApi, type WaveScope } from '../api/strategy.api'
import { MENU_PROPS } from '../constants'
import { selectable } from '../creatorsLogic'
import { archivedReturn } from '../scriptsLogic'
import { nearLimit } from '../limitLogic'
import { strategyErrorMessage } from '../strategyErrors'
import type { StrategyMarket } from '../useMarkets'
import WaveCreatorCard from './WaveCreatorCard.vue'

const PER_PAGE = 30
const SEARCH_DEBOUNCE_MS = 300

const props = defineProps<{
  modelValue: boolean
  scope: WaveScope
  market: StrategyMarket | null
  /** Names of the wave's markets by id, for "Already in wave · {market}". */
  marketName: (id: string | null | undefined) => string
  personas: WavePersonaResource[]
  campaignId: string | null
  campaignName: string | null
  /** `add`: many; `replace`: one, for this slot. */
  mode: 'add' | 'replace'
  replacing: WaveCreatorSlot | null
  /** Places left in the market and in the wave, shown before the request. */
  roomMarket: number
  roomWave: number
  perMarket: number
  perWave: number
  /** PL-3.2a: how many scripts the slot being replaced has (live ones). Above 0 the dialog asks what happens to them. */
  scriptCount?: number
}>()
const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  done: []
}>()

const { t } = useI18n()
const session = ref(0)
const q = ref('')
const countries = ref<string[]>([])
const items = ref<WaveCreatorCandidate[]>([])
const total = ref(0)
const page = ref(1)
const lastPage = ref(1)
const loading = ref(false)
const loadError = ref<string | null>(null)
const selected = ref<WaveCreatorCandidate[]>([])
const personaId = ref<string | null>(null)
const saving = ref(false)
/** PL-3.2a: what happens to the slot's scripts on Replace. Carrying them over is the default; archiving starts fresh. */
const scriptsChoice = ref<ScriptsOnReplace>('carry_over')
const askScripts = computed(() => single.value && (props.scriptCount ?? 0) > 0)
const submitError = ref<string | null>(null)
let ticket = 0
let timer: ReturnType<typeof setTimeout> | null = null

const single = computed(() => props.mode === 'replace')
const initialCountries = computed(() => props.market?.countries ?? [])
/** True when the campaign itself has nobody (found by a probe without the chips), so the empty state is "no applicants yet". */
const campaignEmpty = ref(false)
const filtered = computed(
  () => !campaignEmpty.value && (q.value.trim() !== '' || countries.value.length > 0),
)
const room = computed(() => Math.min(props.roomMarket, props.roomWave))
const overCap = computed(() => !single.value && selected.value.length > room.value)
/** The line under the list. A ceiling is never shown as a target: normally the count, the ceiling only near it (F2). */
const capLine = computed<{ text: string; state: 'normal' | 'near' | 'max' }>(() => {
  const marketName = props.market?.name ?? ''
  const used = props.perMarket - props.roomMarket
  if (props.roomMarket === 0)
    return {
      text: t('app.strategy.creators.picker.maxMarket', {
        max: props.perMarket,
        market: marketName,
      }),
      state: 'max',
    }
  if (props.roomWave === 0)
    return {
      text: t('app.strategy.creators.picker.maxWave', { max: props.perWave }),
      state: 'max',
    }
  if (nearLimit(props.roomWave) && props.roomWave < props.roomMarket)
    return {
      text: `${t('app.strategy.creators.picker.upToWave', { max: props.perWave })} · ${t('app.strategy.limit.placesLeft', { count: props.roomWave })}`,
      state: 'near',
    }
  if (nearLimit(props.roomMarket))
    return {
      text: `${t('app.strategy.creators.picker.upToMarket', { max: props.perMarket })} · ${t('app.strategy.limit.placesLeft', { count: props.roomMarket })}`,
      state: 'near',
    }
  return {
    text: t('app.strategy.creators.picker.inMarket', { count: used, market: marketName }),
    state: 'normal',
  }
})
const capText = computed(() => (single.value ? '' : capLine.value.text))
const names = computed(() => {
  const list = selected.value.map((c) =>
    creatorLabel(c, t, t('app.strategy.creators.card.unnamed')),
  )
  return list.length > 3 ? `${list.slice(0, 3).join(', ')} +${list.length - 3}` : list.join(', ')
})
/**
 * Walk ruling: before the click, how many archived scripts of each chosen creator in this market come back, and how many stay
 * archived because three live scripts is the limit. In Replace the scripts carried over from the old creator count first.
 */
const returning = computed(() => {
  const carried =
    single.value && askScripts.value && scriptsChoice.value === 'carry_over'
      ? (props.scriptCount ?? 0)
      : 0
  return selected.value
    .filter((c) => c.archived_scripts > 0)
    .map((c) => ({
      id: c.id,
      name: creatorLabel(c, t, t('app.strategy.creators.card.unnamed')),
      ...archivedReturn(c.archived_scripts, carried),
    }))
})
const canSubmit = computed(
  () => selected.value.length > 0 && !overCap.value && !saving.value && !loading.value,
)

function isSelected(c: WaveCreatorCandidate): boolean {
  return selected.value.some((s) => s.id === c.id)
}
function toggle(c: WaveCreatorCandidate): void {
  if (!selectable(c)) return
  if (single.value) {
    selected.value = isSelected(c) ? [] : [c]
    return
  }
  selected.value = isSelected(c)
    ? selected.value.filter((s) => s.id !== c.id)
    : [...selected.value, c]
}
function removeCountry(code: string): void {
  countries.value = countries.value.filter((c) => c !== code)
  void load(true)
}

async function load(reset: boolean): Promise<void> {
  const m = props.market
  if (m === null) return
  const mine = ++ticket
  loading.value = true
  loadError.value = null
  const wanted = reset ? 1 : page.value + 1
  try {
    const res = await strategyApi.creatorCandidates(props.scope, {
      market: m.id,
      q: q.value,
      countries: countries.value,
      page: wanted,
      per_page: PER_PAGE,
    })
    if (mine !== ticket) return
    items.value = reset ? res.data : [...items.value, ...res.data]
    total.value = res.meta.total
    page.value = res.meta.page
    lastPage.value = res.meta.last_page
    campaignEmpty.value = false
    if (reset && res.meta.total === 0) {
      if (q.value.trim() === '' && countries.value.length === 0) {
        campaignEmpty.value = true
      } else if (q.value.trim() === '') {
        // Nobody under the market's countries: is the campaign empty, or is it the filter? One cheap look without the chips.
        const probe = await strategyApi.creatorCandidates(props.scope, {
          market: m.id,
          q: '',
          countries: [],
          page: 1,
          per_page: 1,
        })
        if (mine !== ticket) return
        campaignEmpty.value = probe.meta.total === 0
      }
    }
  } catch (err) {
    if (mine === ticket) loadError.value = strategyErrorMessage(err, t)
  } finally {
    if (mine === ticket) loading.value = false
  }
}

watch(
  () => props.modelValue,
  (open) => {
    session.value++
    ticket++
    if (timer !== null) clearTimeout(timer)
    q.value = ''
    countries.value = [...initialCountries.value]
    items.value = []
    total.value = 0
    page.value = 1
    lastPage.value = 1
    selected.value = []
    campaignEmpty.value = false
    personaId.value = null
    scriptsChoice.value = 'carry_over'
    saving.value = false
    submitError.value = null
    loadError.value = null
    if (open) void load(true)
  },
  { immediate: true },
)

watch(q, () => {
  if (!props.modelValue) return
  if (timer !== null) clearTimeout(timer)
  timer = setTimeout(() => void load(true), SEARCH_DEBOUNCE_MS)
})
onBeforeUnmount(() => {
  if (timer !== null) clearTimeout(timer)
  ticket++
})

function close(): void {
  if (!saving.value) emit('update:modelValue', false)
}

async function submit(): Promise<void> {
  if (!canSubmit.value || props.market === null) return
  saving.value = true
  submitError.value = null
  try {
    const first = selected.value[0]
    if (single.value && props.replacing !== null && first !== undefined) {
      await strategyApi.replaceCreator(
        props.scope,
        props.replacing.id,
        first.id,
        askScripts.value ? scriptsChoice.value : undefined,
      )
    } else {
      await strategyApi.addCreators(props.scope, {
        market: props.market.id,
        creators: selected.value.map((c) => c.id),
        ...(personaId.value === null ? {} : { persona: personaId.value }),
      })
    }
    emit('done')
    emit('update:modelValue', false)
  } catch (err) {
    submitError.value = strategyErrorMessage(err, t)
    // The list may be stale (someone else added a creator): read it again, keeping what is ticked.
    void load(true)
  } finally {
    saving.value = false
  }
}

const personaItems = computed(() => [
  { value: null as string | null, title: t('app.strategy.creators.picker.choosePerCreator') },
  ...props.personas.map((p) => ({
    value: p.id as string | null,
    title: `${p.attributes.code} · ${p.attributes.title}`,
  })),
])
const title = computed(() =>
  single.value
    ? t('app.strategy.creators.picker.titleReplace', {
        creator:
          props.replacing === null
            ? ''
            : creatorLabel(props.replacing.creator, t, props.replacing.slot_code),
      })
    : t('app.strategy.creators.picker.title', { market: props.market?.name ?? '' }),
)
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="1180"
    persistent
    scrollable
    class="picker-dialog"
    data-test="creator-picker"
    @update:model-value="
      (v: boolean) => {
        if (!v) close()
      }
    "
    @keydown.esc="close"
  >
    <v-card :key="session" class="picker">
      <div class="picker__head">
        <div class="picker__titles">
          <h2 class="picker__title" data-test="creator-picker-title">{{ title }}</h2>
          <p class="picker__sub">
            {{ t('app.strategy.creators.picker.subtitle', { campaign: campaignName ?? '' }) }}
          </p>
        </div>
        <v-btn
          icon="mdi-close"
          variant="text"
          size="small"
          :aria-label="t('app.strategy.creators.close')"
          :disabled="saving"
          data-test="creator-picker-close"
          @click="close"
        />
      </div>

      <div class="picker__body">
        <v-text-field
          v-model="q"
          :placeholder="t('app.strategy.creators.picker.search')"
          :aria-label="t('app.strategy.creators.picker.search')"
          prepend-inner-icon="mdi-magnify"
          variant="outlined"
          density="comfortable"
          hide-details
          clearable
          data-test="creator-picker-search"
        />

        <div class="picker__filters">
          <span
            v-for="code in countries"
            :key="code"
            class="picker__chip"
            :data-test="`creator-picker-country-${code}`"
          >
            <span class="picker__chip-label">{{ t('app.strategy.creators.picker.country') }}</span>
            <strong>{{ code }}</strong>
            <button
              type="button"
              class="picker__chip-x"
              :aria-label="t('app.strategy.creators.picker.removeCountry', { country: code })"
              :data-test="`creator-picker-country-remove-${code}`"
              @click="removeCountry(code)"
            >
              <v-icon icon="mdi-close" size="13" aria-hidden="true" />
            </button>
          </span>
          <span class="picker__note">
            {{ t('app.strategy.creators.picker.prefiltered') }}
            ·
            <span data-test="creator-picker-count">{{
              t('app.strategy.creators.picker.results', { count: total })
            }}</span>
          </span>
        </div>

        <v-alert
          v-if="loadError"
          type="error"
          variant="tonal"
          density="compact"
          data-test="creator-picker-load-error"
        >
          {{ loadError }}
          <template #append>
            <v-btn size="small" variant="text" @click="load(true)">{{
              t('app.strategy.notes.retry')
            }}</v-btn>
          </template>
        </v-alert>

        <v-skeleton-loader
          v-if="loading && items.length === 0"
          type="card, card"
          data-test="creator-picker-skeleton"
        />
        <CEmptyState
          v-else-if="!loadError && items.length === 0"
          class="picker__empty"
          data-test="creator-picker-empty"
          title-tag="h3"
          :title="
            filtered
              ? t('app.strategy.creators.picker.emptyFilteredTitle')
              : t('app.strategy.creators.picker.emptyTitle')
          "
          :body="
            filtered
              ? t('app.strategy.creators.picker.emptyFilteredBody')
              : t('app.strategy.creators.picker.emptyBody')
          "
        >
          <template #icon>
            <v-icon icon="mdi-account-search-outline" size="44" aria-hidden="true" />
          </template>
          <template v-if="campaignId" #action>
            <v-btn
              color="primary"
              variant="flat"
              :to="{ name: 'campaigns.detail', params: { ulid: campaignId } }"
              data-test="creator-picker-campaign-link"
            >
              {{ t('app.strategy.creators.picker.openCampaign') }}
            </v-btn>
          </template>
        </CEmptyState>
        <div v-else class="picker__grid" role="group" :aria-label="title">
          <WaveCreatorCard
            v-for="c in items"
            :key="c.id"
            variant="picker"
            :candidate="c"
            :selected="isSelected(c)"
            :in-market-name="marketName(c.in_market)"
            @toggle="toggle(c)"
          />
        </div>
        <div v-if="page < lastPage" class="picker__more">
          <v-btn
            variant="outlined"
            size="small"
            :loading="loading"
            data-test="creator-picker-more"
            @click="load(false)"
          >
            {{ t('app.strategy.creators.picker.more') }}
          </v-btn>
        </div>
      </div>

      <div class="picker__foot">
        <div class="picker__sel">
          <span class="picker__count" data-test="creator-picker-selected">
            {{ t('app.strategy.creators.picker.selected', { count: selected.length }) }}
          </span>
          <span class="picker__names" data-test="creator-picker-names">{{ names }}</span>
          <div
            v-if="returning.length > 0"
            class="picker__returning"
            role="status"
            data-test="creator-picker-returning"
          >
            <p
              v-for="r in returning"
              :key="r.id"
              class="picker__returning-line"
              :data-test="`creator-picker-returning-${r.id}`"
            >
              {{ t('app.strategy.scripts.replace.returnBack', { name: r.name, count: r.back }) }}
              <template v-if="r.stay > 0">
                <br />{{ t('app.strategy.scripts.replace.returnStay', { count: r.stay }) }}
              </template>
            </p>
          </div>
          <v-radio-group
            v-if="askScripts"
            v-model="scriptsChoice"
            density="compact"
            hide-details
            class="picker__scripts"
            :label="
              t('app.strategy.scripts.replace.label', { count: scriptCount ?? 0 }, scriptCount ?? 0)
            "
            data-test="creator-picker-scripts"
          >
            <v-radio
              value="carry_over"
              :label="
                t(
                  'app.strategy.scripts.replace.carry',
                  { count: scriptCount ?? 0 },
                  scriptCount ?? 0,
                )
              "
              data-test="creator-picker-scripts-carry"
            />
            <v-radio
              value="archive"
              :label="
                t(
                  'app.strategy.scripts.replace.archive',
                  { count: scriptCount ?? 0 },
                  scriptCount ?? 0,
                )
              "
              data-test="creator-picker-scripts-archive"
            />
          </v-radio-group>
          <v-select
            v-if="!single"
            v-model="personaId"
            :items="personaItems"
            item-value="value"
            item-title="title"
            :label="t('app.strategy.creators.picker.assignPersona')"
            variant="outlined"
            density="compact"
            hide-details
            :menu-props="MENU_PROPS"
            class="picker__persona"
            data-test="creator-picker-persona"
          />
        </div>
        <div class="picker__cta">
          <p
            v-if="capText"
            class="picker__cap"
            :class="{ 'picker__cap--over': overCap || capLine.state === 'max' }"
            :data-state="capLine.state"
            role="status"
            data-test="creator-picker-cap"
          >
            {{ capText }}
          </p>
          <p v-if="submitError" class="picker__error" role="alert" data-test="creator-picker-error">
            {{ submitError }}
          </p>
          <div class="picker__buttons">
            <v-btn
              variant="outlined"
              :disabled="saving"
              data-test="creator-picker-cancel"
              @click="close"
            >
              {{ t('app.strategy.cancel') }}
            </v-btn>
            <v-btn
              color="primary"
              variant="flat"
              prepend-icon="mdi-account-plus-outline"
              :disabled="!canSubmit"
              :loading="saving"
              data-test="creator-picker-submit"
              @click="submit"
            >
              {{
                single
                  ? t('app.strategy.creators.picker.replaceWith')
                  : selected.length === 0
                    ? t('app.strategy.creators.add')
                    : t('app.strategy.creators.picker.add', { count: selected.length })
              }}
            </v-btn>
          </div>
        </div>
      </div>
    </v-card>
  </v-dialog>
</template>

<style scoped>
/* Frame 13:2572: modal 1180 wide on the dark surface, 1 px border, radius 14; header padding 22 x 24; body padding 0 24 20, gap 14;
   footer 72 high, padding 16 x 24, a 1 px line above. */
.picker {
  display: flex;
  flex-direction: column;
  max-height: calc(100dvh - 48px);
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 14px;
}
.picker__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  padding: 22px 24px;
}
.picker__title {
  margin: 0;
  font-size: 20px;
  font-weight: 600;
  line-height: 1.25;
  overflow-wrap: anywhere;
}
.picker__sub {
  margin: 4px 0 0;
  font-size: 13px;
  color: rgb(var(--v-theme-on-surface-variant));
  overflow-wrap: anywhere;
}
.picker__body {
  display: flex;
  flex-direction: column;
  gap: 14px;
  flex: 1 1 auto;
  min-height: 0;
  padding: 0 24px 20px;
  overflow-y: auto;
}
.picker__filters {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.picker__chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 10px 6px 12px;
  border: 1px solid color-mix(in srgb, rgb(var(--v-theme-primary)) 60%, transparent);
  border-radius: 8px;
  background: color-mix(in srgb, rgb(var(--v-theme-primary)) 8%, transparent);
  font-size: 13px;
  color: rgb(var(--v-theme-primary));
}
.picker__chip-label {
  color: rgb(var(--v-theme-on-surface-variant));
}
.picker__chip-x {
  display: inline-flex;
  padding: 2px;
  border: 0;
  background: none;
  color: inherit;
  cursor: pointer;
}
.picker__note {
  margin-left: auto;
  font-size: 12px;
  color: rgb(var(--v-theme-on-surface-variant));
}
/* Five columns of 214 at the frame width, fewer as the dialog narrows. */
.picker__grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
  gap: 14px;
}
.picker__more {
  display: flex;
  justify-content: center;
}
.picker__foot {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px 24px;
  padding: 16px 24px;
  border-top: 1px solid rgb(var(--v-theme-border-color));
  background: rgb(var(--v-theme-surface));
}
.picker__sel {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 16px;
  min-width: 0;
}
.picker__count {
  font-size: 14px;
  font-weight: 600;
}
.picker__scripts {
  flex: 1 1 320px;
  min-width: 0;
}
.picker__returning {
  flex: 1 1 100%;
  padding: 8px 10px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 6px;
  font-size: 12px;
}
.picker__returning-line {
  margin: 0;
  color: rgb(var(--v-theme-on-surface));
}
.picker__names {
  max-width: 320px;
  font-size: 13px;
  color: rgb(var(--v-theme-on-surface-variant));
  overflow-wrap: anywhere;
}
.picker__persona {
  width: 280px;
  max-width: 100%;
}
.picker__cta {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 6px;
}
.picker__buttons {
  display: flex;
  gap: 10px;
}
.picker__cap,
.picker__error {
  margin: 0;
  font-size: 12px;
}
.picker__cap {
  color: rgb(var(--v-theme-on-surface-variant));
}
.picker__cap--over,
.picker__error {
  color: rgb(var(--v-theme-error));
}
@media (max-width: 720px) {
  .picker__head,
  .picker__body,
  .picker__foot {
    padding-left: 14px;
    padding-right: 14px;
  }
  .picker__cta {
    align-items: stretch;
    width: 100%;
  }
  .picker__note {
    margin-left: 0;
  }
}
</style>
