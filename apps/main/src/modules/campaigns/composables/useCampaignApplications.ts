/**
 * The campaign-applications list, once (AH-059, S7a).
 *
 * Two surfaces read the same rows now: the campaign-detail Applications tab (the
 * full history, filterable) and the board's Applications column (D4 — pending
 * only, a working surface). Both need the same fetch, the same pending badge
 * number, the same refusal slot and the same refetch-after-answer, and the
 * cheapest way for those to disagree is for each to own its own copy.
 *
 * What lives here is LIST state. The two answers do not: accepting and rejecting
 * are owned by {@link AcceptApplicationDialog} and {@link RejectApplicationDialog},
 * which each own their call, their payload and their refusal mapping. That split
 * is chunk 4's and it holds — a composable that also performed the writes would
 * make every consumer inherit both dialogs' concerns.
 */

import type {
  CampaignApplicationListItemResource,
  CampaignApplicationListParams,
  CampaignApplicationStatus,
} from '@catalyst/api-client'
import { computed, ref } from 'vue'

import { campaignsApi } from '../api/campaigns.api'

export type ApplicationsFilter = 'all' | CampaignApplicationStatus

export interface UseCampaignApplicationsOptions {
  /**
   * Rows per page. The tab paginates at 25; the board column asks for a small
   * page because a column is a working surface, not an archive.
   */
  perPage?: number
  /**
   * A fixed status filter. The board column pins `'pending'` — its whole premise
   * is "what still needs an answer" — while the tab leaves it settable so the
   * full history stays reachable.
   */
  initialFilter?: ApplicationsFilter
  /**
   * Called after every SUCCESSFUL fetch with `meta.pending_total`.
   *
   * A callback rather than a `watch` on the ref, because the meaningful event is
   * the fetch, not the change: a campaign whose pending count is genuinely 0 must
   * still announce it, and a watcher would stay silent because 0 is the initial
   * value. That silence is what a tab badge showing a stale count looks like.
   */
  onLoaded?: (pendingTotal: number) => void
}

export function useCampaignApplications(
  agencyId: () => string,
  campaignId: () => string,
  options: UseCampaignApplicationsOptions = {},
) {
  const perPage = options.perPage ?? 25

  const rows = ref<CampaignApplicationListItemResource[]>([])
  const loading = ref(false)
  const loadError = ref(false)
  const page = ref(1)
  const lastPage = ref(1)
  const filter = ref<ApplicationsFilter>(options.initialFilter ?? 'all')

  /**
   * `meta.pending_total`, never the creator-facing `applicant_count`: that number
   * is INTEREST semantics ("how many creators applied"), and using it as a badge
   * would put a permanent unclearable count on every campaign that ever had an
   * application.
   */
  const pendingTotal = ref(0)

  /** Where a dialog's refusal is rendered — surfaced, never swallowed (§5.6). */
  const actionError = ref<string | null>(null)

  const hasRows = computed(() => rows.value.length > 0)

  /**
   * @param initial Show the skeleton. Only on the first load of an empty list: a
   *   refetch after an answer must not blank the rows the operator is reading.
   */
  async function load(initial = false): Promise<void> {
    loading.value = initial && rows.value.length === 0

    try {
      const params: CampaignApplicationListParams = { page: page.value, per_page: perPage }
      if (filter.value !== 'all') {
        params.status = filter.value
      }

      const res = await campaignsApi.listApplications(agencyId(), campaignId(), params)
      rows.value = res.data
      lastPage.value = res.meta.last_page
      loadError.value = false
      pendingTotal.value = res.meta.pending_total
      options.onLoaded?.(res.meta.pending_total)
    } catch {
      // A failed REFETCH keeps the rows on screen: they are stale, not wrong, and
      // the error alert is a worse answer than slightly old data. Only a failed
      // FIRST load has nothing to fall back to.
      if (rows.value.length === 0) {
        loadError.value = true
      }
    } finally {
      loading.value = false
    }
  }

  /** Change the filter and go back to page 1 — page 3 of a new filter is nothing. */
  async function setFilter(next: ApplicationsFilter): Promise<void> {
    filter.value = next
    page.value = 1
    await load(true)
  }

  async function setPage(next: number): Promise<void> {
    page.value = next
    await load()
  }

  function isPending(row: CampaignApplicationListItemResource): boolean {
    return row.attributes.status === 'pending'
  }

  return {
    rows,
    loading,
    loadError,
    page,
    lastPage,
    filter,
    pendingTotal,
    actionError,
    hasRows,
    load,
    setFilter,
    setPage,
    isPending,
  }
}
