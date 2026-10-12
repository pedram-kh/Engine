/**
 * The campaign detail page's tabs that a deep link may open (AH-103).
 *
 * A notification for an agency user names a tab — `?tab=drafts` for a submitted
 * draft, `?tab=applications` for a new application — and the page opens on it.
 * The list is a WHITELIST: anything not on it (a typo, a stale link, `settings`,
 * which only some members can see) opens the default tab instead of an empty
 * panel. `settings` is deliberately absent: no notification points there, and a
 * link must never expose a tab the viewer's role hides.
 *
 * Kept in a `.ts` file (not the SFC) so the notification registry's spec can pin
 * its destinations to the same list the page uses.
 */
export const CAMPAIGN_DETAIL_TABS = [
  'overview',
  'attachments',
  'creators',
  'board',
  'drafts',
  'applications',
  'payments',
  'messages',
] as const

export type CampaignDetailTab = (typeof CAMPAIGN_DETAIL_TABS)[number]

export const DEFAULT_CAMPAIGN_TAB: CampaignDetailTab = 'overview'

/** A `?tab=` value → a whitelisted tab, or `null` (the first of a repeated key counts). */
export function campaignTabFromQuery(value: unknown): CampaignDetailTab | null {
  const first = Array.isArray(value) ? value[0] : value

  return typeof first === 'string' && (CAMPAIGN_DETAIL_TABS as readonly string[]).includes(first)
    ? (first as CampaignDetailTab)
    : null
}
