<script setup lang="ts">
/**
 * PL-2.3 — one concept card (frame 13:4412): a header tinted with the persona's colour (the "CONCEPT n" chip, the status
 * pill, the menu, the title, the persona, funnel and USP chips), the body fields (an empty field is not drawn; Hook,
 * Payoff and CTA sit in the yellow box; key points are a bullet list), the references, and a footer for the client's
 * decision (green for Approved, red with the comment for Changes requested). Every string is drawn by interpolation:
 * card text, the client name and the comment are plain text, never HTML.
 */
import type { FunnelStage, StrategyLimits, WaveConceptResource } from '@catalyst/api-client'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { brandColorVar } from '@/modules/agency/composables/brandColor'
import type { WaveScope } from '../api/strategy.api'
import { FUNNEL_STAGES, initialsOf } from '../conceptsLogic'
import { MENU_PROPS } from '../constants'
import '../matrixColors.css'
import ConceptReferences from './ConceptReferences.vue'

const props = defineProps<{
  concept: WaveConceptResource
  scope: WaveScope
  limits: StrategyLimits
  brandName: string
  /** Admin or manager: records a decision and deletes a concept that is not Awaiting. */
  canDecide: boolean
  busy: boolean
}>()

const emit = defineEmits<{
  edit: [concept: WaveConceptResource]
  decide: [concept: WaveConceptResource]
  duplicate: [concept: WaveConceptResource]
  remove: [concept: WaveConceptResource]
  stage: [concept: WaveConceptResource, stage: FunnelStage | null]
  changed: []
}>()

const { t, d } = useI18n()
const a = computed(() => props.concept.attributes)
const menuOpen = ref(false)
const stageOpen = ref(false)

/** A decided concept is deleted by admin or manager only (the server decides again under its lock). */
const deleteBlocked = computed(() => a.value.status !== 'awaiting' && !props.canDecide)

const textFields = computed(() => {
  const x = a.value
  const rows: { key: string; label: string; value: string; boxed: boolean }[] = []
  const add = (key: string, value: string | null, boxed = false): void => {
    if (value !== null && value.trim() !== '')
      rows.push({ key, label: t(`app.strategy.concepts.field.${key}`), value, boxed })
  }
  add('product', x.product)
  add('angle', x.angle)
  add('primary_message', x.primary_message)
  add('hypothesis', x.hypothesis)
  add('hook', x.hook, true)
  add('format_visual', x.format_visual)
  // key points are drawn as a list below; the position of the row is kept by `key`
  if (x.key_points.length > 0) rows.push({ key: 'key_points', label: '', value: '', boxed: false })
  add('payoff', x.payoff, true)
  add('cta', x.cta, true)
  return rows
})

const decidedOn = computed(() => {
  const dec = a.value.decision
  return dec === null ? '' : d(new Date(dec.recorded_at), 'short')
})

function pickStage(stage: FunnelStage | null): void {
  stageOpen.value = false
  if (stage !== a.value.funnel_stage) emit('stage', props.concept, stage)
}
function pick(action: 'edit' | 'decide' | 'duplicate' | 'remove'): void {
  menuOpen.value = false
  if (action === 'edit') emit('edit', props.concept)
  else if (action === 'decide') emit('decide', props.concept)
  else if (action === 'duplicate') emit('duplicate', props.concept)
  else emit('remove', props.concept)
}
</script>

<template>
  <article
    class="concept-card"
    :class="`concept-card--${a.status}`"
    :style="{ '--persona-color': brandColorVar(a.persona.color_key) }"
    :aria-busy="busy"
    :data-test="`concept-card-${concept.id}`"
    :data-status="a.status"
  >
    <header class="concept-card__head">
      <div class="concept-card__row">
        <span class="concept-card__chip" data-test="concept-number">
          {{ t('app.strategy.concepts.card.number', { n: a.number }) }}
        </span>
        <span
          class="concept-card__pill"
          :class="`concept-card__pill--${a.status}`"
          data-test="concept-status"
        >
          <span class="concept-card__dot" aria-hidden="true" />
          {{ t(`app.strategy.concepts.status.${a.status}`) }}
        </span>
        <v-spacer />
        <v-progress-circular v-if="busy" indeterminate size="16" width="2" class="mr-2" />
        <v-menu v-model="menuOpen" location="bottom end" v-bind="MENU_PROPS">
          <template #activator="{ props: act }">
            <v-btn
              v-bind="act"
              icon="mdi-dots-horizontal"
              variant="text"
              size="small"
              density="comfortable"
              :disabled="busy"
              :aria-label="t('app.strategy.concepts.card.menu', { n: a.number })"
              data-test="concept-menu"
            />
          </template>
          <v-list density="compact" data-test="concept-menu-list">
            <v-list-item
              prepend-icon="mdi-pencil-outline"
              :title="t('app.strategy.concepts.menu.edit')"
              data-test="concept-menu-edit"
              @click="pick('edit')"
            />
            <v-list-item
              v-if="canDecide"
              prepend-icon="mdi-check-decagram-outline"
              :title="t('app.strategy.concepts.menu.decide')"
              data-test="concept-menu-decide"
              @click="pick('decide')"
            />
            <v-list-item
              prepend-icon="mdi-content-copy"
              :title="t('app.strategy.concepts.menu.duplicate')"
              data-test="concept-menu-duplicate"
              @click="pick('duplicate')"
            />
            <v-list-item
              prepend-icon="mdi-delete-outline"
              :title="t('app.strategy.concepts.menu.delete')"
              :subtitle="deleteBlocked ? t('app.strategy.concepts.menu.deleteDecided') : undefined"
              :disabled="deleteBlocked"
              base-color="error"
              data-test="concept-menu-delete"
              @click="pick('remove')"
            />
          </v-list>
        </v-menu>
      </div>
      <h3 class="concept-card__title" data-test="concept-title">{{ a.title }}</h3>
      <div class="concept-card__chips">
        <span class="concept-card__persona" data-test="concept-persona">
          <span class="concept-card__persona-code">{{ a.persona.code }}</span>
          <span class="concept-card__persona-title">{{ a.persona.title }}</span>
        </span>
        <v-menu v-model="stageOpen" location="bottom start" v-bind="MENU_PROPS">
          <template #activator="{ props: act }">
            <button
              v-bind="act"
              type="button"
              class="concept-card__funnel"
              :disabled="busy"
              :aria-label="t('app.strategy.concepts.card.stage')"
              data-test="concept-funnel"
            >
              <template v-if="a.funnel_stage !== null">
                <span class="concept-card__funnel-stage">{{ a.funnel_stage.toUpperCase() }}</span>
                <template v-if="a.funnel_note"> · {{ a.funnel_note }}</template>
              </template>
              <template v-else>{{ t('app.strategy.concepts.card.stageNone') }}</template>
              <v-icon icon="mdi-chevron-down" size="12" aria-hidden="true" />
            </button>
          </template>
          <v-list density="compact" data-test="concept-funnel-list">
            <v-list-item
              v-for="s in FUNNEL_STAGES"
              :key="s"
              :title="t(`app.strategy.concepts.stage.${s}`)"
              :active="a.funnel_stage === s"
              :data-test="`concept-funnel-${s}`"
              @click="pickStage(s)"
            />
            <v-list-item
              v-if="a.funnel_stage !== null"
              :title="t('app.strategy.concepts.stage.none')"
              data-test="concept-funnel-none"
              @click="pickStage(null)"
            />
          </v-list>
        </v-menu>
        <span
          v-for="u in a.usps"
          :key="u.id"
          class="concept-card__usp"
          :style="{ '--usp-color': brandColorVar(u.color_key) }"
          data-test="concept-usp"
        >
          <span class="concept-card__usp-dot" aria-hidden="true" />{{ u.title }}
        </span>
      </div>
    </header>

    <div v-if="textFields.length > 0" class="concept-card__body" data-test="concept-body">
      <div
        v-for="row in textFields"
        :key="row.key"
        class="concept-card__field"
        :data-field="row.key"
      >
        <template v-if="row.key === 'key_points'">
          <span class="concept-card__label">{{ t('app.strategy.concepts.field.key_points') }}</span>
          <ul class="concept-card__points" data-test="concept-key-points">
            <li v-for="(p, i) in a.key_points" :key="i">
              <span class="concept-card__bullet" aria-hidden="true">•</span>
              <span>{{ p }}</span>
            </li>
          </ul>
        </template>
        <template v-else>
          <span class="concept-card__label">{{ row.label }}</span>
          <span v-if="row.boxed" class="concept-card__box" :data-test="`concept-box-${row.key}`">{{
            row.value
          }}</span>
          <span v-else class="concept-card__value">{{ row.value }}</span>
        </template>
      </div>
    </div>

    <ConceptReferences
      :concept="concept"
      :scope="scope"
      :limits="limits"
      @changed="emit('changed')"
    />

    <footer
      v-if="a.status === 'approved' && a.decision"
      class="concept-card__foot concept-card__foot--approved"
      data-test="concept-footer-approved"
    >
      <p class="concept-card__foot-line">
        {{
          t('app.strategy.concepts.card.approvedBy', {
            name: a.decision.client_name,
            date: decidedOn,
          })
        }}
      </p>
      <p v-if="a.decision.note" class="concept-card__foot-text" data-test="concept-decision-note">
        {{ a.decision.note }}
      </p>
    </footer>
    <footer
      v-else-if="a.status === 'changes_requested' && a.decision"
      class="concept-card__foot concept-card__foot--changes"
      data-test="concept-footer-changes"
    >
      <div class="concept-card__foot-who">
        <span class="concept-card__avatar" aria-hidden="true">{{
          initialsOf(a.decision.client_name)
        }}</span>
        <span class="concept-card__foot-meta">
          {{ a.decision.client_name }} · {{ brandName }} · {{ decidedOn }}
        </span>
      </div>
      <p class="concept-card__foot-text" data-test="concept-comment">{{ a.decision.comment }}</p>
    </footer>
  </article>
</template>

<style scoped>
/*
 * Values from frame 13:4412 (card Concept/n): fill surface, 1px border, radius 12, clipped; the header is the persona's
 * colour at 6% with 16 / 18 / 14 padding and a 10px gap; the "CONCEPT n" chip on the page fill, 10.5px / 700 with
 * 0.6px tracking; the status pill 11px / 500 with a 6px dot at 15%; title 18 / 600; chips 12px, radius 999; body 18 / 16
 * padding, 12px gap, label cell 108px (12 / 500, tertiary), value 13 / 19; Hook, Payoff and CTA in a box at 6% fill and
 * the matrix's yellow-line border; key points a column with a tertiary bullet; the changes-requested border at 50%, its footer 7% fill and
 * 30% top border with a 24px avatar. The approved footer is the same geometry in green (an assumption: the frame has none).
 */
.concept-card {
  display: flex;
  flex-direction: column;
  min-width: 0;
  overflow: hidden;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 12px;
  background: rgb(var(--v-theme-surface));
}
.concept-card--changes_requested {
  border-color: color-mix(in srgb, var(--agency-brand-red) 50%, transparent);
}
.concept-card__head {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 16px 18px 14px;
  background: color-mix(in srgb, var(--persona-color) 6%, transparent);
}
.concept-card__row {
  display: flex;
  align-items: center;
  gap: 8px;
}
.concept-card__chip {
  padding: 2px 8px;
  border-radius: 6px;
  font-size: 10.5px;
  font-weight: 700;
  line-height: normal;
  letter-spacing: 0.6px;
  text-transform: uppercase;
  color: rgb(var(--v-theme-on-surface));
  background: rgb(var(--v-theme-background));
}
.concept-card__pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 9px;
  border-radius: 9999px;
  font-size: 11px;
  font-weight: 500;
  line-height: normal;
  --pill: var(--agency-brand-amber);
  color: var(--pill);
  background: color-mix(in srgb, var(--pill) 15%, transparent);
}
.concept-card__pill--approved {
  --pill: var(--agency-brand-green);
}
.concept-card__pill--changes_requested {
  --pill: var(--agency-brand-red);
}
.concept-card__dot {
  width: 6px;
  height: 6px;
  border-radius: 9999px;
  background: var(--pill);
}
.concept-card__title {
  margin: 0;
  font-size: 18px;
  font-weight: 600;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface));
  overflow-wrap: anywhere;
}
.concept-card__chips {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}
.concept-card__persona {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  max-width: 100%;
  padding: 3px 9px;
  border: 1px solid color-mix(in srgb, var(--persona-color) 60%, transparent);
  border-radius: 9999px;
  line-height: normal;
}
.concept-card__persona-code {
  font-size: 10px;
  font-weight: 700;
  color: var(--persona-color);
}
.concept-card__persona-title {
  font-size: 12px;
  font-weight: 500;
  color: rgb(var(--v-theme-on-surface));
  overflow-wrap: anywhere;
}
.concept-card__funnel {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 9px;
  border: 0;
  border-radius: 9999px;
  background: rgb(var(--v-theme-surface-bright));
  color: rgb(var(--v-theme-on-surface-variant));
  font: inherit;
  font-size: 12px;
  line-height: normal;
  cursor: pointer;
}
.concept-card__funnel:disabled {
  cursor: default;
}
.concept-card__usp {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 9px;
  border-radius: 9999px;
  font-size: 12px;
  font-weight: 500;
  line-height: normal;
  color: var(--usp-color);
  background: color-mix(in srgb, var(--usp-color) 14%, transparent);
  overflow-wrap: anywhere;
}
.concept-card__usp-dot {
  flex: none;
  width: 6px;
  height: 6px;
  border-radius: 9999px;
  background: var(--usp-color);
}
.concept-card__body {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 18px 16px;
}
.concept-card__field {
  display: flex;
  gap: 12px;
  min-width: 0;
}
.concept-card__label {
  flex: 0 0 108px;
  font-size: 12px;
  font-weight: 500;
  line-height: 19px;
  color: rgb(var(--v-theme-text-tertiary));
}
.concept-card__value,
.concept-card__points,
.concept-card__box {
  flex: 1 1 0;
  min-width: 0;
  font-size: 13px;
  line-height: 19px;
  color: rgb(var(--v-theme-on-surface));
  overflow-wrap: anywhere;
  white-space: pre-line;
}
.concept-card__box {
  padding: 7px 10px;
  border: 1px solid var(--agency-matrix-line-strong);
  border-radius: 6px;
  background: var(--agency-matrix-line-fill);
  font-style: italic;
}
.concept-card__points {
  display: flex;
  flex-direction: column;
  gap: 3px;
  margin: 0;
  padding: 0;
  list-style: none;
  white-space: normal;
}
.concept-card__points li {
  display: flex;
  gap: 6px;
}
.concept-card__bullet {
  color: rgb(var(--v-theme-text-tertiary));
}
.concept-card__foot {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 12px 18px;
  border-top: 1px solid transparent;
}
.concept-card__foot--approved {
  background: color-mix(in srgb, var(--agency-brand-green) 7%, transparent);
  border-top-color: color-mix(in srgb, var(--agency-brand-green) 30%, transparent);
}
.concept-card__foot--changes {
  background: color-mix(in srgb, var(--agency-brand-red) 7%, transparent);
  border-top-color: color-mix(in srgb, var(--agency-brand-red) 30%, transparent);
}
.concept-card__foot-line {
  margin: 0;
  font-size: 12px;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface));
}
.concept-card__foot-who {
  display: flex;
  align-items: center;
  gap: 8px;
}
.concept-card__avatar {
  display: inline-flex;
  flex: none;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border-radius: 9999px;
  font-size: 11px;
  font-weight: 600;
  background: var(--agency-brand-blue);
  color: rgb(var(--v-theme-background));
}
.concept-card__foot-meta {
  font-size: 11.5px;
  line-height: normal;
  color: rgb(var(--v-theme-text-tertiary));
  overflow-wrap: anywhere;
}
.concept-card__foot-text {
  margin: 0;
  font-size: 13px;
  line-height: 19px;
  color: rgb(var(--v-theme-on-surface));
  overflow-wrap: anywhere;
  white-space: pre-line;
}
</style>
