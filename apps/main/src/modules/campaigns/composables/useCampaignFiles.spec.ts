/**
 * AH-116 (item 6) — `useCampaignFiles`: a read-only fetch of a campaign's attachments. Pins that
 * a failure is silent, and that only the newest request may write (a slow earlier response must
 * not overwrite a fresher list).
 */

import type { CampaignAttachmentResource } from '@catalyst/api-client'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api/campaigns.api', () => ({
  campaignsApi: { listAttachments: vi.fn() },
}))

import { campaignsApi } from '../api/campaigns.api'
import { useCampaignFiles } from './useCampaignFiles'

function file(id: string): CampaignAttachmentResource {
  return {
    id,
    type: 'campaign_attachments',
    attributes: {
      original_filename: `${id}.pdf`,
      mime_type: 'application/pdf',
      size_bytes: 1,
      visible_to_creators: true,
      download_url: null,
      attached_at: '2026-10-01T10:00:00Z',
    },
    relationships: { attached_by: null },
  }
}

describe('useCampaignFiles (AH-116)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it("reads the campaign's attachments for the agency", async () => {
    vi.mocked(campaignsApi.listAttachments).mockResolvedValue({ data: [file('a')] })
    const { files, load } = useCampaignFiles(
      () => 'agency-1',
      () => 'campaign-1',
    )

    await load()

    expect(campaignsApi.listAttachments).toHaveBeenCalledWith('agency-1', 'campaign-1')
    expect(files.value.map((f) => f.id)).toEqual(['a'])
  })

  it('does not call the API without an agency, and leaves the list empty', async () => {
    const { files, load } = useCampaignFiles(
      () => null,
      () => 'campaign-1',
    )

    await load()

    expect(campaignsApi.listAttachments).not.toHaveBeenCalled()
    expect(files.value).toEqual([])
  })

  it('a failed read is silent and empties the list rather than throwing', async () => {
    vi.mocked(campaignsApi.listAttachments).mockResolvedValueOnce({ data: [file('a')] })
    const { files, load } = useCampaignFiles(
      () => 'agency-1',
      () => 'campaign-1',
    )
    await load()
    expect(files.value).toHaveLength(1)

    vi.mocked(campaignsApi.listAttachments).mockRejectedValueOnce(new Error('network down'))
    await expect(load()).resolves.toBeUndefined()
    expect(files.value).toEqual([])
  })

  it('a slow earlier response never overwrites a newer one', async () => {
    let releaseSlow: (value: { data: CampaignAttachmentResource[] }) => void = () => {}
    vi.mocked(campaignsApi.listAttachments)
      .mockReturnValueOnce(
        new Promise((resolve) => {
          releaseSlow = resolve
        }),
      )
      .mockResolvedValueOnce({ data: [file('fresh')] })
    const { files, load } = useCampaignFiles(
      () => 'agency-1',
      () => 'campaign-1',
    )

    const slow = load()
    await load()
    expect(files.value.map((f) => f.id)).toEqual(['fresh'])

    releaseSlow({ data: [file('stale')] })
    await slow
    expect(files.value.map((f) => f.id)).toEqual(['fresh'])
  })
})
