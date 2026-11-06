/**
 * AH-119 — the unified inbox's filters as route query: `?brand=a,b&campaign=c&unread=1&owner=me`.
 * The filters live in the URL so a reload or a back navigation keeps them, and the thread routes
 * (children of the inbox) carry them along so opening a conversation does not reset the list.
 */

import type { LocationQuery, LocationQueryRaw } from 'vue-router'

import type { InboxFilters } from './useMessageInbox'

export const NO_FILTERS: InboxFilters = { brand: [], campaign: [], unread: false, owner: 'all' }

function one(value: LocationQuery[string] | undefined): string {
  const first = Array.isArray(value) ? value[0] : value
  return typeof first === 'string' ? first : ''
}

function list(value: LocationQuery[string] | undefined): string[] {
  return one(value)
    .split(',')
    .map((part) => part.trim())
    .filter((part) => part !== '')
}

export function filtersFromQuery(query: LocationQuery): InboxFilters {
  return {
    brand: list(query.brand),
    campaign: list(query.campaign),
    unread: one(query.unread) === '1',
    owner: one(query.owner) === 'me' ? 'me' : 'all',
  }
}

/** Only the keys that differ from "no filter", so a clean inbox has a clean URL. */
export function filtersToQuery(filters: InboxFilters): LocationQueryRaw {
  const out: LocationQueryRaw = {}
  if (filters.brand.length > 0) {
    out.brand = filters.brand.join(',')
  }
  if (filters.campaign.length > 0) {
    out.campaign = filters.campaign.join(',')
  }
  if (filters.unread) {
    out.unread = '1'
  }
  if (filters.owner === 'me') {
    out.owner = 'me'
  }
  return out
}

/** The filter keys of a query, for carrying them onto a thread link or back to the inbox. */
export function filterQueryOf(query: LocationQuery): LocationQueryRaw {
  return filtersToQuery(filtersFromQuery(query))
}
