/**
 * AH-115 L0.1 — the agency stops for `walk.mjs --set agency`.
 *
 * The 24 `meta.layout: 'agency'` routes (the same list `walk-l4.mjs` walks), each opened as the
 * REAL page over the mock API (`l3-fixtures.mjs`) inside the real agency shell. `pageFor` mirrors
 * `walk-l4.mjs`: a route without an entry at the requested width reuses its 1600 entry.
 *
 * Dev tool only.
 */

import { REAL_PAGES } from './l3-fixtures.mjs'

export const AGENCY_ROUTES = [
  ['/', 'dashboard'],
  ['/roster', 'roster'],
  ['/roster/:ulid', 'roster-detail'],
  ['/messages', 'messages'],
  ['/messages/:creatorUlid', 'messages-thread'],
  ['/discover', 'discover'],
  ['/discover/:ulid', 'discover-profile'],
  ['/brands', 'brands'],
  ['/brands/new', 'brand-create'],
  ['/brands/:ulid', 'brand-detail'],
  ['/brands/:ulid/edit', 'brand-edit'],
  ['/campaigns', 'campaigns'],
  ['/campaigns/new', 'campaign-create'],
  ['/campaigns/:ulid', 'campaign-detail'],
  ['/talent-pools', 'pools'],
  ['/talent-pools/new', 'pool-create'],
  ['/talent-pools/:ulid', 'pool-detail'],
  ['/talent-pools/:ulid/edit', 'pool-edit'],
  ['/files', 'files'],
  ['/agency-users', 'team'],
  ['/creator-invitations/bulk', 'bulk-invite'],
  ['/settings', 'settings'],
  ['/notifications', 'notifications'],
  ['/notifications/preferences', 'notification-prefs'],
]

/** The two widths the agency baseline records (desktop and phone). */
export const AGENCY_WIDTHS = { desktop: 1280, mobile: 390 }

export function agencyPageFor(slug, width) {
  const entry = REAL_PAGES[`${slug}-${width}`] ?? REAL_PAGES[`${slug}-1600`]
  if (!entry) return null
  return { ...entry, width, height: width === 390 ? 844 : 800 }
}
