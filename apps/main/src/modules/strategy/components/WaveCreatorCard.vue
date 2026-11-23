<script setup lang="ts">
/**
 * PL-3.1 — the creator card of the Creators step (frame 13:1953, card 13:2178) and its compact variant for the picker (frame
 * 13:2572). One component, two variants, so a creator looks the same in both.
 *
 * `slot` variant: photo with the slot code (SK-C1, editable), name, the verified tick, region and "Example content" only when
 * the data holds them, languages, category tags, the persona select, the DERIVED status (a pill, never a control: it follows the
 * campaign), the client's latest decision, and Profile / Replace / Remove. A declined creator is drawn at 60% opacity. An erased
 * creator is "Deleted creator" with no photo and no personal field.
 *
 * `picker` variant: 214 px card, 150 px photo, name, country and age, language, tags and "+n", a selection ring and tick, and the
 * "Already in wave" chip (naming the market) on a card that cannot be picked.
 *
 * Names, regions, codes, the client's name and comment are rendered as text interpolations only (never as markup).
 */
import type {
  WaveCreatorCandidate,
  WaveCreatorCardData,
  WaveCreatorSlot,
  WavePersonaResource,
} from '@catalyst/api-client'
import { labelForCountryCode, languageEndonym } from '@catalyst/api-client'
import { computed, nextTick, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { creatorAvatarUrl, creatorInitial, creatorLabel } from '@/core/creators/creatorLabel'
import { brandColorVar } from '@/modules/agency/composables/brandColor'
import AgencyStatusPill from '@/modules/agency/components/AgencyStatusPill.vue'
import { MENU_PROPS } from '../constants'
import { statusTone } from '../creatorsLogic'

const props = withDefaults(
  defineProps<{
    variant?: 'slot' | 'picker'
    // slot variant
    row?: WaveCreatorSlot
    personas?: WavePersonaResource[]
    busy?: boolean
    canManage?: boolean
    canDecide?: boolean
    // picker variant
    candidate?: WaveCreatorCandidate
    selected?: boolean
    /** The name of the market a candidate is already in. */
    inMarketName?: string
  }>(),
  {
    variant: 'slot',
    row: undefined,
    personas: () => [],
    busy: false,
    canManage: false,
    canDecide: false,
    candidate: undefined,
    selected: false,
    inMarketName: '',
  },
)
const emit = defineEmits<{
  persona: [personaId: string | null]
  'slot-code': [code: string]
  profile: []
  replace: []
  remove: []
  decide: []
  history: []
  toggle: []
}>()

const { t, te } = useI18n()

const creator = computed<WaveCreatorCardData | null>(() =>
  props.variant === 'picker' ? (props.candidate ?? null) : (props.row?.creator ?? null),
)
const name = computed(() => creatorLabel(creator.value, t, t('app.strategy.creators.card.unnamed')))
const avatar = computed(() => creatorAvatarUrl(creator.value))
const initial = computed(() => creatorInitial(creator.value))
const erased = computed(() => creator.value?.is_erased === true)

const country = computed(() => {
  const code = creator.value?.country_code
  return code ? labelForCountryCode(code) : null
})
const location = computed(() => {
  const region = creator.value?.region?.trim()
  const parts = [region ? region : null, country.value].filter((p): p is string => p !== null)
  return parts.length === 0 ? null : parts.join(', ')
})
const languages = computed(() => {
  const c = creator.value
  if (c === null || c.primary_language === null) return null
  const codes = [c.primary_language, ...c.secondary_languages]
  return codes.map((code) => languageEndonym(code)).join(' · ')
})
const categories = computed(() =>
  (creator.value?.categories ?? []).map((cat) => t(`creator.ui.wizard.categories.${cat}`, cat)),
)
const MAX_TAGS = 2
const tags = computed(() => categories.value.slice(0, MAX_TAGS))
const moreTags = computed(() => Math.max(0, categories.value.length - MAX_TAGS))
const exampleUrl = computed(() => {
  const u = creator.value?.example_content_url
  return u !== null && u !== undefined && /^https?:\/\//i.test(u) ? u : null
})

// slot variant --------------------------------------------------------------------------------------------------------
const declined = computed(() => props.row?.status === 'declined')
const persona = computed(() => props.row?.persona ?? null)
const personaItems = computed(() => [
  { value: null as string | null, title: t('app.strategy.creators.card.notAssigned'), code: '' },
  ...props.personas.map((p) => ({
    value: p.id as string | null,
    title: p.attributes.title,
    code: p.attributes.code,
  })),
])
const personaColor = computed(() => {
  const full = props.personas.find((p) => p.id === persona.value?.id)
  return full === undefined
    ? 'var(--v-theme-on-surface-variant)'
    : brandColorVar(full.attributes.color_key)
})
const decision = computed(() => props.row?.decision ?? null)

const editing = ref(false)
const draftCode = ref('')
const codeInput = ref<HTMLInputElement | null>(null)
async function startEdit(): Promise<void> {
  if (!props.canManage || props.busy) return
  draftCode.value = props.row?.slot_code ?? ''
  editing.value = true
  await nextTick()
  codeInput.value?.focus()
  codeInput.value?.select()
}
function commitCode(): void {
  if (!editing.value) return
  editing.value = false
  const next = draftCode.value.trim().toUpperCase()
  if (next !== '' && next !== props.row?.slot_code) emit('slot-code', next)
}
function cancelCode(): void {
  editing.value = false
}

/** The short reason under the pill; statuses whose pill already says it (accepted, declined, no source) have no line. */
const detailText = computed(() => {
  const key = `app.strategy.creators.detail.${props.row?.detail ?? ''}`
  return te(key) ? t(key) : ''
})
const sameNameTitle = computed(() => t('app.strategy.creators.card.statusHint'))
const stamp = (iso: string): string => new Date(iso).toLocaleDateString()
</script>

<template>
  <article
    v-if="variant === 'slot' && row"
    class="wcc"
    :class="{ 'wcc--declined': declined, 'wcc--busy': busy }"
    :data-test="`creator-card-${row.id}`"
    :data-status="row.status"
  >
    <div class="wcc__photo">
      <img v-if="avatar" class="wcc__img" :src="avatar" :alt="name" loading="lazy" />
      <span v-else class="wcc__initial" aria-hidden="true">{{ initial }}</span>
      <div class="wcc__code" :data-test="`creator-slot-code-${row.id}`">
        <input
          v-if="editing"
          ref="codeInput"
          v-model="draftCode"
          class="wcc__code-input"
          maxlength="10"
          :aria-label="t('app.strategy.creators.card.slotCode')"
          :data-test="`creator-slot-code-input-${row.id}`"
          @keydown.enter.prevent="commitCode"
          @keydown.esc.prevent="cancelCode"
          @blur="commitCode"
        />
        <button
          v-else
          type="button"
          class="wcc__code-btn"
          :disabled="!canManage || busy"
          :title="canManage ? t('app.strategy.creators.card.editCode') : undefined"
          :data-test="`creator-slot-code-edit-${row.id}`"
          @click="startEdit"
        >
          {{ row.slot_code }}
        </button>
      </div>
    </div>

    <div class="wcc__body">
      <div class="wcc__namerow">
        <span
          class="wcc__name"
          :class="{ 'wcc__name--muted': declined }"
          :data-test="`creator-name-${row.id}`"
          >{{ name }}</span
        >
        <v-icon
          v-if="creator?.verified"
          icon="mdi-check-decagram"
          size="15"
          color="success"
          :aria-label="t('app.strategy.creators.card.verified')"
          :data-test="`creator-verified-${row.id}`"
        />
      </div>

      <div v-if="location || languages" class="wcc__meta">
        <span v-if="location" class="wcc__meta-item" :data-test="`creator-location-${row.id}`">
          <v-icon icon="mdi-map-marker-outline" size="13" aria-hidden="true" />{{ location }}
        </span>
        <span v-if="languages" class="wcc__meta-item" :data-test="`creator-languages-${row.id}`">
          <v-icon icon="mdi-translate" size="13" aria-hidden="true" />{{ languages }}
        </span>
      </div>

      <div v-if="tags.length > 0 || exampleUrl" class="wcc__tags">
        <span v-for="tag in tags" :key="tag" class="wcc__tag">{{ tag }}</span>
        <span v-if="moreTags > 0" class="wcc__tag">+{{ moreTags }}</span>
        <a
          v-if="exampleUrl"
          class="wcc__example"
          :href="exampleUrl"
          target="_blank"
          rel="noopener noreferrer"
          :data-test="`creator-example-${row.id}`"
        >
          <v-icon icon="mdi-open-in-new" size="12" aria-hidden="true" />{{
            t('app.strategy.creators.card.example')
          }}
        </a>
      </div>

      <div class="wcc__label">{{ t('app.strategy.creators.card.persona') }}</div>
      <v-select
        :model-value="persona?.id ?? null"
        :items="personaItems"
        item-value="value"
        item-title="title"
        variant="outlined"
        density="compact"
        hide-details
        :disabled="!canManage || busy || erased"
        :menu-props="MENU_PROPS"
        class="wcc__persona"
        :class="{ 'wcc__persona--set': persona !== null }"
        :style="{ '--usp-color': personaColor }"
        :aria-label="t('app.strategy.creators.card.persona')"
        :data-test="`creator-persona-${row.id}`"
        @update:model-value="(v: string | null) => emit('persona', v)"
      >
        <template #selection="{ item }">
          <span class="wcc__persona-sel">
            <span v-if="item.raw.code" class="wcc__persona-code">{{ item.raw.code }}</span>
            <span class="wcc__persona-title">{{ item.raw.title }}</span>
          </span>
        </template>
        <template #item="{ item, props: itemProps }">
          <v-list-item v-bind="itemProps" :title="undefined">
            <span class="wcc__persona-sel">
              <span v-if="item.raw.code" class="wcc__persona-code">{{ item.raw.code }}</span>
              <span>{{ item.raw.title }}</span>
            </span>
          </v-list-item>
        </template>
      </v-select>

      <div class="wcc__label">{{ t('app.strategy.creators.card.status') }}</div>
      <div class="wcc__status" :title="sameNameTitle">
        <AgencyStatusPill
          :tone="statusTone(row.status)"
          :label="t(`app.strategy.creators.status.${row.status}`)"
          :data-test="`creator-status-${row.id}`"
        />
        <span class="wcc__detail" :data-test="`creator-detail-${row.id}`">{{ detailText }}</span>
      </div>
      <p class="wcc__hint">{{ sameNameTitle }}</p>

      <div v-if="decision" class="wcc__decision" :data-test="`creator-decision-${row.id}`">
        <div class="wcc__decision-head">
          <v-icon
            :icon="
              decision.status === 'approved' ? 'mdi-check-circle-outline' : 'mdi-swap-horizontal'
            "
            size="14"
            :color="decision.status === 'approved' ? 'success' : 'warning'"
            aria-hidden="true"
          />
          <span :data-test="`creator-decision-status-${row.id}`">{{
            t(`app.strategy.creators.decision.status.${decision.status}`, {
              name: decision.client_name,
            })
          }}</span>
        </div>
        <p v-if="decision.comment || decision.note" class="wcc__decision-text">
          {{ decision.comment ?? decision.note }}
        </p>
        <span class="wcc__decision-meta">{{ stamp(decision.recorded_at) }}</span>
      </div>

      <div v-if="canDecide || row.decision_count > 0" class="wcc__decide">
        <button
          v-if="canDecide"
          type="button"
          class="wcc__link"
          :disabled="busy || erased"
          :data-test="`creator-decide-${row.id}`"
          @click="emit('decide')"
        >
          {{ t('app.strategy.creators.decision.record') }}
        </button>
        <button
          v-if="row.decision_count > 0"
          type="button"
          class="wcc__link"
          :data-test="`creator-history-${row.id}`"
          @click="emit('history')"
        >
          {{ t('app.strategy.creators.decision.history', { count: row.decision_count }) }}
        </button>
      </div>

      <div class="wcc__actions">
        <button
          type="button"
          class="wcc__action"
          :disabled="erased"
          :data-test="`creator-profile-${row.id}`"
          @click="emit('profile')"
        >
          <v-icon icon="mdi-eye-outline" size="13" aria-hidden="true" />{{
            t('app.strategy.creators.card.profile')
          }}
        </button>
        <button
          v-if="canManage"
          type="button"
          class="wcc__action"
          :disabled="busy"
          :data-test="`creator-replace-${row.id}`"
          @click="emit('replace')"
        >
          <v-icon icon="mdi-swap-horizontal" size="13" aria-hidden="true" />{{
            t('app.strategy.creators.card.replace')
          }}
        </button>
        <button
          v-if="canManage"
          type="button"
          class="wcc__action"
          :disabled="busy"
          :data-test="`creator-remove-${row.id}`"
          @click="emit('remove')"
        >
          <v-icon icon="mdi-trash-can-outline" size="13" aria-hidden="true" />{{
            t('app.strategy.creators.card.remove')
          }}
        </button>
      </div>
    </div>
  </article>

  <article
    v-else-if="variant === 'picker' && candidate"
    class="wcp"
    :class="{
      'wcp--selected': selected,
      'wcp--blocked': candidate.already_in_wave,
    }"
    :role="candidate.already_in_wave ? undefined : 'checkbox'"
    :aria-checked="candidate.already_in_wave ? undefined : selected"
    :aria-disabled="candidate.already_in_wave ? true : undefined"
    :tabindex="candidate.already_in_wave ? -1 : 0"
    :aria-label="name"
    :data-test="`picker-card-${candidate.id}`"
    :data-state="candidate.already_in_wave ? 'in-wave' : selected ? 'selected' : 'free'"
    @click="!candidate.already_in_wave && emit('toggle')"
    @keydown.enter.self.prevent="!candidate.already_in_wave && emit('toggle')"
    @keydown.space.self.prevent="!candidate.already_in_wave && emit('toggle')"
  >
    <div class="wcp__photo">
      <img v-if="avatar" class="wcc__img" :src="avatar" :alt="name" loading="lazy" />
      <span v-else class="wcc__initial" aria-hidden="true">{{ initial }}</span>
      <span
        v-if="candidate.already_in_wave"
        class="wcp__chip"
        :data-test="`picker-in-wave-${candidate.id}`"
      >
        {{
          inMarketName !== ''
            ? t('app.strategy.creators.picker.alreadyInMarket', { market: inMarketName })
            : t('app.strategy.creators.picker.alreadyInWave')
        }}
      </span>
      <span v-else-if="selected" class="wcp__tick" aria-hidden="true">
        <v-icon icon="mdi-check" size="14" />
      </span>
    </div>
    <div class="wcp__body">
      <div class="wcc__namerow">
        <span class="wcp__name">{{ name }}</span>
        <v-icon v-if="creator?.verified" icon="mdi-check-decagram" size="14" color="success" />
      </div>
      <div v-if="country || creator?.age" class="wcp__line">
        <v-icon icon="mdi-map-marker-outline" size="12" aria-hidden="true" />
        <span
          >{{ country }}<template v-if="country && creator?.age"> · </template
          >{{ creator?.age }}</span
        >
      </div>
      <div v-if="languages" class="wcp__line">
        <v-icon icon="mdi-translate" size="12" aria-hidden="true" /><span>{{ languages }}</span>
      </div>
      <div v-if="tags.length > 0" class="wcc__tags">
        <span v-for="tag in tags" :key="tag" class="wcc__tag wcc__tag--sm">{{ tag }}</span>
        <span v-if="moreTags > 0" class="wcp__more">+{{ moreTags }}</span>
      </div>
      <div class="wcp__status">
        <AgencyStatusPill
          :tone="statusTone(candidate.status)"
          :label="t(`app.strategy.creators.status.${candidate.status}`)"
          :data-test="`picker-status-${candidate.id}`"
        />
      </div>
      <div class="wcp__source">
        <span v-if="candidate.applied" class="wcp__src">{{
          t('app.strategy.creators.picker.applied')
        }}</span>
        <span v-if="candidate.invited" class="wcp__src">{{
          t('app.strategy.creators.picker.invited')
        }}</span>
      </div>
    </div>
  </article>
</template>

<style scoped>
/* Frame 13:2178: card 291 wide on the surface fill, 1 px border, radius 10; photo 200; body padding 16, gap 10. */
.wcc {
  display: flex;
  flex-direction: column;
  min-width: 0;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 10px;
  background: rgb(var(--v-theme-surface));
  overflow: hidden;
}
.wcc--declined {
  opacity: 0.6;
}
.wcc--busy {
  pointer-events: none;
  opacity: 0.8;
}
.wcc__photo,
.wcp__photo {
  position: relative;
  display: flex;
  align-items: flex-end;
  background: linear-gradient(
    145deg,
    color-mix(in srgb, rgb(var(--v-theme-primary)) 35%, rgb(var(--v-theme-surface-bright))) 0%,
    rgb(var(--v-theme-surface-bright)) 100%
  );
  overflow: hidden;
}
.wcc__photo {
  height: 200px;
}
.wcc__img {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.wcc__initial {
  margin: 0 0 8px 14px;
  font-size: 56px;
  font-weight: 600;
  line-height: 1;
  color: rgb(var(--v-theme-on-surface) / 0.18);
}
/* Slot code chip: 12/700 on the page-black fill, padding 3 x 8, radius 6, top-left 12 / 12. */
.wcc__code {
  position: absolute;
  top: 12px;
  left: 12px;
}
.wcc__code-btn,
.wcc__code-input {
  padding: 3px 8px;
  border: 0;
  border-radius: 6px;
  background: rgb(var(--v-theme-background));
  color: rgb(var(--v-theme-on-surface));
  font: inherit;
  font-size: 12px;
  font-weight: 700;
  line-height: 1.3;
}
.wcc__code-btn:not(:disabled) {
  cursor: text;
}
.wcc__code-btn:focus-visible,
.wcc__code-input:focus {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 1px;
}
.wcc__code-input {
  width: 88px;
  text-transform: uppercase;
}
.wcc__body {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 16px;
  min-width: 0;
}
.wcc__namerow {
  display: flex;
  align-items: center;
  gap: 6px;
  min-width: 0;
}
.wcc__name {
  font-size: 16px;
  font-weight: 600;
  line-height: 1.25;
  color: rgb(var(--v-theme-on-surface));
  overflow-wrap: anywhere;
}
.wcc__name--muted {
  color: rgb(var(--v-theme-on-surface-variant));
}
.wcc__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 12px;
  font-size: 12px;
  color: rgb(var(--v-theme-on-surface-variant));
}
.wcc__meta-item {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  min-width: 0;
}
.wcc__tags {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
}
.wcc__tag {
  padding: 2px 8px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 6px;
  font-size: 11px;
  color: rgb(var(--v-theme-on-surface));
}
.wcc__tag--sm {
  padding: 2px 7px;
  border-radius: 5px;
}
.wcc__example {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  margin-left: 4px;
  font-size: 12px;
  font-weight: 500;
  color: rgb(var(--v-theme-primary));
  text-decoration: none;
}
.wcc__example:hover {
  text-decoration: underline;
}
.wcc__label {
  margin-top: 2px;
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.6px;
  text-transform: uppercase;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
}
.wcc__persona {
  --usp-color: rgb(var(--v-theme-on-surface-variant));
}
.wcc__persona :deep(.v-field) {
  border-radius: 8px;
  font-size: 13px;
  font-weight: 500;
  --v-field-padding-top: 0;
  --v-field-padding-bottom: 0;
}
.wcc__persona--set :deep(.v-field) {
  background: color-mix(in srgb, var(--usp-color) 8%, transparent);
}
.wcc__persona--set :deep(.v-field__outline) {
  color: color-mix(in srgb, var(--usp-color) 60%, transparent);
}
.wcc__persona-sel {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}
.wcc__persona-code {
  flex: none;
  padding: 1px 5px;
  border: 1px solid color-mix(in srgb, var(--usp-color) 70%, transparent);
  border-radius: 4px;
  font-size: 10px;
  font-weight: 700;
  color: var(--usp-color);
}
.wcc__persona-title {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.wcc__status {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}
.wcc__detail {
  font-size: 12px;
  color: rgb(var(--v-theme-on-surface-variant));
}
.wcc__hint {
  margin: -4px 0 0;
  font-size: 11px;
  line-height: 1.35;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
}
.wcc__decision {
  padding: 8px 10px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 8px;
  font-size: 12px;
}
.wcc__decision-head {
  display: flex;
  align-items: center;
  gap: 6px;
  font-weight: 600;
  overflow-wrap: anywhere;
}
.wcc__decision-text {
  margin: 4px 0 0;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  color: rgb(var(--v-theme-on-surface-variant));
}
.wcc__decision-meta {
  display: block;
  margin-top: 4px;
  font-size: 11px;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
}
.wcc__decide {
  display: flex;
  gap: 14px;
}
.wcc__link {
  padding: 0;
  border: 0;
  background: none;
  font-size: 12px;
  font-weight: 500;
  color: rgb(var(--v-theme-primary));
  cursor: pointer;
}
.wcc__link:disabled {
  opacity: 0.5;
  cursor: default;
}
/* Action row: a 1 px line above, padding-top 12, gap 14, 12/400 on-surface-variant, icon gap 5. */
.wcc__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 14px;
  padding-top: 12px;
  border-top: 1px solid rgb(var(--v-theme-border-color));
}
.wcc__action {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 0;
  border: 0;
  background: none;
  font-size: 12px;
  color: rgb(var(--v-theme-on-surface-variant));
  cursor: pointer;
}
.wcc__action:hover:not(:disabled) {
  color: rgb(var(--v-theme-on-surface));
}
.wcc__action:disabled {
  opacity: 0.5;
  cursor: default;
}

/* Frame 13:2572 card: 214 wide, photo 150, radius 10, info padding 12 14 14, gap 7; selected 2 px primary ring. */
.wcp {
  position: relative;
  display: flex;
  flex-direction: column;
  min-width: 0;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 10px;
  background: rgb(var(--v-theme-surface));
  overflow: hidden;
  cursor: pointer;
  outline: none;
}
.wcp:focus-visible {
  box-shadow: 0 0 0 2px rgb(var(--v-theme-primary));
}
.wcp--selected {
  border: 2px solid rgb(var(--v-theme-primary));
}
.wcp--blocked {
  opacity: 0.5;
  cursor: default;
}
.wcp__photo {
  height: 150px;
}
.wcp__chip {
  position: absolute;
  top: 10px;
  left: 10px;
  max-width: calc(100% - 20px);
  padding: 3px 8px;
  border-radius: 6px;
  background: rgb(var(--v-theme-background));
  font-size: 11px;
  font-weight: 500;
  color: rgb(var(--v-theme-on-surface-variant));
  overflow-wrap: anywhere;
}
.wcp__tick {
  position: absolute;
  top: 10px;
  right: 10px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  border-radius: 9999px;
  background: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-primary));
}
.wcp__body {
  display: flex;
  flex-direction: column;
  gap: 7px;
  padding: 12px 14px 14px;
  min-width: 0;
}
.wcp__name {
  font-size: 14px;
  font-weight: 600;
  color: rgb(var(--v-theme-on-surface));
  overflow-wrap: anywhere;
}
.wcp__line {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  color: rgb(var(--v-theme-on-surface-variant));
}
.wcp__more {
  font-size: 11px;
  color: rgb(var(--v-theme-on-surface-variant));
}
.wcp__source {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
}
.wcp__status {
  display: flex;
}
/* The origin (how the creator joined the campaign) is secondary to the status pill: small, neutral. */
.wcp__src {
  padding: 1px 6px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 5px;
  font-size: 10.5px;
  color: rgb(var(--v-theme-on-surface-variant));
}
</style>
