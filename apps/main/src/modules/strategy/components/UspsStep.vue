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
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import draggable from 'vuedraggable'

import { strategyApi } from '../api/strategy.api'
import { useStrategyContext } from '../composables/useStrategyContext'
import { strategyErrorMessage } from '../strategyErrors'
import AudienceTitleDialog from './AudienceTitleDialog.vue'
import UspCard from './UspCard.vue'
import UspDeleteDialog from './UspDeleteDialog.vue'
import UspDialog from './UspDialog.vue'

const props = defineProps<{ summary: StrategySummaryResource }>()
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
    <div>
      <h2 class="text-h6 mb-1" data-test="usps-heading">{{ t('app.strategy.usp.title') }}</h2>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('app.strategy.usp.subtitle') }}</p>
    </div>

    <div class="agency-card usps-bar" data-test="usps-bar">
      <div class="usps-bar__audience">
        <span class="agency-field__label">{{ t('app.strategy.audience.label') }}</span>
        <div class="d-flex align-center ga-2">
          <span v-if="audienceTitle" class="usps-bar__title" data-test="audience-title">{{
            audienceTitle
          }}</span>
          <span v-else class="text-medium-emphasis" data-test="audience-title-empty">
            {{ t('app.strategy.audience.placeholder') }}
          </span>
          <v-btn
            icon="mdi-pencil-outline"
            variant="text"
            size="small"
            :aria-label="t('app.strategy.audience.edit')"
            data-test="audience-edit"
            @click="audienceDialog = true"
          />
        </div>
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
                color="primary"
                variant="flat"
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
    <div v-else-if="count === 0" class="agency-card usps-empty" data-test="usps-empty">
      <p class="text-subtitle-1 mb-1">{{ t('app.strategy.usp.emptyTitle') }}</p>
      <p class="text-body-2 text-medium-emphasis mb-3">{{ t('app.strategy.usp.emptyBody') }}</p>
      <v-btn
        color="primary"
        variant="flat"
        prepend-icon="mdi-plus"
        data-test="usp-add-empty"
        @click="openAdd"
      >
        {{ t('app.strategy.usp.add') }}
      </v-btn>
    </div>
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
      @deleted="onDeleted"
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
.usps-step {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.usps-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px 24px;
}
.usps-bar__audience {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}
.usps-bar__title {
  font-weight: 600;
  overflow-wrap: anywhere;
}
.usps-bar__side {
  display: flex;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
}
.usps-bar__counter {
  font-size: 14px;
  font-weight: 600;
}
.usps-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 300px), 1fr));
  gap: 16px;
}
.usps-empty {
  text-align: center;
}
</style>
