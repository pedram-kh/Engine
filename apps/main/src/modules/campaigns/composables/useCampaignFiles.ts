/**
 * `useCampaignFiles` — the files attached to a campaign, read-only (AH-116, item 6).
 *
 * A thin read over the existing `campaignsApi.listAttachments` (unpaginated, `view`-gated, so
 * every agency member can read it). It exists so two surfaces that only SHOW files, the Overview
 * strip and the campaign chat's pinned strip, do not each re-implement the fetch. There is no
 * upload, toggle or detach here: those stay in the Attachments tab.
 *
 * The strip is an extra, never the point of the page, so a failed load is silent: it leaves the
 * list empty (and the strip undrawn) rather than putting an error over a page that works.
 */

import type { CampaignAttachmentResource } from '@catalyst/api-client'
import { ref, type Ref } from 'vue'

import { campaignsApi } from '../api/campaigns.api'

export interface CampaignFiles {
  files: Ref<CampaignAttachmentResource[]>
  /** Re-reads the list. Resolves (never rejects) once the newest request has settled. */
  load: () => Promise<void>
}

export function useCampaignFiles(
  agencyId: () => string | null,
  campaignId: () => string,
): CampaignFiles {
  const files = ref<CampaignAttachmentResource[]>([])
  // Only the newest request may write: a slow earlier response must not overwrite a fresher list.
  let latest = 0

  async function load(): Promise<void> {
    const agency = agencyId()
    if (agency === null) {
      files.value = []
      return
    }

    const request = ++latest
    try {
      const res = await campaignsApi.listAttachments(agency, campaignId())
      if (request === latest) files.value = res.data
    } catch {
      if (request === latest) files.value = []
    }
  }

  return { files, load }
}
