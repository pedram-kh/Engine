/**
 * AH-114 L4 — the agency status-pill tone of a draft round. Lives under `modules/agency/`
 * because `campaigns/draftRounds.ts` is reachable from the creator tree and may not import
 * agency code (spec 2). It reads the SAME mapping as `roundStateColor`, so the pill and the
 * card tint never disagree.
 */

import type { AssignmentStatus, DraftReviewStatus } from '@catalyst/api-client'

import { roundStateColor } from '@/modules/campaigns/draftRounds'

import type { AgencyStatusTone } from './agencyStatusTones'

export function roundStateTone(
  reviewStatus: DraftReviewStatus,
  assignmentStatus?: AssignmentStatus | null,
): AgencyStatusTone {
  switch (roundStateColor(reviewStatus, assignmentStatus)) {
    case 'success':
      return 'success'
    case 'warning':
      return 'warning'
    case 'error':
      return 'danger'
    case 'info':
      return 'info'
    default:
      return 'neutral'
  }
}
