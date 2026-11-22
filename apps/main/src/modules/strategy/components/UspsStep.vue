<script setup lang="ts">
/**
 * PL-2.1 — step 1, USPs: one set per wave, shared by all its markets (4 to 6 recommended, 6 the maximum).
 *
 * The audience title and the counter sit above a card grid. Below 4 USPs a warning line says so (amber, never
 * blocking); at 6 Add USP is disabled and the counter says why. Cards are dragged by their grip (vuedraggable, as
 * the board columns) or moved from the menu; both send the FULL ordered list. A refused or stale order reloads the
 * grid, so the screen never keeps an order the server did not accept.
 */
import type { StrategySummaryResource, WaveUspResource } from '@catalyst/api-client'
import { CEmptyState } from '@catalyst/ui'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import draggable from 'vuedraggable'

import { strategyApi } from '../api/strategy.api'
import { provideMarkets } from '../useMarkets'
import { useStrategyContext } from '../composables/useStrategyContext'
import { strategyErrorMessage } from '../strategyErrors'
import AudienceTitleDialog from './AudienceTitleDialog.vue'
import UspCard from './UspCard.vue'
import UspDeleteDialog from './UspDeleteDialog.vue'
import UspDialog from './UspDialog.vue'

const props = defineProps<{ summary: StrategySummaryResource }>()

/** PL-2.4: the delete dialog prints each affected market by name and reads the concepts of every market. */
provideMarkets(() => props.summary)
const emit = defineEmits<{
  summary: [summary: StrategySummaryResource]
  changed: []
}>()

const { t } = useI18n()
const { scope, canDeleteInUse } = useStrategyContext()

const limits = computed(() => props.summary.attributes.limits)
const audienceTitle = computed(() => props.summary.attributes.audience_title)

/** The grid, in the order shown. vuedraggable moves items inside this array. */
const usps = ref<WaveUspResource[]>([])
const loading = ref(true)
const failed = ref(false)
const reordering = ref(false)
const notice = ref<string | null>(null)

const uspDialog = ref(false)
const editing = ref<WaveUspResource | null>(null)
const deleteDialog = ref(false)
const deleting = ref<WaveUspResource | null>(null)
const audienceDialog = ref(false)

const count = computed(() => usps.value.length)
const atMax = computed(() => count.value >= limits.value.usp_max)
const belowWarn = computed(() => count.value < limits.value.usp_warn_below)

async function load(): Promise<void> {
  const s = scope.value
  if (s === null) return
  try {
    usps.value = (await strategyApi.listUsps(s)).data
    failed.value = false
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

onMounted(load)

function openAdd(): void {
  editing.value = null
  uspDialog.value = true
}
function openEdit(usp: WaveUspResource): void {
  editing.value = usp
  uspDialog.value = true
}
function openDelete(usp: WaveUspResource): void {
  deleting.value = usp
  deleteDialog.value = true
}

async function onSaved(): Promise<void> {
  notice.value = null
  await load()
  emit('changed')
}

async function onDeleted(): Promise<void> {
  notice.value = null
  await load()
  emit('changed')
}

async function persistOrder(): Promise<void> {
  const s = scope.value
  if (s === null) return
  reordering.value = true
  notice.value = null
  try {
    const res = await strategyApi.reorderUsps(
      s,
      usps.value.map((u) => u.id),
    )
    usps.value = res.data
  } catch (err) {
    notice.value = strategyErrorMessage(err, t)
    await load()
  } finally {
    reordering.value = false
  }
}

function onDragChange(evt: { moved?: unknown }): void {
  if (evt.moved !== undefined) void persistOrder()
}

function move(usp: WaveUspResource, by: -1 | 1): void {
  const from = usps.value.findIndex((u) => u.id === usp.id)
  const to = from + by
  if (from < 0 || to < 0 || to >= usps.value.length) return
  const next = [...usps.value]
  const [item] = next.splice(from, 1)
  if (item === undefined) return
  next.splice(to, 0, item)
  usps.value = next
  void persistOrder()
}
</script>

<template>
  <section class="usps-step" data-test="usps-step">
    <div class="step-head">
      <h2 class="step-head__title" data-test="usps-heading">{{ t('app.strategy.usp.title') }}</h2>
      <p class="step-head__sub">{{ t('app.strategy.usp.subtitle') }}</p>
    </div>

    <div class="usps-bar" data-test="usps-bar">
      <div class="usps-bar__audience">
        <span class="usps-bar__label">{{ t('app.strategy.audience.label') }}</span>
        <span v-if="audienceTitle" class="usps-bar__title" data-test="audience-title">{{
          audienceTitle
        }}</span>
        <span v-else class="usps-bar__empty" data-test="audience-title-empty">
          {{ t('app.strategy.audience.placeholder') }}
        </span>
        <v-btn
          icon="mdi-pencil-outline"
          variant="text"
          size="x-small"
          class="usps-bar__edit"
          :aria-label="t('app.strategy.audience.edit')"
          data-test="audience-edit"
          @click="audienceDialog = true"
        />
      </div>
      <div class="usps-bar__side">
        <span class="usps-bar__counter" data-test="usp-counter">
          {{
            atMax
              ? t('app.strategy.usp.counterMax', { count, max: limits.usp_max })
              : t('app.strategy.usp.counter', { count, max: limits.usp_max })
          }}
        </span>
        <v-tooltip :disabled="!atMax" location="bottom">
          <template #activator="{ props: tip }">
            <span v-bind="tip">
              <v-btn
                variant="outlined"
                size="small"
                class="step-btn"
                prepend-icon="mdi-plus"
                :disabled="atMax || loading || failed"
                data-test="usp-add"
                @click="openAdd"
              >
                {{ t('app.strategy.usp.add') }}
              </v-btn>
            </span>
          </template>
          {{ t('app.strategy.usp.maxReason', { max: limits.usp_max }) }}
        </v-tooltip>
      </div>
    </div>

    <v-alert
      v-if="!loading && !failed && belowWarn"
      type="warning"
      variant="tonal"
      density="compact"
      data-test="usp-warning"
    >
      {{ t('app.strategy.usp.warning', { min: limits.usp_warn_below }) }}
    </v-alert>
    <v-alert
      v-if="notice"
      type="error"
      variant="tonal"
      density="compact"
      closable
      data-test="usp-notice"
      @click:close="notice = null"
    >
      {{ notice }}
    </v-alert>

    <v-skeleton-loader v-if="loading" type="card, card" data-test="usps-skeleton" />
    <v-alert v-else-if="failed" type="error" variant="tonal" data-test="usps-error">
      {{ t('app.strategy.errors.loadFailed') }}
    </v-alert>
    <CEmptyState
      v-else-if="count === 0"
      class="agency-card usps-empty"
      data-test="usps-empty"
      title-tag="h3"
      :title="t('app.strategy.usp.emptyTitle')"
      :body="t('app.strategy.usp.emptyBody')"
    >
      <template #icon>
        <v-icon icon="mdi-lightbulb-outline" size="48" aria-hidden="true" />
      </template>
      <template #action>
        <v-btn
          color="primary"
          variant="flat"
          prepend-icon="mdi-plus"
          data-test="usp-add-empty"
          @click="openAdd"
        >
          {{ t('app.strategy.usp.add') }}
        </v-btn>
      </template>
    </CEmptyState>
    <draggable
      v-else
      v-model="usps"
      item-key="id"
      handle=".usp-grip"
      :disabled="reordering"
      class="usps-grid"
      data-test="usps-grid"
      @change="onDragChange"
    >
      <template #item="{ element, index }">
        <UspCard
          :usp="element"
          :index="index + 1"
          :total="count"
          :can-delete-in-use="canDeleteInUse"
          :busy="reordering"
          @edit="openEdit"
          @remove="openDelete"
          @earlier="move($event, -1)"
          @later="move($event, 1)"
        />
      </template>
    </draggable>

    <UspDialog
      v-if="scope"
      v-model="uspDialog"
      :scope="scope"
      :limits="limits"
      :usp="editing"
      @saved="onSaved"
      @gone="onSaved"
    />
    <UspDeleteDialog
      v-if="scope"
      v-model="deleteDialog"
      :scope="scope"
      :usp="deleting"
      :can-delete-in-use="canDeleteInUse"
      @deleted="onDeleted"
      @stale="load"
    />
    <AudienceTitleDialog
      v-if="scope"
      v-model="audienceDialog"
      :scope="scope"
      :current="audienceTitle"
      :max="limits.audience_title"
      @saved="emit('summary', $event)"
    />
  </section>
</template>

<style scoped>
/*
 * Values from frame 13:428 (section head, audience bar), through the agency theme tokens.
 */
.usps-step {
  display: flex;
  flex-direction: column;
  gap: 18px;
}
.step-head {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.step-head__title {
  margin: 0;
  font-size: 22px;
  font-weight: 600;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface));
}
.step-head__sub {
  margin: 0;
  font-size: 13px;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface-variant));
}
.usps-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 8px 24px;
  padding: 12px 16px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 8px;
  background: rgb(var(--v-theme-surface));
}
.usps-bar__audience {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px 10px;
  min-width: 0;
}
.usps-bar__label {
  font-size: 12px;
  line-height: normal;
  color: rgb(var(--v-theme-text-tertiary));
}
.usps-bar__title {
  font-size: 16px;
  font-weight: 600;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface));
  overflow-wrap: anywhere;
}
.usps-bar__empty {
  font-size: 14px;
  color: rgb(var(--v-theme-on-surface-variant));
}
.usps-bar__edit {
  color: rgb(var(--v-theme-text-tertiary));
}
.usps-bar__side {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.usps-bar__counter {
  font-size: 12px;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface-variant));
}
/*
 * 13px medium, 14px icon, 8px gap, 6px 12px padding, 8px radius, 1px border (frame: "Add USP", drawn with no fill
 * and the default border; the agency theme's secondary button has a surface fill and the strong border, and its
 * selector is more specific than any scoped one, hence the !important).
 */
.usps-bar .step-btn {
  height: auto;
  min-height: 0;
  padding: 6px 12px;
  border: 1px solid rgb(var(--v-theme-border-color)) !important;
  border-radius: 8px;
  background-color: transparent !important;
  font-size: 13px;
  font-weight: 500;
  letter-spacing: normal;
  text-transform: none;
  color: rgb(var(--v-theme-on-surface));
}
.usps-bar .step-btn:disabled,
.usps-bar .step-btn.v-btn--disabled {
  border-color: color-mix(in srgb, rgb(var(--v-theme-text-tertiary)) 80%, transparent) !important;
  color: rgb(var(--v-theme-text-tertiary)) !important;
  opacity: 1;
}
.usps-bar .step-btn :deep(.v-btn__prepend) {
  margin-inline: 0 8px;
  font-size: 14px;
}
.usps-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 290px), 1fr));
  gap: 16px;
}
/* PL-2.5: with the notes column beside it the cards return to the frame's width (frame 13:428: 290 px). */
@media (min-width: 1100px) {
  .usps-grid {
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 290px), 290px));
  }
}
.usps-empty {
  text-align: center;
}
</style>
