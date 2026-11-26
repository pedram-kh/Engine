<script setup lang="ts">
/**
 * PL-3.2a — the archived scripts of a market. A script is archived by "Archive script" in the editor (every role), or when its
 * creator is removed from the market with "archive" (or replaced with "archive and start fresh"). Every role can Restore one while
 * its creator is in the market and has fewer than three live scripts; otherwise the row says why. A creator who is added to the
 * market again gets their scripts back on their own. Only an admin or a manager can delete one for good (after a confirm), and
 * this list is the only place that can. Names are text interpolations.
 */
import type { ArchivedScript } from '@catalyst/api-client'
import { ref, toRefs, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { creatorLabel } from '@/core/creators/creatorLabel'
import { strategyApi, type WaveScope } from '../api/strategy.api'
import { isGone, strategyErrorMessage } from '../strategyErrors'
import StrategyConfirmDialog from './StrategyConfirmDialog.vue'

const props = defineProps<{
  modelValue: boolean
  scope: WaveScope
  market: string
  marketName: string
  canPurge: boolean
  /** The most live scripts a creator may have in a wave. */
  max: number
}>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; changed: [] }>()
const { t, locale } = useI18n()
const { max } = toRefs(props)

const session = ref(0)
const items = ref<ArchivedScript[]>([])
const loading = ref(false)
const failed = ref(false)
let ticket = 0

async function load(): Promise<void> {
  const mine = ++ticket
  loading.value = true
  failed.value = false
  try {
    const res = await strategyApi.archivedScripts(props.scope, props.market)
    if (mine === ticket) items.value = res.data
  } catch {
    if (mine === ticket) failed.value = true
  } finally {
    if (mine === ticket) loading.value = false
  }
}

watch(
  () => props.modelValue,
  (open) => {
    session.value++
    ticket++
    purgeOpen.value = false
    if (open) {
      items.value = []
      void load()
    }
  },
)

function label(a: ArchivedScript): string {
  return creatorLabel(a.creator, t, t('app.strategy.creators.card.unnamed'))
}
function when(iso: string | null): string {
  return iso === null
    ? ''
    : new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium' }).format(new Date(iso))
}

// Restore (every role): the server decides; a refusal is shown on the row.
const restoring = ref<string | null>(null)
const restoreErrors = ref<Record<string, string>>({})
async function restore(a: ArchivedScript): Promise<void> {
  if (restoring.value !== null) return
  restoring.value = a.id
  restoreErrors.value = {}
  try {
    await strategyApi.restoreScript(props.scope, a.id)
    emit('changed')
    await load()
  } catch (err) {
    if (isGone(err)) {
      emit('changed')
      await load()
    } else restoreErrors.value = { [a.id]: strategyErrorMessage(err, t) }
  } finally {
    restoring.value = null
  }
}

const purgeOpen = ref(false)
const purging = ref<ArchivedScript | null>(null)
const purgeBusy = ref(false)
const purgeError = ref<string | null>(null)
function askPurge(a: ArchivedScript): void {
  purging.value = a
  purgeError.value = null
  purgeOpen.value = true
}
async function doPurge(): Promise<void> {
  const a = purging.value
  if (a === null || purgeBusy.value) return
  purgeBusy.value = true
  purgeError.value = null
  try {
    await strategyApi.deleteArchivedScript(props.scope, a.id)
    purgeOpen.value = false
    emit('changed')
    await load()
  } catch (err) {
    if (isGone(err)) {
      purgeOpen.value = false
      emit('changed')
      await load()
    } else purgeError.value = strategyErrorMessage(err, t)
  } finally {
    purgeBusy.value = false
  }
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="560"
    scrollable
    data-test="archived-scripts-dialog"
    @update:model-value="(v: boolean) => emit('update:modelValue', v)"
  >
    <v-card :key="session">
      <v-card-title class="text-h6 text-wrap text-break pt-4 px-6">{{
        t('app.strategy.scripts.archived.title', { market: marketName })
      }}</v-card-title>
      <v-card-text class="px-6">
        <p class="mb-3" data-test="archived-info">
          {{ t('app.strategy.scripts.archived.infoFull') }}
        </p>
        <v-skeleton-loader v-if="loading" type="list-item-two-line, list-item-two-line" />
        <v-alert
          v-else-if="failed"
          type="error"
          variant="tonal"
          density="compact"
          data-test="archived-error"
          >{{ t('app.strategy.errors.loadFailed') }}</v-alert
        >
        <p v-else-if="items.length === 0" class="text-medium-emphasis" data-test="archived-empty">
          {{ t('app.strategy.scripts.archived.empty') }}
        </p>
        <ul v-else class="arch">
          <li v-for="a in items" :key="a.id" class="arch__row" :data-test="`archived-${a.id}`">
            <div class="arch__main">
              <div class="arch__name">
                {{ t('app.strategy.scripts.archived.row', { name: label(a), n: a.number }) }}
              </div>
              <div class="arch__meta">
                {{
                  t('app.strategy.scripts.archived.counts', {
                    hooks: a.hooks,
                    lines: a.lines,
                    images: a.images,
                  })
                }}
                <template v-if="a.archived_at">
                  ·
                  {{
                    t('app.strategy.scripts.archived.on', { date: when(a.archived_at) })
                  }}</template
                >
              </div>
              <div
                v-if="!a.creator_in_market"
                class="arch__hint"
                :data-test="`archived-gone-${a.id}`"
              >
                {{ t('app.strategy.scripts.archived.creatorGone') }}
              </div>
              <div
                v-else-if="!a.can_restore"
                class="arch__hint"
                :data-test="`archived-full-${a.id}`"
              >
                {{ t('app.strategy.scripts.archived.restoreFull', { name: label(a), max }) }}
              </div>
              <div
                v-if="restoreErrors[a.id]"
                class="arch__error"
                role="alert"
                :data-test="`archived-restore-error-${a.id}`"
              >
                {{ restoreErrors[a.id] }}
              </div>
            </div>
            <div class="arch__actions">
              <v-btn
                size="small"
                variant="outlined"
                prepend-icon="mdi-restore"
                :disabled="!a.can_restore || restoring !== null"
                :loading="restoring === a.id"
                :data-test="`archived-restore-${a.id}`"
                @click="restore(a)"
                >{{ t('app.strategy.scripts.archived.restore') }}</v-btn
              >
              <v-btn
                v-if="canPurge"
                size="small"
                variant="text"
                color="error"
                :data-test="`archived-delete-${a.id}`"
                @click="askPurge(a)"
                >{{ t('app.strategy.scripts.archived.delete') }}</v-btn
              >
            </div>
          </li>
        </ul>
      </v-card-text>
      <v-card-actions class="px-6 pb-4">
        <v-spacer />
        <v-btn
          variant="text"
          data-test="archived-close"
          @click="emit('update:modelValue', false)"
          >{{ t('app.strategy.close') }}</v-btn
        >
      </v-card-actions>
    </v-card>
    <StrategyConfirmDialog
      v-model="purgeOpen"
      :title="t('app.strategy.scripts.archived.deleteTitle')"
      :body="
        purging === null
          ? ''
          : t('app.strategy.scripts.archived.deleteBodyFull', {
              name: label(purging),
              n: purging.number,
              hooks: purging.hooks,
              lines: purging.lines,
              images: purging.images,
            })
      "
      :confirm-label="t('app.strategy.scripts.archived.deleteConfirm')"
      :busy="purgeBusy"
      :error="purgeError"
      test-id="archived-delete-dialog"
      @confirm="doPurge"
    />
  </v-dialog>
</template>

<style scoped>
.arch {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin: 0;
  padding: 0;
  list-style: none;
}
.arch__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 12px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 8px;
}
.arch__main {
  min-width: 0;
}
.arch__name {
  font-size: 14px;
  font-weight: 500;
  overflow-wrap: anywhere;
}
.arch__meta,
.arch__hint {
  font-size: 12px;
  color: rgb(var(--v-theme-on-surface-variant));
}
.arch__hint {
  margin-top: 2px;
}
.arch__error {
  margin-top: 4px;
  font-size: 12px;
  color: rgb(var(--v-theme-error));
}
.arch__actions {
  display: flex;
  flex: none;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: 4px;
}
</style>
