/**
 * Admin SPA sidebar navigation model (Sprint 13, D-1).
 *
 * Mirrors the Phase-1 sidebar in `docs/09-ADMIN-PANEL.md` § 5.3, scoped
 * to the Sprint-13 surface. The nav is a declarative data structure so
 * adding a module is a data change, not a template change — the same
 * discipline the route table follows.
 *
 * Each leaf carries:
 *   - `key`        — i18n + data-test suffix.
 *   - `routeName`  — the Vue Router route it links to.
 *   - `icon`       — mdi icon.
 *   - `badge`      — optional badge-count key the layout reads off the
 *                    nav-badge store (Sprint 13: pending approvals + KYC
 *                    queue depth). `undefined` = no badge.
 *   - `comingSoon` — `true` for the payment-touching placeholders shipped
 *                    this sprint (D-13). Rendered with a muted "soon"
 *                    affordance; the route still resolves to a
 *                    coming-soon page (a discrete swappable block S10
 *                    replaces, not unpicks).
 *   - `external`   — `true` for links that leave the SPA (Horizon embed,
 *                    D-8) — rendered as an `<a href>` rather than a
 *                    router link.
 */

export type NavBadgeKey = 'creatorApprovals' | 'kycQueue'

export interface NavLeaf {
  key: string
  routeName: string
  icon: string
  badge?: NavBadgeKey
  comingSoon?: boolean
  external?: boolean
  href?: string
}

export interface NavGroup {
  key: string
  icon: string
  children: NavLeaf[]
}

export type NavEntry = NavLeaf | NavGroup

export function isNavGroup(entry: NavEntry): entry is NavGroup {
  return 'children' in entry
}

/**
 * The Sprint-13 sidebar. Order matches the spec's § 5.3 listing,
 * trimmed to the modules in scope this sprint plus the coming-soon
 * payment surfaces (D-13).
 */
export const NAV_ENTRIES: ReadonlyArray<NavEntry> = [
  { key: 'dashboard', icon: 'mdi-view-dashboard-outline', routeName: 'app.dashboard' },
  { key: 'agencies', icon: 'mdi-office-building-outline', routeName: 'app.agencies.list' },
  {
    key: 'creators',
    icon: 'mdi-account-star-outline',
    children: [
      {
        key: 'creatorApprovals',
        icon: 'mdi-account-clock-outline',
        routeName: 'app.creators.list',
        badge: 'creatorApprovals',
      },
      {
        key: 'kycQueue',
        icon: 'mdi-card-account-details-outline',
        routeName: 'app.creators.kyc',
        badge: 'kycQueue',
      },
      { key: 'allCreators', icon: 'mdi-account-multiple-outline', routeName: 'app.creators.all' },
    ],
  },
  {
    key: 'payments',
    icon: 'mdi-credit-card-outline',
    children: [
      {
        key: 'disputes',
        icon: 'mdi-gavel',
        routeName: 'app.payments.disputes',
        comingSoon: true,
      },
      {
        key: 'recentPayments',
        icon: 'mdi-cash-multiple',
        routeName: 'app.payments.recent',
        comingSoon: true,
      },
    ],
  },
  { key: 'audit', icon: 'mdi-clipboard-text-clock-outline', routeName: 'app.audit.list' },
  { key: 'alerts', icon: 'mdi-bell-alert-outline', routeName: 'app.alerts.list' },
  {
    key: 'support',
    icon: 'mdi-lifebuoy',
    children: [
      { key: 'userSearch', icon: 'mdi-account-search-outline', routeName: 'app.support.search' },
      {
        key: 'impersonationLog',
        icon: 'mdi-account-switch-outline',
        routeName: 'app.support.impersonation-log',
      },
    ],
  },
  {
    key: 'operations',
    icon: 'mdi-server',
    children: [
      { key: 'systemHealth', icon: 'mdi-heart-pulse', routeName: 'app.operations.health' },
      {
        key: 'horizon',
        icon: 'mdi-chart-timeline-variant',
        routeName: 'app.operations.health',
        external: true,
        href: '/horizon',
      },
    ],
  },
  {
    key: 'compliance',
    icon: 'mdi-shield-account-outline',
    children: [
      {
        key: 'exportRequests',
        icon: 'mdi-database-export-outline',
        routeName: 'app.compliance.exports',
      },
      {
        key: 'erasureQueue',
        icon: 'mdi-database-remove-outline',
        routeName: 'app.compliance.erasures',
      },
    ],
  },
  { key: 'featureFlags', icon: 'mdi-flag-outline', routeName: 'app.feature-flags.list' },
]
