<script setup lang="ts">
/**
 * Admin erasure queue (GDPR art. 17) — AH-104c C2.
 *
 * Six tabs, one per state an account can be in while it waits to be erased:
 *
 *   Pending   asked, and the grace period has not ended
 *   Ready     the grace ended and nothing stands in the way
 *   Blocked   the grace ended, but a payment, delivered work or a payout provider does
 *   On hold   an admin's legal hold stands (with or without a request)
 *   Erasing   the erasure has begun; the sentence says how far it got
 *   Erased    from the erasure ledger only: a date, and nothing of the person
 *
 * Every row says, in a sentence, where it stands and why: that is the whole point of the page,
 * and the stuck states (an erasure that stopped half way) say what to do. Placing or releasing a
 * hold needs a reason (the dialog).
 *
 * AH-104c C3 adds the Erase button, on a Ready row only (and "Review and confirm again" on an
 * erasing row the server says can be retried). It opens the plan, asks for the typed account id,
 * a reason and a fresh two-factor code, and queues the run (202). The page then watches the
 * account every few seconds until it is erased, or until the claim was released because the data
 * changed or a check now refuses: the admin is told which, and that nothing was erased.
 *
 * Words: "hold" in the UI; "Erasing" (never "claim") for an account the erasure has taken.
 */

import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { ApiError, formatDateTime } from '@catalyst/api-client'

import ErasureEraseDialog from '../components/ErasureEraseDialog.vue'
import ErasureHoldDialog from '../components/ErasureHoldDialog.vue'
import {
  adminComplianceApi,
  type ErasurePlan,
  ERASURE_TABS,
  type ErasureQueueCounts,
  type ErasureQueueItem,
  type ErasureQueueRow,
  type ErasureTab,
} from '../api/compliance.api'

const { t, te, locale } = useI18n()

const PAGE_SIZE = 25
/** How often an account that is being erased is looked at again. */
const POLL_MS = 4000

const tab = ref<ErasureTab>('pending')
const items = ref<ErasureQueueItem[]>([])
const counts = ref<ErasureQueueCounts | null>(null)
const nextCursor = ref<string | null>(null)
const loading = ref(false)
const errorKey = ref<string | null>(null)

const dialogOpen = ref(false)
const dialogMode = ref<'hold' | 'release'>('hold')
const dialogTarget = ref<ErasureQueueRow | null>(null)
const dialogSaving = ref(false)
const dialogErrorKey = ref<string | null>(null)

const eraseOpen = ref(false)
const eraseTarget = ref<ErasureQueueRow | null>(null)
const erasePlan = ref<ErasurePlan | null>(null)
const planLoading = ref(false)
const planErrorKey = ref<string | null>(null)
const eraseSaving = ref(false)
const eraseErrorKey = ref<string | null>(null)
const eraseErrorCodes = ref<string[]>([])

/** The account the admin just sent to be erased, and how it ended. */
const watched = ref<ErasureQueueRow | null>(null)
const watchedUlid = ref<string | null>(null)
const outcome = ref<'completed' | 'released' | null>(null)
let timer: ReturnType<typeof setInterval> | null = null

const isErasedTab = computed(() => tab.value === 'erased')
const showCapNotice = computed(
  () =>
    counts.value?.due_scan_capped === true && (tab.value === 'ready' || tab.value === 'blocked'),
)

const headers = computed(() =>
  isErasedTab.value
    ? [
        { title: t('admin.compliance.erasures.columns.account'), key: 'account', sortable: false },
        { title: t('admin.compliance.erasures.columns.state'), key: 'state', sortable: false },
      ]
    : [
        { title: t('admin.compliance.erasures.columns.account'), key: 'account', sortable: false },
        {
          title: t('admin.compliance.erasures.columns.requested'),
          key: 'requested',
          sortable: false,
          width: 180,
        },
        {
          title: t('admin.compliance.erasures.columns.due'),
          key: 'due',
          sortable: false,
          width: 180,
        },
        { title: t('admin.compliance.erasures.columns.state'), key: 'state', sortable: false },
        {
          title: t('admin.compliance.erasures.columns.actions'),
          key: 'actions',
          sortable: false,
          align: 'end' as const,
          width: 220,
        },
      ],
)

function tabLabel(name: ErasureTab): string {
  const count = counts.value?.[name]
  const label = t(`admin.compliance.erasures.tabs.${name}`)
  return count === undefined ? label : `${label} (${count})`
}

function when(iso: string | null): string {
  return iso === null ? '—' : formatDateTime(iso, locale.value)
}

function liveRow(item: ErasureQueueItem): ErasureQueueRow | null {
  return item.attributes.state === 'erased' ? null : (item as ErasureQueueRow)
}

/** The one sentence a row carries: where the account stands, and why. */
function sentence(item: ErasureQueueItem): string {
  if (item.attributes.state === 'erased') {
    return t('admin.compliance.erasures.state.erased', { date: when(item.attributes.erased_at) })
  }
  const row = item as ErasureQueueRow
  const a = row.attributes
  switch (a.state) {
    case 'pending':
      return t('admin.compliance.erasures.state.pending', { date: when(a.due_at) })
    case 'ready':
      return t('admin.compliance.erasures.state.ready')
    case 'blocked':
      return t('admin.compliance.erasures.state.blocked', {
        reasons: a.blockers
          .map((code) => t(`admin.compliance.erasures.blocker.${code}`))
          .join('; '),
      })
    case 'held': {
      const since = t('admin.compliance.erasures.state.held', { date: when(a.held_at) })
      return a.held_by === null
        ? since
        : `${since} ${t('admin.compliance.erasures.state.held_by', { admin: a.held_by })}`
    }
    case 'in_progress':
      return t(`admin.compliance.erasures.state.in_progress.${progress(row)}`)
  }
}

/** Which sentence an erasing row carries: its run, and whether it has outlasted a run. */
function progress(row: ErasureQueueRow): string {
  const run = row.attributes.run
  if (run === null) return 'waiting'
  if (run.state === 'waiting' || run.state === 'running') {
    return run.stuck ? `${run.state}_stuck` : run.state
  }
  return run.state === 'completed' ? 'running' : run.state
}

function canErase(row: ErasureQueueRow): boolean {
  return row.attributes.state === 'ready'
}

function canRetry(row: ErasureQueueRow): boolean {
  return row.attributes.state === 'in_progress' && row.attributes.run?.can_retry === true
}

function canHold(row: ErasureQueueRow): boolean {
  return ['pending', 'ready', 'blocked'].includes(row.attributes.state)
}

async function load(append: boolean): Promise<void> {
  loading.value = true
  errorKey.value = null
  try {
    const res = await adminComplianceApi.listErasureQueue({
      tab: tab.value,
      cursor: append ? nextCursor.value : null,
      perPage: PAGE_SIZE,
    })
    items.value = append ? [...items.value, ...res.data] : res.data
    nextCursor.value = res.meta.next_cursor
    counts.value = res.meta.counts
  } catch (error) {
    errorKey.value = error instanceof ApiError ? error.code : 'admin.compliance.load_failed'
  } finally {
    loading.value = false
  }
}

function selectTab(next: unknown): void {
  if (typeof next !== 'string' || !(ERASURE_TABS as readonly string[]).includes(next)) return
  tab.value = next as ErasureTab
  items.value = []
  nextCursor.value = null
  void load(false)
}

function openDialog(mode: 'hold' | 'release', row: ErasureQueueRow): void {
  dialogMode.value = mode
  dialogTarget.value = row
  dialogErrorKey.value = null
  dialogOpen.value = true
}

async function confirmDialog(payload: { reason: string }): Promise<void> {
  const row = dialogTarget.value
  if (row === null) return
  dialogSaving.value = true
  dialogErrorKey.value = null
  try {
    if (dialogMode.value === 'hold') {
      await adminComplianceApi.holdErasure(row.id, payload.reason)
    } else {
      await adminComplianceApi.releaseErasure(row.id, payload.reason)
    }
    dialogOpen.value = false
    await load(false)
  } catch (error) {
    dialogErrorKey.value = codeKey(error)
  } finally {
    dialogSaving.value = false
  }
}

function codeKey(error: unknown): string {
  const code = error instanceof ApiError ? error.code : ''
  return te(code) ? code : 'admin.compliance.erasures.action_failed'
}

async function openErase(row: ErasureQueueRow): Promise<void> {
  eraseTarget.value = row
  erasePlan.value = null
  planErrorKey.value = null
  eraseErrorKey.value = null
  eraseErrorCodes.value = []
  eraseOpen.value = true
  planLoading.value = true
  try {
    erasePlan.value = (await adminComplianceApi.erasurePlan(row.id)).data
  } catch (error) {
    planErrorKey.value = codeKey(error)
  } finally {
    planLoading.value = false
  }
}

function refusedCodes(error: unknown): string[] {
  if (!(error instanceof ApiError)) return []
  const codes = error.details[0]?.meta?.codes
  return Array.isArray(codes) ? codes.filter((c): c is string => typeof c === 'string') : []
}

async function confirmErase(payload: {
  ulid: string
  reason: string
  digest: string
  mfaCode: string
}): Promise<void> {
  const row = eraseTarget.value
  if (row === null) return
  eraseSaving.value = true
  eraseErrorKey.value = null
  eraseErrorCodes.value = []
  try {
    const res = await adminComplianceApi.erase(row.id, {
      ulid: payload.ulid,
      reason: payload.reason,
      digest: payload.digest,
      mfa_code: payload.mfaCode,
    })
    eraseOpen.value = false
    outcome.value = null
    watchedUlid.value = row.id
    watched.value = res.data
    selectTab('in_progress')
  } catch (error) {
    eraseErrorKey.value = codeKey(error)
    eraseErrorCodes.value = refusedCodes(error)
  } finally {
    eraseSaving.value = false
  }
}

/** Looks at the account just sent off: still erasing, erased (gone from the queue), or released. */
async function pollWatched(ulid: string): Promise<void> {
  try {
    const row = (await adminComplianceApi.erasureRow(ulid)).data
    if (row.attributes.state === 'in_progress') {
      watched.value = row
      return
    }
    outcome.value = 'released'
  } catch (error) {
    if (!(error instanceof ApiError) || error.status !== 404) return
    outcome.value = 'completed'
  }
  watchedUlid.value = null
  watched.value = null
  await load(false)
}

/** Reloads the Erasing tab without the loading flicker, so its sentences move on by themselves. */
async function refreshQuietly(): Promise<void> {
  try {
    const res = await adminComplianceApi.listErasureQueue({
      tab: tab.value,
      cursor: null,
      perPage: PAGE_SIZE,
    })
    items.value = res.data
    nextCursor.value = res.meta.next_cursor
    counts.value = res.meta.counts
  } catch {
    /* the next tick tries again; the page keeps what it shows */
  }
}

const hasRunningRows = computed<boolean>(
  () =>
    tab.value === 'in_progress' &&
    items.value.some((item) => {
      const run = (item as ErasureQueueRow).attributes.run
      return run !== null && (run.state === 'waiting' || run.state === 'running')
    }),
)

const shouldPoll = computed<boolean>(() => watchedUlid.value !== null || hasRunningRows.value)

function tick(): void {
  if (watchedUlid.value !== null) {
    void pollWatched(watchedUlid.value)
  } else if (hasRunningRows.value) {
    void refreshQuietly()
  }
}

watch(
  shouldPoll,
  (on) => {
    if (on && timer === null) timer = setInterval(tick, POLL_MS)
    if (!on && timer !== null) {
      clearInterval(timer)
      timer = null
    }
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  if (timer !== null) clearInterval(timer)
  timer = null
})

onMounted(() => {
  void load(false)
})
</script>

<template>
  <section data-testid="admin-erasure-queue">
    <header class="mb-4">
      <h1 class="text-h5 ma-0">{{ t('admin.compliance.erasures.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis ma-0 mt-1">
        {{ t('admin.compliance.erasures.subtitle') }}
      </p>
    </header>

    <v-tabs
      :model-value="tab"
      class="mb-4"
      data-testid="admin-erasure-tabs"
      @update:model-value="selectTab"
    >
      <v-tab
        v-for="name in ERASURE_TABS"
        :key="name"
        :value="name"
        :data-testid="`admin-erasure-tab-${name}`"
      >
        {{ tabLabel(name) }}
      </v-tab>
    </v-tabs>

    <v-alert
      v-if="errorKey"
      type="error"
      variant="tonal"
      class="mb-4"
      data-testid="admin-erasure-queue-error"
    >
      {{ t(errorKey) }}
    </v-alert>

    <v-alert
      v-if="watched !== null"
      type="info"
      variant="tonal"
      class="mb-4"
      data-testid="admin-erasure-watch"
    >
      {{ sentence(watched) }}
    </v-alert>

    <v-alert
      v-if="outcome !== null"
      :type="outcome === 'completed' ? 'success' : 'warning'"
      variant="tonal"
      closable
      class="mb-4"
      :data-testid="`admin-erasure-outcome-${outcome}`"
      @click:close="outcome = null"
    >
      {{ t(`admin.compliance.erasures.watch.${outcome}`) }}
    </v-alert>

    <v-alert
      v-if="showCapNotice"
      type="info"
      variant="tonal"
      class="mb-4"
      data-testid="admin-erasure-queue-capped"
    >
      {{ t('admin.compliance.erasures.capped', { count: 200 }) }}
    </v-alert>

    <v-data-table
      :headers="headers"
      :items="items"
      :loading="loading"
      item-value="id"
      :items-per-page="-1"
      hide-default-footer
      :no-data-text="t(`admin.compliance.erasures.empty.${tab}`)"
      data-testid="admin-erasure-queue-table"
    >
      <template #item.account="{ item }">
        <template v-if="liveRow(item)">
          <div class="font-weight-medium">{{ liveRow(item)?.attributes.name }}</div>
          <div class="text-caption text-medium-emphasis">{{ liveRow(item)?.attributes.email }}</div>
        </template>
        <code v-else data-testid="admin-erasure-erased-ref">{{ item.id }}</code>
      </template>

      <template #item.requested="{ item }">
        {{ when(liveRow(item)?.attributes.requested_at ?? null) }}
      </template>

      <template #item.due="{ item }">
        {{ when(liveRow(item)?.attributes.due_at ?? null) }}
      </template>

      <template #item.state="{ item }">
        <span :data-testid="`admin-erasure-state-${item.id}`">{{ sentence(item) }}</span>
      </template>

      <template #item.actions="{ item }">
        <template v-if="liveRow(item)">
          <v-btn
            v-if="canErase(liveRow(item) as ErasureQueueRow)"
            size="small"
            variant="text"
            color="error"
            :data-testid="`admin-erasure-erase-${item.id}`"
            @click="openErase(liveRow(item) as ErasureQueueRow)"
          >
            {{ t('admin.compliance.erasures.actions.erase') }}
          </v-btn>
          <v-btn
            v-if="canRetry(liveRow(item) as ErasureQueueRow)"
            size="small"
            variant="text"
            color="error"
            :data-testid="`admin-erasure-retry-${item.id}`"
            @click="openErase(liveRow(item) as ErasureQueueRow)"
          >
            {{ t('admin.compliance.erasures.actions.retry') }}
          </v-btn>
          <v-btn
            v-if="liveRow(item)?.attributes.state === 'held'"
            size="small"
            variant="text"
            :data-testid="`admin-erasure-release-${item.id}`"
            @click="openDialog('release', liveRow(item) as ErasureQueueRow)"
          >
            {{ t('admin.compliance.erasures.actions.release') }}
          </v-btn>
          <v-btn
            v-else-if="canHold(liveRow(item) as ErasureQueueRow)"
            size="small"
            variant="text"
            color="warning"
            :data-testid="`admin-erasure-hold-${item.id}`"
            @click="openDialog('hold', liveRow(item) as ErasureQueueRow)"
          >
            {{ t('admin.compliance.erasures.actions.hold') }}
          </v-btn>
        </template>
      </template>

      <template #bottom>
        <div v-if="nextCursor !== null" class="d-flex justify-center pa-3">
          <v-btn
            variant="tonal"
            :loading="loading"
            data-testid="admin-erasure-load-more"
            @click="load(true)"
          >
            {{ t('admin.compliance.erasures.actions.load_more') }}
          </v-btn>
        </div>
      </template>
    </v-data-table>

    <ErasureHoldDialog
      v-model="dialogOpen"
      :mode="dialogMode"
      :is-saving="dialogSaving"
      :error-key="dialogErrorKey"
      @confirm="confirmDialog"
    />

    <ErasureEraseDialog
      v-model="eraseOpen"
      :user-ulid="eraseTarget?.id ?? null"
      :plan="erasePlan"
      :plan-loading="planLoading"
      :plan-error-key="planErrorKey"
      :is-saving="eraseSaving"
      :error-key="eraseErrorKey"
      :error-codes="eraseErrorCodes"
      @confirm="confirmErase"
    />
  </section>
</template>
