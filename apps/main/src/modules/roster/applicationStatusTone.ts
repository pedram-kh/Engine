/**
 * AH-114 (L4): the tone of a creator's application status on the agency roster and the creator
 * detail. The shared tone table (`agency/status/agencyStatusTones.ts`) has no row for it, so the
 * two pages share this one mapping and hand the result to `AgencyStatusPill`'s `tone` + `label`.
 */
import type { AgencyStatusTone } from '@/modules/agency/status/agencyStatusTones'

export function applicationStatusTone(status: string): AgencyStatusTone {
  switch (status) {
    case 'approved':
      return 'success'
    case 'pending':
      return 'warning'
    case 'rejected':
      return 'danger'
    default:
      return 'neutral'
  }
}
