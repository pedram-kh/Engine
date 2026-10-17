/**
 * How a campaign's lifecycle state reads to a CREATOR (AH-107).
 *
 * One table, one place: the assignment-card chip, the assignment-detail chip and
 * banner, and the job-card/detail Paused chip all read this, so they cannot
 * disagree about what a status is called, what colour it is, or where it shows.
 *
 * The server sends the RAW enum value (`draft | active | paused | completed |
 * cancelled`, plan D1) and the client owns every word. `draft` is shown as
 * "Upcoming" (Q1): a creator invited to a draft campaign is not looking at an
 * unfinished document, they are looking at a campaign that has not started.
 *
 * DISPLAY ONLY (Q2). Nothing here gates an action; a banner is information, not
 * a lock. The tech-debt row "campaign status does not gate creator actions"
 * carries that decision, and the Pest suite pins it.
 *
 * `showOnJob` encodes Q3: job surfaces show **Paused only**. Active and
 * Upcoming are the unremarkable case on a board of open jobs, and completed /
 * cancelled campaigns never reach it (the board filters them at read time,
 * `Campaign::LISTABLE_STATUSES`).
 */

import type { CampaignStatus } from '@catalyst/api-client'

export interface CampaignStatusPresentation {
  /** i18n key for the chip label. */
  labelKey: string
  /** Vuetify colour; `undefined` is the neutral tonal chip. */
  color: string | undefined
  /** i18n key for the assignment-detail banner, or null when there is none. */
  bannerKey: string | null
  /** Whether the Jobs Board card/detail shows this status (Q3: Paused only). */
  showOnJob: boolean
}

/**
 * Keyed by the whole enum on purpose: a `Record<CampaignStatus, …>` is a compile
 * error the day a sixth status is added, and the spec iterates the same list.
 */
const PRESENTATION: Record<CampaignStatus, CampaignStatusPresentation> = {
  draft: {
    labelKey: 'creator.ui.campaignStatus.upcoming',
    color: undefined,
    bannerKey: null,
    showOnJob: false,
  },
  active: {
    labelKey: 'creator.ui.campaignStatus.active',
    color: 'success',
    bannerKey: null,
    showOnJob: false,
  },
  paused: {
    labelKey: 'creator.ui.campaignStatus.paused',
    color: 'warning',
    bannerKey: 'creator.ui.campaignStatusBanner.paused',
    showOnJob: true,
  },
  completed: {
    labelKey: 'creator.ui.campaignStatus.completed',
    color: undefined,
    bannerKey: 'creator.ui.campaignStatusBanner.completed',
    showOnJob: false,
  },
  cancelled: {
    labelKey: 'creator.ui.campaignStatus.cancelled',
    color: 'error',
    bannerKey: 'creator.ui.campaignStatusBanner.cancelled',
    showOnJob: false,
  },
}

export const CAMPAIGN_STATUSES = Object.keys(PRESENTATION) as CampaignStatus[]

/**
 * `null` for an absent or unrecognised value, so a caller renders NOTHING rather
 * than a raw key. Absent is real: an older backend does not send the field, and
 * the SPA is deployed independently of it.
 */
export function campaignStatusPresentation(status: unknown): CampaignStatusPresentation | null {
  if (typeof status !== 'string') return null
  return Object.prototype.hasOwnProperty.call(PRESENTATION, status)
    ? PRESENTATION[status as CampaignStatus]
    : null
}
