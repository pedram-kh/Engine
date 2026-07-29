<script setup lang="ts">
/**
 * Campaign list page (Sprint 8 Chunk 1) — server-side paginated table with
 * brand / status / date filters. Mirrors BrandListPage. Any agency member may
 * view; the Create button is shown to all but the backend gates create to
 * admin/manager (a staff member's POST 403s).
 *
 * ── The Job board column is INTERACTIVE (AH-059, D3) ────────────────────────
 *
 * AH-057 gave this column a read-only chip. It is now a switch driving the same
 * `PATCH /agencies/{agency}/campaigns/{campaign}` endpoint the Settings tab
 * drives, with the same two gates in front of it, because listing a campaign
 * from the row you are already looking at is the natural gesture and walking
 * into Settings to flip one boolean is not.
 *
 * The two surfaces do NOT re-derive the gates. Both consult
 * {@link missingListingFloorFields}, the single SPA mirror of the backend trait
 * (itself pinned against the PHP source by `listing-floor-parity.spec.ts`), and
 * both send the same key to the same endpoint, whose 422 is the authority. What
 * differs is only the AFFORDANCE, and the difference is deliberate:
 *
 *   - Settings holds the whole listing form, so it can DISABLE the toggle and
 *     name the missing fields inline before anything is attempted.
 *   - A table row has no room for that, so the switch stays live and the refusal
 *     is EXPLICIT — a dialog naming exactly which fields are missing, with the
 *     way to go fill them. Never a silently-reverted switch, which reads as a
 *     broken control.
 *
 * The ON direction additionally confirms, because it is the direction with a
 * side effect a user cannot take back: `false → true` fans out a job-posted
 * notification to every rostered creator. OFF stays immediate — delisting is
 * reversible and urgent (it is what you reach for when something is wrong).
 */

import { ApiError, extractFieldErrors, formatCurrency } from '@catalyst/api-client'
import type { BrandResource, CampaignListParams, CampaignResource } from '@catalyst/api-client'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { CEmptyState } from '@catalyst/ui'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { brandsApi } from '@/modules/brands/api/brands.api'
import { campaignsApi } from '../api/campaigns.api'
import { type ListingFloorField, missingListingFloorFields } from '../listingFloor'

const { t, locale } = useI18n()
const agencyStore = useAgencyStore()

type StatusFilter = 'all' | 'draft' | 'active' | 'paused' | 'completed' | 'cancelled'

const statusFilter = ref<StatusFilter>('all')
const brandFilter = ref<string | null>(null)
const startsFrom = ref<string>('')
const startsTo = ref<string>('')

const items = ref<CampaignResource[]>([])
const totalItems = ref(0)
const loading = ref(false)
const error = ref<string | null>(null)
const brandOptions = ref<{ title: string; value: string }[]>([])

const tableOptions = ref({ page: 1, itemsPerPage: 25 })

const headers = [
  { title: t('app.campaigns.fields.name'), key: 'attributes.name', sortable: false },
  { title: t('app.campaigns.fields.brand'), key: 'relationships.brand.data.name', sortable: false },
  {
    title: t('app.campaigns.fields.status'),
    key: 'attributes.status',
    sortable: false,
    width: 120,
  },
  // AH-057 — listing state is ORTHOGONAL to lifecycle status, so it gets its
  // own column rather than a second chip in the status cell: a campaign can be
  // active-and-unlisted or active-and-listed, and conflating the two is what
  // makes a later "only listed" filter awkward to build.
  {
    title: t('app.campaigns.fields.jobBoard'),
    key: 'attributes.listed_on_jobs_board',
    sortable: false,
    width: 120,
  },
  {
    title: t('app.campaigns.fields.budget'),
    key: 'attributes.budget_minor_units',
    sortable: false,
    width: 140,
  },
  { title: '', key: 'actions', sortable: false, width: 80, align: 'end' as const },
]

const statusFilterItems: { label: string; value: StatusFilter }[] = [
  { label: t('app.campaigns.status.all'), value: 'all' },
  { label: t('app.campaigns.status.draft'), value: 'draft' },
  { label: t('app.campaigns.status.active'), value: 'active' },
  { label: t('app.campaigns.status.paused'), value: 'paused' },
  { label: t('app.campaigns.status.completed'), value: 'completed' },
  { label: t('app.campaigns.status.cancelled'), value: 'cancelled' },
]

const statusColor: Record<string, string> = {
  draft: 'default',
  active: 'success',
  paused: 'warning',
  completed: 'info',
  cancelled: 'error',
}

async function loadBrandOptions(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return
  try {
    const res = await brandsApi.list(agencyId, { per_page: 100, status: 'active' })
    brandOptions.value = res.data.map((b: BrandResource) => ({
      title: b.attributes.name,
      value: b.id,
    }))
  } catch {
    // The brand filter is a convenience; failing to populate it is non-fatal.
    brandOptions.value = []
  }
}

async function loadCampaigns(): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  loading.value = true
  error.value = null

  const params: CampaignListParams = {
    page: tableOptions.value.page,
    per_page: tableOptions.value.itemsPerPage,
  }
  if (statusFilter.value !== 'all') params.status = statusFilter.value
  if (brandFilter.value) params.brand = brandFilter.value
  if (startsFrom.value) params.starts_from = startsFrom.value
  if (startsTo.value) params.starts_to = startsTo.value

  try {
    const res = await campaignsApi.list(agencyId, params)
    items.value = res.data
    totalItems.value = res.meta.total
  } catch {
    error.value = t('app.campaigns.errors.loadFailed')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void loadBrandOptions()
  void loadCampaigns()
})

watch(
  () => agencyStore.currentAgencyId,
  (id) => {
    if (id !== null) {
      void loadBrandOptions()
      void loadCampaigns()
    }
  },
)

watch([statusFilter, brandFilter, startsFrom, startsTo], () => {
  tableOptions.value.page = 1
  void loadCampaigns()
})

function onTableUpdate(opts: { page: number; itemsPerPage: number }): void {
  tableOptions.value = opts
  void loadCampaigns()
}

function formatMoney(minor: number | null, currency: string | null): string {
  return formatCurrency(minor, currency, locale.value)
}

// ── The Job board switch (AH-059, D3) ───────────────────────────────────────

/** The row whose switch is mid-flight, so only that row's control spins. */
const listingBusyId = ref<string | null>(null)

/** The pending ON confirmation: the campaign awaiting an answer. */
const confirmTarget = ref<CampaignResource | null>(null)

/** The refusal being explained — either the floor or the terminal status. */
const refusal = ref<{
  campaignId: string
  campaignName: string
  reason: 'floor' | 'status'
  fields: string[]
} | null>(null)

const listingSnackbar = ref<{ color: string; text: string } | null>(null)

const confirmName = computed(() => confirmTarget.value?.attributes.name ?? '')

/**
 * The floor, evaluated against the row's OWN stored attributes.
 *
 * The list payload carries every floor field, so this is the same predicate over
 * the same data the Settings tab evaluates — not a looser approximation. It runs
 * before the request purely so the common refusal costs no round trip; the
 * server's 422 is still the authority and is handled below.
 */
function rowFloorMissing(item: CampaignResource): ListingFloorField[] {
  return missingListingFloorFields(item.attributes)
}

function isTerminal(item: CampaignResource): boolean {
  return item.attributes.status === 'completed' || item.attributes.status === 'cancelled'
}

/** Floor field keys → the localized names the refusal dialog lists. */
function floorFieldNames(fields: string[]): string {
  return fields.map((f) => t(`app.campaigns.listing.floorFields.${f}`)).join(', ')
}

/**
 * The switch's handler. Vuetify has already flipped the model optimistically, so
 * every path either commits the flip or is responsible for putting the row back.
 */
async function onListingToggle(item: CampaignResource, next: boolean | null): Promise<void> {
  const value = next === true

  if (value === item.attributes.listed_on_jobs_board) return

  // ── OFF: immediate, ungated. Delisting is reversible and it is what someone
  // reaches for when a listing is wrong, so it must not ask twice.
  if (!value) {
    await commitListing(item, false)
    return
  }

  // ── ON, gate 1: the listing floor. Same predicate as Settings.
  const missing = rowFloorMissing(item)
  if (missing.length > 0) {
    refuse(item, 'floor', missing)
    return
  }

  // ── ON, gate 2: terminal status. A completed or cancelled campaign cannot be
  // listed — there is nothing for a creator to apply to.
  if (isTerminal(item)) {
    refuse(item, 'status', [])
    return
  }

  // Both gates clear: ask, because this is the direction that notifies people.
  confirmTarget.value = item
}

function refuse(item: CampaignResource, reason: 'floor' | 'status', fields: string[]): void {
  refusal.value = {
    campaignId: item.id,
    campaignName: item.attributes.name,
    reason,
    fields,
  }
}

async function confirmListing(): Promise<void> {
  const item = confirmTarget.value
  confirmTarget.value = null
  if (item !== null) await commitListing(item, true)
}

/**
 * Declining the confirmation, or dismissing either dialog.
 *
 * Re-reads rather than assigning the boolean back. Vuetify flipped the switch on
 * click and this is the only branch where NOTHING was written, so the row on
 * screen is now the one thing in the app that is wrong — and the server is the
 * only place that knows what it should say.
 */
async function abandonListingFlip(): Promise<void> {
  confirmTarget.value = null
  refusal.value = null
  await loadCampaigns()
}

/**
 * The one write. A SINGLE-KEY PATCH — `{ listed_on_jobs_board }` and nothing
 * else — which is why this surface cannot overwrite anything the row does not
 * own: every other field is governed by the endpoint's `sometimes` rules and is
 * preserved by its own absence. Settings sends the whole form because Settings
 * IS the whole form.
 */
async function commitListing(item: CampaignResource, value: boolean): Promise<void> {
  const agencyId = agencyStore.currentAgencyId
  if (agencyId === null) return

  listingBusyId.value = item.id

  try {
    const res = await campaignsApi.update(agencyId, item.id, { listed_on_jobs_board: value })

    // Replace the row from the server's answer rather than patching the boolean
    // locally: the flip also stamps `listed_at`, and the response is the truth
    // about what happened.
    const index = items.value.findIndex((c) => c.id === item.id)
    if (index !== -1) items.value[index] = res.data

    listingSnackbar.value = {
      color: 'success',
      text: value
        ? t('app.campaigns.listing.toggle.listed', { name: item.attributes.name })
        : t('app.campaigns.listing.toggle.delisted', { name: item.attributes.name }),
    }
  } catch (err) {
    // The BACKSTOP, and the reason the local gates are a courtesy rather than the
    // rule: a row rendered before someone else emptied a floor field, or before
    // the campaign was cancelled, reaches a 422 here. The same dialog explains
    // it, populated from the server's own field errors.
    if (err instanceof ApiError && err.status === 422) {
      const grouped = extractFieldErrors<string>(err)
      const missing = rowFloorMissing(item)

      refuse(
        item,
        missing.length > 0 ? 'floor' : 'status',
        missing.length > 0
          ? missing
          : Object.keys(grouped).filter((k) => k !== 'listed_on_jobs_board'),
      )
    } else {
      listingSnackbar.value = { color: 'error', text: t('app.campaigns.errors.saveFailed') }
    }

    // Re-read the row either way: the switch has been flipped optimistically and
    // the only honest way to put it back is to ask what the server actually holds.
    await loadCampaigns()
  } finally {
    listingBusyId.value = null
  }
}
</script>

<template>
  <div data-test="campaign-list-page">
    <div class="d-flex align-center justify-space-between mb-4">
      <h1 class="text-h5 ma-0" data-test="campaign-list-heading">{{ t('app.campaigns.title') }}</h1>
      <v-btn
        color="primary"
        prepend-icon="mdi-plus"
        :to="{ name: 'campaigns.create' }"
        data-test="campaign-create-btn"
      >
        {{ t('app.campaigns.actions.create') }}
      </v-btn>
    </div>

    <!-- Filters -->
    <div class="d-flex flex-wrap align-center ga-3 mb-4" data-test="campaign-filters">
      <v-select
        v-model="brandFilter"
        :items="brandOptions"
        :label="t('app.campaigns.filters.brand')"
        item-title="title"
        item-value="value"
        density="compact"
        variant="outlined"
        hide-details
        clearable
        style="max-width: 220px"
        data-test="campaign-filter-brand"
      />
      <v-text-field
        v-model="startsFrom"
        :label="t('app.campaigns.filters.startsFrom')"
        type="date"
        density="compact"
        variant="outlined"
        hide-details
        style="max-width: 190px"
        data-test="campaign-filter-starts-from"
      />
      <v-text-field
        v-model="startsTo"
        :label="t('app.campaigns.filters.startsTo')"
        type="date"
        density="compact"
        variant="outlined"
        hide-details
        style="max-width: 190px"
        data-test="campaign-filter-starts-to"
      />
    </div>

    <v-chip-group v-model="statusFilter" mandatory class="mb-4" data-test="campaign-status-filter">
      <v-chip
        v-for="item in statusFilterItems"
        :key="item.value"
        :value="item.value"
        filter
        variant="outlined"
        :data-test="`campaign-filter-${item.value}`"
      >
        {{ item.label }}
      </v-chip>
    </v-chip-group>

    <v-alert v-if="error" type="error" variant="tonal" class="mb-4" data-test="campaign-list-error">
      {{ error }}
    </v-alert>

    <template v-if="loading && items.length === 0">
      <v-skeleton-loader type="table" data-test="campaign-list-skeleton" />
    </template>

    <template v-else-if="!loading && items.length === 0 && !error">
      <CEmptyState
        data-test="campaign-empty-state"
        title-tag="h2"
        :title="t('app.campaigns.empty.heading')"
        :body="t('app.campaigns.empty.body')"
      >
        <template #icon>
          <v-icon icon="mdi-bullhorn-outline" size="64" color="medium-emphasis" />
        </template>
        <template #action>
          <v-btn color="primary" :to="{ name: 'campaigns.create' }" data-test="campaign-empty-cta">
            {{ t('app.campaigns.empty.cta') }}
          </v-btn>
        </template>
      </CEmptyState>
    </template>

    <v-data-table-server
      v-else
      :headers="headers"
      :items="items"
      :items-length="totalItems"
      :loading="loading"
      :items-per-page="tableOptions.itemsPerPage"
      :page="tableOptions.page"
      item-value="id"
      data-test="campaign-table"
      @update:options="onTableUpdate"
    >
      <template #item.relationships.brand.data.name="{ item }">
        {{ item.relationships.brand.data.name }}
      </template>

      <template #item.attributes.status="{ item }">
        <v-chip
          :color="statusColor[item.attributes.status] ?? 'default'"
          size="small"
          variant="tonal"
          :data-test="`campaign-status-${item.id}`"
        >
          {{ t(`app.campaigns.status.${item.attributes.status}`) }}
        </v-chip>
      </template>

      <!-- AH-059 (D3) — a SWITCH, not a clickable chip. A chip that silently
           toggles is a control disguised as a label; a switch says "this is
           settable, and here is its state" in one glyph, which is also what the
           Settings tab uses for the same boolean. -->
      <template #item.attributes.listed_on_jobs_board="{ item }">
        <v-switch
          :model-value="item.attributes.listed_on_jobs_board"
          color="primary"
          density="compact"
          hide-details
          inset
          :loading="listingBusyId === item.id"
          :disabled="listingBusyId !== null"
          :aria-label="t('app.campaigns.listing.toggle.ariaLabel', { name: item.attributes.name })"
          :data-test="`campaign-job-board-toggle-${item.id}`"
          @update:model-value="(v) => onListingToggle(item, v as boolean | null)"
        />
      </template>

      <template #item.attributes.budget_minor_units="{ item }">
        {{ formatMoney(item.attributes.budget_minor_units, item.attributes.budget_currency) }}
      </template>

      <template #item.actions="{ item }">
        <v-btn
          icon="mdi-eye-outline"
          size="small"
          variant="text"
          :to="{ name: 'campaigns.detail', params: { ulid: item.id } }"
          :aria-label="t('app.campaigns.detail.title')"
          :data-test="`campaign-view-${item.id}`"
        />
      </template>
    </v-data-table-server>

    <!-- The ON confirmation (AH-059, D3). Names the campaign, because the row is
         about to leave the user's focus, and states the consequence: this is the
         flip that notifies every rostered creator, once, and there is no unsend. -->
    <v-dialog
      :model-value="confirmTarget !== null"
      max-width="480"
      data-test="campaign-listing-confirm"
      @update:model-value="
        (v) => {
          if (!v) void abandonListingFlip()
        }
      "
    >
      <v-card>
        <v-card-title>{{ t('app.campaigns.listing.toggle.confirmTitle') }}</v-card-title>
        <v-card-text>
          <p class="mb-2" data-test="campaign-listing-confirm-body">
            {{ t('app.campaigns.listing.toggle.confirmBody', { name: confirmName }) }}
          </p>
          <p class="text-medium-emphasis mb-0">
            {{ t('app.campaigns.listing.toggle.confirmNotice') }}
          </p>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn
            variant="text"
            data-test="campaign-listing-confirm-cancel"
            @click="abandonListingFlip()"
          >
            {{ t('app.campaigns.listing.toggle.cancel') }}
          </v-btn>
          <v-btn
            color="primary"
            variant="flat"
            data-test="campaign-listing-confirm-submit"
            @click="confirmListing()"
          >
            {{ t('app.campaigns.listing.toggle.confirm') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- The explicit refusal. The whole point of D3's failure UX: a switch that
         springs back with no explanation is indistinguishable from a bug, so the
         missing fields are NAMED and the way to go fill them is offered. -->
    <v-dialog
      :model-value="refusal !== null"
      max-width="480"
      data-test="campaign-listing-refusal"
      @update:model-value="
        (v) => {
          if (!v) void abandonListingFlip()
        }
      "
    >
      <v-card v-if="refusal">
        <v-card-title>{{ t('app.campaigns.listing.toggle.blockedTitle') }}</v-card-title>
        <v-card-text>
          <p
            v-if="refusal.reason === 'floor'"
            class="mb-0"
            data-test="campaign-listing-refusal-body"
          >
            {{
              t('app.campaigns.listing.toggle.blockedFloor', {
                name: refusal.campaignName,
                fields: floorFieldNames(refusal.fields),
              })
            }}
          </p>
          <p v-else class="mb-0" data-test="campaign-listing-refusal-body">
            {{ t('app.campaigns.listing.toggle.blockedStatus', { name: refusal.campaignName }) }}
          </p>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn
            variant="text"
            data-test="campaign-listing-refusal-close"
            @click="abandonListingFlip()"
          >
            {{ t('app.campaigns.listing.toggle.close') }}
          </v-btn>
          <!-- To the campaign, NOT to `?tab=settings`: the detail page's tab is
               local component state rather than a route parameter, so a tab link
               would be a promise the SPA does not keep. Making the tab
               addressable is recorded as a candidate, not smuggled in here. -->
          <v-btn
            v-if="refusal.reason === 'floor'"
            color="primary"
            variant="flat"
            :to="{ name: 'campaigns.detail', params: { ulid: refusal.campaignId } }"
            data-test="campaign-listing-refusal-fix"
          >
            {{ t('app.campaigns.listing.toggle.fix') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar
      :model-value="listingSnackbar !== null"
      :timeout="4000"
      :color="listingSnackbar?.color"
      data-test="campaign-listing-snackbar"
      @update:model-value="
        (v) => {
          if (!v) listingSnackbar = null
        }
      "
    >
      {{ listingSnackbar?.text }}
    </v-snackbar>
  </div>
</template>
