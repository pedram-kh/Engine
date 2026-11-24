<script setup lang="ts">
/**
 * PL-1.1 — the New wave dialog, in two modes.
 *
 *  - Create: name, go-live date, markets, and one of three campaign choices (PL-1.2): none yet (sends
 *    `campaign_id: null`), an existing campaign for a wave of this brand that has no wave, or "create one now"
 *    (sends `new_campaign: {name}`; the server makes the campaign, its board and the link in one step).
 *  - Edit (`wave` given): the same fields. The campaign can be set, changed or removed until a creator is Selected
 *    or further (`link_locked`); after that it is a read-only name with the reason (the server answers 422
 *    `wave.campaign_link_locked` to any change).
 *
 * No design frame exists for this dialog; it is built from the design system's dialog and field styles.
 * Server codes map to strings; anything else is the generic save error.
 */
import {
  ApiError,
  countryInTwoMarketsMeta,
  marketHasNotesMeta,
  marketHasScriptsMeta,
  marketHasPersonasMeta,
} from '@catalyst/api-client'
import type { MarketHasNotesMeta, MarketHasScriptsMeta } from '@catalyst/api-client'
import type {
  PersonaCodesResult,
  UpdateWavePayload,
  WaveCampaignOption,
  WaveResource,
} from '@catalyst/api-client'
import { labelForCountryCode } from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { MAX_MARKETS, MENU_ABOVE_DIALOG_Z } from '../constants'
import { wavesApi } from '../api/waves.api'
import {
  draftsFromWave,
  emptyDraft,
  toInput,
  validateDrafts,
  type MarketDraft,
} from '../marketDrafts'
import MarketBuilder from './MarketBuilder.vue'

const props = defineProps<{
  modelValue: boolean
  agencyId: string
  brandId: string
  brandName: string
  wave?: WaveResource | null
  /** A1: offer "also update the codes of this market's personas" when a short code is changed (strategy is on). */
  offerPersonaCodes?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  /** `personaCodes` only when an edit asked for persona codes to follow a short-code change. */
  saved: [wave: WaveResource, personaCodes?: PersonaCodesResult]
  /** The markets were reloaded after a 409 (someone else changed them): the wave as the server has it now. */
  refreshed: [wave: WaveResource]
}>()

const { t, te } = useI18n()

/**
 * Menus inside this dialog. With the dialog in Vuetify's overlay stack a menu gets the top z-index plus 10; the
 * z-index here is the fallback for an empty stack, so a menu is never behind the dialog. The max height keeps
 * the list inside a short window.
 */
const menuAboveDialog = {
  zIndex: MENU_ABOVE_DIALOG_Z,
  maxHeight: 'calc(100dvh - 24px)',
  contentClass: 'wave-dialog-menu',
}

/**
 * Bumped every time the dialog opens or closes and used as the key of the card, so every menu inside it is
 * unmounted. A menu left open when the dialog closed used to survive a quick reopen: the dialog then took a
 * z-index above the stale menu (2420 against 2410), and the list opened behind the dialog.
 */
const session = ref(0)

const isEdit = computed(() => props.wave !== undefined && props.wave !== null)
const hasLink = computed(() => props.wave?.relationships.campaign != null)
// PL-1.2 (Q3): a link is editable until a card is Selected or further. A payload without the field (an API that
// predates it) falls back to the PL-1.1 rule: once linked, it is fixed.
const linkLocked = computed(() => props.wave?.attributes.link_locked ?? hasLink.value)

const name = ref('')
const goLiveOn = ref('')
const drafts = ref<MarketDraft[]>([])
/** The wave the markets list was built from: the prop on open, the server's copy after a reload (G5). */
const baseline = ref<WaveResource | null>(null)
/** The server refused the edit because the markets changed since the list was loaded (409). */
const staleMarkets = ref(false)
const reloading = ref(false)
const campaignId = ref<string | null>(null)
type CampaignChoice = 'none' | 'existing' | 'new'
const campaignChoice = ref<CampaignChoice>('none')
const newCampaignName = ref('')
const options = ref<WaveCampaignOption[]>([])
const saving = ref(false)
const submitted = ref(false)
const formError = ref<string | null>(null)
// PL-2.5: removing a market that carries internal notes asks first (422 `wave.market_has_notes`); the same save then
// goes again with the count the person saw.
const notesAsk = ref<MarketHasNotesMeta | null>(null)
const notesWarning = computed(() => {
  const a = notesAsk.value
  if (a === null) return ''
  return t(
    'app.waves.dialog.notesWarning',
    {
      markets: a.markets.map((m) => `${m.name} (${m.note_count})`).join(', '),
      count: a.note_count,
    },
    a.note_count,
  )
})

// PL-3.2a: the same for archived scripts (422 `wave.market_has_scripts`); both asks can stand together.
const scriptsAsk = ref<MarketHasScriptsMeta | null>(null)
const scriptsWarning = computed(() => {
  const a = scriptsAsk.value
  if (a === null) return ''
  return t(
    'app.waves.dialog.scriptsWarning',
    {
      markets: a.markets.map((m) => `${m.name} (${m.script_count})`).join(', '),
      count: a.script_count,
    },
    a.script_count,
  )
})

const nameError = computed(() =>
  submitted.value && name.value.trim() === '' ? t('app.waves.validation.nameRequired') : null,
)
const goLiveError = computed(() =>
  submitted.value && goLiveOn.value === '' ? t('app.waves.validation.goLiveRequired') : null,
)
const marketErrors = computed(() => (submitted.value ? validateDrafts(drafts.value) : {}))
const marketsError = computed(() => {
  if (!submitted.value) return null
  if (drafts.value.length === 0) return t('app.waves.validation.marketsRequired')
  if (drafts.value.length > MAX_MARKETS) return t('app.waves.validation.marketsMax')
  if (Object.keys(marketErrors.value).length > 0) return t('app.waves.markets.problems')
  return null
})

const newCampaignNameError = computed(() =>
  submitted.value &&
  !isEdit.value &&
  campaignChoice.value === 'new' &&
  newCampaignName.value.trim() === ''
    ? t('app.waves.validation.newCampaignNameRequired')
    : null,
)

const campaignOptionItems = computed(() => [
  ...options.value.map((o) => {
    const key = `app.campaigns.status.${o.attributes.status}`
    const status = te(key) ? t(key) : o.attributes.status
    return { value: o.id as string | null, title: `${o.attributes.name} · ${status}` }
  }),
])

const campaignItems = computed(() => {
  const linked = props.wave?.relationships.campaign?.data
  // The wave's own campaign is not in the server's list (it already has a wave): keep it selectable on edit.
  const own =
    isEdit.value && linked
      ? [{ value: linked.id as string | null, title: linked.attributes.name }]
      : []
  return [
    { value: null as string | null, title: t('app.waves.dialog.noCampaign') },
    ...own,
    ...campaignOptionItems.value,
  ]
})

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
    session.value++
    if (!open) return
    submitted.value = false
    formError.value = null
    saving.value = false
    name.value = props.wave?.attributes.name ?? ''
    goLiveOn.value = props.wave?.attributes.go_live_on ?? ''
    baseline.value = props.wave ?? null
    staleMarkets.value = false
    notesAsk.value = null
    scriptsAsk.value = null
    drafts.value = props.wave ? draftsFromWave(props.wave.attributes.markets) : [emptyDraft()]
    campaignId.value = props.wave?.relationships.campaign?.data.id ?? null
    campaignChoice.value = 'none'
    newCampaignName.value = ''
    void loadOptions()
  },
  { immediate: true },
)

watch(
  drafts,
  () => {
    notesAsk.value = null
    scriptsAsk.value = null
  },
  { deep: true },
)

const CODE_KEYS: Record<string, string> = {
  'wave.campaign_not_available': 'app.waves.errors.campaignNotAvailable',
  'wave.campaign_already_linked': 'app.waves.errors.campaignAlreadyLinked',
  'wave.campaign_link_locked': 'app.waves.errors.campaignLinkLocked',
  'wave.brand_archived': 'app.waves.errors.brandArchived',
  'wave.market_name_taken': 'app.waves.errors.marketNameTaken',
  'wave.market_code_taken': 'app.waves.errors.marketCodeTaken',
  'wave.market_unknown': 'app.waves.errors.marketUnknown',
  'wave.market_conflict': 'app.waves.errors.marketConflict',
}

function messageFor(err: unknown): string {
  // PL-2.1: removing a market that still has personas is refused with the markets and their counts in the meta.
  const personas = marketHasPersonasMeta(err)
  if (personas !== null) {
    const base = t('app.waves.errors.marketHasPersonas', {
      markets: personas.markets.map((m) => `${m.name ?? m.market} (${m.persona_count})`).join(', '),
    })
    // PL-2.3: the same markets, with their concept counts, when concepts use their personas.
    const withConcepts = personas.markets.filter((m) => (m.concept_count ?? 0) > 0)
    return withConcepts.length === 0
      ? base
      : `${base} ${t('app.waves.errors.marketHasConcepts', {
          markets: withConcepts.map((m) => `${m.name ?? m.market} (${m.concept_count})`).join(', '),
        })}`
  }
  // PL-3.1: removing a market that still has creators is refused with the markets and their counts in the meta.
  if (err instanceof ApiError && err.code === 'wave.market_has_creators') {
    const raw = err.details[0]?.meta?.['markets']
    const list = Array.isArray(raw)
      ? raw.flatMap((m: unknown) => {
          const r = m as { name?: unknown; market?: unknown; creator_count?: unknown }
          const label =
            typeof r.name === 'string' ? r.name : typeof r.market === 'string' ? r.market : ''
          return label === ''
            ? []
            : [`${label} (${typeof r.creator_count === 'number' ? r.creator_count : 0})`]
        })
      : []
    return t('app.waves.errors.marketHasCreators', { markets: list.join(', ') })
  }
  const twice = countryInTwoMarketsMeta(err)
  if (twice !== null) {
    return t('app.waves.errors.countryInTwoMarkets', {
      country: labelForCountry(twice.country),
      first: twice.markets[0] ?? '',
      second: twice.markets[1] ?? '',
    })
  }
  if (err instanceof ApiError) {
    const key = CODE_KEYS[err.code]
    if (key !== undefined) return t(key)
  }
  return t('app.waves.errors.saveFailed')
}

function labelForCountry(code: string): string {
  return labelForCountryCode(code)
}

/** The markets list as the server would hold it, to tell an edit from no change. Countries are compared sorted. */
function signature(
  list: { id?: string; name: string; code?: string | null; countries: string[] }[],
): string {
  return JSON.stringify(
    list.map((m) => [m.id ?? null, m.name, m.code ?? null, [...m.countries].sort()]),
  )
}

/** G5: take the markets as the server has them now, keep what else the user typed, and say the list was refreshed. */
async function reloadMarkets(): Promise<void> {
  if (!props.wave) return
  reloading.value = true
  try {
    const res = await wavesApi.show(props.agencyId, props.brandId, props.wave.id)
    baseline.value = res.data
    drafts.value = draftsFromWave(res.data.attributes.markets)
    staleMarkets.value = false
    formError.value = null
    submitted.value = false
    emit('refreshed', res.data)
  } catch (err) {
    formError.value = messageFor(err)
  } finally {
    reloading.value = false
  }
}

function close(): void {
  if (saving.value) return
  emit('update:modelValue', false)
}

async function submit(): Promise<void> {
  submitted.value = true
  formError.value = null
  staleMarkets.value = false
  if (nameError.value || goLiveError.value || marketsError.value || newCampaignNameError.value)
    return

  saving.value = true
  try {
    let res
    if (isEdit.value && props.wave) {
      const body: UpdateWavePayload = {}
      if (name.value.trim() !== props.wave.attributes.name) body.name = name.value.trim()
      if (goLiveOn.value !== props.wave.attributes.go_live_on) body.go_live_on = goLiveOn.value
      const was = baseline.value ?? props.wave
      const inputs = drafts.value.map(toInput)
      if (signature(inputs) !== signature(was.attributes.markets)) {
        // PL-2.4: the whole list, each existing market with its ulid; the version says what the list was built from.
        body.markets = inputs
        body.markets_version = was.attributes.markets_version
        if (notesAsk.value !== null) body.confirm_notes_deleted = notesAsk.value.note_count
        if (scriptsAsk.value !== null) body.confirm_scripts_deleted = scriptsAsk.value.script_count
      }
      // Set, change or remove (null) the link, but only when it differs from what is saved.
      const savedCampaignId = props.wave.relationships.campaign?.data.id ?? null
      if (!linkLocked.value && campaignId.value !== savedCampaignId)
        body.campaign_id = campaignId.value
      res = await wavesApi.update(props.agencyId, props.brandId, props.wave.id, body)
    } else {
      res = await wavesApi.create(props.agencyId, props.brandId, {
        name: name.value.trim(),
        go_live_on: goLiveOn.value,
        markets: drafts.value.map(toInput),
        ...(campaignChoice.value === 'new'
          ? { new_campaign: { name: newCampaignName.value.trim() } }
          : { campaign_id: campaignChoice.value === 'existing' ? campaignId.value : null }),
      })
    }
    const personaCodes = res.meta?.persona_codes
    if (personaCodes) emit('saved', res.data, personaCodes)
    else emit('saved', res.data)
    emit('update:modelValue', false)
  } catch (err) {
    const notes = marketHasNotesMeta(err)
    if (notes !== null) {
      notesAsk.value = notes
      return
    }
    const scripts = marketHasScriptsMeta(err)
    if (scripts !== null) {
      scriptsAsk.value = scripts
      return
    }
    if (err instanceof ApiError && err.code === 'wave.markets_changed') {
      staleMarkets.value = true
      formError.value = t('app.waves.errors.marketsChanged')
    } else {
      formError.value = messageFor(err)
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="640"
    persistent
    data-test="wave-dialog"
    @update:model-value="
      (v: boolean) => {
        if (!saving) emit('update:modelValue', v)
      }
    "
  >
    <v-card :key="session" class="wave-dialog-card">
      <div class="wave-dialog__head">
        <div class="wave-dialog__titles">
          <div class="wave-dialog__title">
            {{ isEdit ? t('app.waves.dialog.editTitle') : t('app.waves.dialog.createTitle') }}
          </div>
          <div class="wave-dialog__brand" data-test="wave-dialog-brand">{{ brandName }}</div>
        </div>
        <v-btn
          icon="mdi-close"
          variant="text"
          size="small"
          :disabled="saving"
          :aria-label="t('app.strategy.close')"
          data-test="wave-close"
          @click="close"
        />
      </div>
      <v-card-text class="d-flex flex-column ga-4 px-6 pt-0">
        <div class="wave-dialog__pair">
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
        </div>
        <div>
          <div class="wave-dialog__label">{{ t('app.waves.dialog.markets') }}</div>
          <MarketBuilder
            v-model="drafts"
            :errors="marketErrors"
            :error-message="marketsError"
            :offer-persona-codes="isEdit && offerPersonaCodes"
          />
        </div>

        <template v-if="linkLocked">
          <v-text-field
            :model-value="wave?.relationships.campaign?.data.attributes.name"
            :label="t('app.waves.dialog.campaign')"
            :hint="t('app.waves.dialog.campaignLocked')"
            persistent-hint
            variant="outlined"
            density="comfortable"
            readonly
            data-test="wave-campaign-locked"
          />
        </template>
        <template v-else-if="isEdit">
          <v-select
            v-model="campaignId"
            :items="campaignItems"
            item-title="title"
            item-value="value"
            :label="t('app.waves.dialog.campaign')"
            :hint="t('app.waves.dialog.campaignHint')"
            persistent-hint
            variant="outlined"
            density="comfortable"
            :menu-props="menuAboveDialog"
            data-test="wave-campaign"
          />
        </template>
        <template v-else>
          <v-radio-group
            v-model="campaignChoice"
            :label="t('app.waves.dialog.campaign')"
            density="compact"
            hide-details
            data-test="wave-campaign-choice"
          >
            <v-radio
              value="none"
              color="primary"
              :label="t('app.waves.dialog.choice.none')"
              data-test="wave-choice-none"
            />
            <v-radio
              value="existing"
              color="primary"
              :label="t('app.waves.dialog.choice.existing')"
              data-test="wave-choice-existing"
            />
            <v-radio
              value="new"
              color="primary"
              :label="t('app.waves.dialog.choice.new')"
              data-test="wave-choice-new"
            />
          </v-radio-group>
          <v-select
            v-if="campaignChoice === 'existing'"
            v-model="campaignId"
            :items="campaignOptionItems"
            item-title="title"
            item-value="value"
            :label="t('app.waves.dialog.campaign')"
            :hint="t('app.waves.dialog.campaignHint')"
            persistent-hint
            variant="outlined"
            density="comfortable"
            :menu-props="menuAboveDialog"
            data-test="wave-campaign"
          />
          <v-text-field
            v-else-if="campaignChoice === 'new'"
            v-model="newCampaignName"
            :label="t('app.waves.dialog.newCampaignName')"
            :hint="t('app.waves.dialog.newCampaignHint')"
            persistent-hint
            variant="outlined"
            density="comfortable"
            maxlength="255"
            :error-messages="newCampaignNameError ? [newCampaignNameError] : []"
            data-test="wave-new-campaign-name"
          />
        </template>

        <v-alert v-if="notesAsk" type="warning" variant="tonal" data-test="wave-notes-warning">
          {{ notesWarning }}
        </v-alert>
        <v-alert v-if="scriptsAsk" type="warning" variant="tonal" data-test="wave-scripts-warning">
          {{ scriptsWarning }}
        </v-alert>
        <v-alert v-if="formError" type="error" variant="tonal" data-test="wave-form-error">
          {{ formError }}
          <template v-if="staleMarkets" #append>
            <v-btn
              variant="outlined"
              size="small"
              :loading="reloading"
              data-test="wave-markets-reload"
              @click="reloadMarkets"
            >
              {{ t('app.waves.markets.reload') }}
            </v-btn>
          </template>
        </v-alert>
      </v-card-text>
      <div class="wave-dialog__foot">
        <v-btn variant="outlined" :disabled="saving" data-test="wave-cancel" @click="close">
          {{ t('app.brands.archive.cancel') }}
        </v-btn>
        <v-btn
          :color="notesAsk || scriptsAsk ? 'error' : 'primary'"
          variant="flat"
          :loading="saving"
          data-test="wave-submit"
          @click="submit"
        >
          {{
            scriptsAsk
              ? t('app.waves.dialog.scriptsConfirm')
              : notesAsk
                ? t('app.waves.dialog.notesConfirm')
                : isEdit
                  ? t('app.waves.dialog.save')
                  : t('app.waves.dialog.create')
          }}
        </v-btn>
      </div>
    </v-card>
  </v-dialog>
</template>

<style scoped>
/* Frame 13:4000: a header with the title (20 / 600), the brand (13) and a close button, name and date side by side,
   and a footer bar (the surface colour, a top border, 16 / 24 padding, 10 between the buttons). The field style itself
   (Vuetify outlined with a floating label), the card radius and shadow, and the secondary button's border are the app's
   design-system tokens, shared by every dialog and form (see pl-2-4-review.md). */
.wave-dialog-card {
  overflow: hidden;
}
.wave-dialog__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 22px 24px;
}
.wave-dialog__titles {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.wave-dialog__head :deep(.v-btn .v-icon) {
  font-size: 22px;
}
.wave-dialog__title {
  font-size: 20px;
  font-weight: 600;
  line-height: normal;
  white-space: normal;
  overflow-wrap: anywhere;
}
.wave-dialog__brand {
  font-size: 13px;
  line-height: normal;
  opacity: 1;
  color: rgb(var(--v-theme-on-surface-variant));
  white-space: normal;
  overflow-wrap: anywhere;
}
.wave-dialog__pair {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
}
.wave-dialog__label {
  margin-bottom: 6px;
  font-size: 12px;
  font-weight: 500;
  color: rgb(var(--v-theme-on-surface-variant));
}
.wave-dialog__foot {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 10px;
  padding: 16px 24px;
  background: rgb(var(--v-theme-surface));
  border-top: 1px solid rgb(var(--v-theme-border-color));
}
@media (max-width: 559px) {
  .wave-dialog__pair {
    grid-template-columns: 1fr;
  }
}
</style>

<style>
/* Teleported select menus. The class exists only while a menu in this dialog is open. */
.wave-dialog-menu {
  max-height: calc(100dvh - 24px);
  overflow-y: auto;
}
</style>
