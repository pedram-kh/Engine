<script setup lang="ts">
/**
 * PL-2.1 — delete a USP. A USP no persona angle or matrix cell uses is a plain confirm. For one that is used, the dialog
 * lists what is affected (market, persona, the angle text, or the cell's level and message line) and the button says how
 * many items go with it. A cell and the angle synced from it are one item (PL-2.2).
 *
 * The list is first built from the wave's personas (read only, safe to do on open). The delete carries
 * `confirm_count` equal to the number shown; if the server counts differently (someone linked or unlinked an
 * angle meanwhile) it answers 409 `usp.in_use` with a fresh list, read through `uspInUseMeta`, the dialog shows
 * that list with a note and asks again. It never deletes more than what was shown.
 */
import { uspInUseMeta } from '@catalyst/api-client'
import type { UspInUseAffected, WaveUspResource } from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { strategyApi, type WaveScope } from '../api/strategy.api'
import { isGone, strategyErrorMessage } from '../strategyErrors'
import StrategyConfirmDialog from './StrategyConfirmDialog.vue'

const props = defineProps<{
  modelValue: boolean
  scope: WaveScope
  usp: WaveUspResource | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  deleted: []
}>()

const { t } = useI18n()

const affected = ref<UspInUseAffected[]>([])
const loadingList = ref(false)
const busy = ref(false)
const error = ref<string | null>(null)
const refreshed = ref(false)
const gone = ref(false)
/** Bumped on every open; a slow list that answers after a close must not fill a later open. */
let openId = 0

async function loadAffected(usp: WaveUspResource, id: number): Promise<void> {
  loadingList.value = true
  try {
    const res = await strategyApi.listPersonas(props.scope)
    if (id !== openId) return
    const out: UspInUseAffected[] = []
    for (const p of res.data) {
      for (const a of p.attributes.angles) {
        // A synced angle is part of its cell and is listed with it, not twice.
        if (a.attributes.usp === usp.id && !a.attributes.read_only) {
          out.push({
            kind: 'angle',
            level: null,
            persona: p.id,
            market: p.attributes.market,
            code: p.attributes.code,
            title: p.attributes.title,
            explanation: a.attributes.explanation,
          })
        }
      }
      for (const c of p.attributes.matrix_cells) {
        if (c.usp === usp.id) {
          const synced = p.attributes.angles.find(
            (a) => a.attributes.usp === usp.id && a.attributes.read_only,
          )
          out.push({
            kind: 'cell',
            level: c.level,
            persona: p.id,
            market: p.attributes.market,
            code: p.attributes.code,
            title: p.attributes.title,
            explanation: synced?.attributes.explanation ?? '',
          })
        }
      }
    }
    affected.value = out
  } catch (err) {
    if (id === openId) error.value = strategyErrorMessage(err, t)
  } finally {
    if (id === openId) loadingList.value = false
  }
}

watch(
  () => props.modelValue,
  (open) => {
    openId++
    affected.value = []
    busy.value = false
    error.value = null
    refreshed.value = false
    gone.value = false
    loadingList.value = false
    if (
      open &&
      props.usp &&
      (props.usp.attributes.angle_count > 0 || props.usp.attributes.cell_count > 0)
    ) {
      void loadAffected(props.usp, openId)
    }
  },
  { immediate: true },
)

const inUse = computed(() => affected.value.length > 0)
const hasCells = computed(() => affected.value.some((i) => i.kind === 'cell'))
const title = computed(() =>
  t('app.strategy.usp.deleteDialog.title', { title: props.usp?.attributes.title ?? '' }),
)
const confirmLabel = computed(() =>
  inUse.value
    ? t(
        hasCells.value
          ? 'app.strategy.usp.deleteDialog.confirmInUseCells'
          : 'app.strategy.usp.deleteDialog.confirmInUse',
        { count: affected.value.length },
        affected.value.length,
      )
    : t('app.strategy.usp.delete'),
)

function onClose(open: boolean): void {
  emit('update:modelValue', open)
  if (!open && gone.value) emit('deleted')
}

async function confirm(): Promise<void> {
  if (!props.usp || loadingList.value) return
  busy.value = true
  error.value = null
  try {
    await strategyApi.removeUsp(
      props.scope,
      props.usp.id,
      inUse.value ? affected.value.length : undefined,
    )
    emit('deleted')
    emit('update:modelValue', false)
  } catch (err) {
    const meta = uspInUseMeta(err)
    if (meta !== null) {
      affected.value = meta.affected
      refreshed.value = true
      error.value = null
    } else if (isGone(err)) {
      gone.value = true
      error.value = strategyErrorMessage(err, t)
    } else {
      error.value = strategyErrorMessage(err, t)
    }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <StrategyConfirmDialog
    :model-value="modelValue"
    test-id="usp-delete-dialog"
    :title="title"
    :body="
      inUse
        ? t(
            hasCells
              ? 'app.strategy.usp.deleteDialog.bodyInUseCells'
              : 'app.strategy.usp.deleteDialog.bodyInUse',
            { count: affected.length },
            affected.length,
          )
        : t('app.strategy.usp.deleteDialog.body')
    "
    :confirm-label="confirmLabel"
    :busy="busy || loadingList"
    :error="error"
    :confirm-disabled="gone"
    @update:model-value="onClose"
    @confirm="confirm"
  >
    <v-skeleton-loader
      v-if="loadingList"
      type="list-item-two-line"
      data-test="usp-delete-loading"
    />
    <template v-else>
      <v-alert
        v-if="refreshed"
        type="warning"
        variant="tonal"
        density="compact"
        class="mb-3"
        data-test="usp-delete-refreshed"
      >
        {{ t('app.strategy.usp.deleteDialog.refreshed') }}
      </v-alert>
      <ul v-if="inUse" class="usp-delete-list" data-test="usp-delete-affected">
        <li
          v-for="item in affected"
          :key="`${item.kind}-${item.persona}-${item.explanation}`"
          class="usp-delete-list__row"
        >
          <span class="usp-delete-list__who"
            >{{ item.market }} · {{ item.code }} · {{ item.title }}</span
          >
          <span class="usp-delete-list__what"
            ><template v-if="item.kind === 'cell' && item.level"
              >{{
                t('app.strategy.usp.deleteDialog.itemCell', {
                  level: t(`app.strategy.matrix.level.${item.level}`),
                })
              }}<template v-if="item.explanation"> · </template></template
            >{{ item.explanation }}</span
          >
        </li>
      </ul>
    </template>
  </StrategyConfirmDialog>
</template>

<style scoped>
.usp-delete-list {
  list-style: none;
  margin: 0;
  padding: 0;
  max-height: 40vh;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.usp-delete-list__row {
  display: flex;
  flex-direction: column;
  padding: 8px 10px;
  border-radius: 8px;
  background: rgba(var(--v-theme-on-surface), 0.05);
  overflow-wrap: anywhere;
}
.usp-delete-list__who {
  font-size: 13px;
  font-weight: 600;
}
.usp-delete-list__what {
  font-size: 13px;
  color: rgba(var(--v-theme-on-surface), 0.75);
  white-space: pre-line;
}
</style>
