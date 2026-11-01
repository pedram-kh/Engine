/**
 * S1.5 pins: surfaces and data (cards, banners, tables, tabs, chips, progress,
 * dividers) and the three overlays, read off both probe pages under both agency themes.
 * See pins-s14.mjs for the pin shapes.
 */

const STEP = 'S1.5'
const PROBE = (id) => `[data-test="${id}"]`
const TABLE_TH = `${PROBE('probe-table')} .v-table__wrapper > table > thead > tr > th`

const on = (name, sel, expect, extra = {}) => ({ step: STEP, name, sel, expect, ...extra })
const extra = (name, sel, expect, more = {}) => on(name, sel, expect, { page: 'extra', ...more })

const OPEN_MENU = { act: [['click', PROBE('probe-menu-btn')]], undo: [['press', 'Escape']] }
const OPEN_DIALOG = { act: [['click', PROBE('probe-dialog-btn')]], undo: [['press', 'Escape']] }
const OPEN_TIP = { act: [['hover', PROBE('probe-tooltip-btn')]], undo: [['hover', 'body']] }

export const S15_PINS = [
  // ── Cards ────────────────────────────────────────────────────────────────
  on('card: surface, 1px border, radius 10, no shadow', PROBE('probe-card'), (c) => ({
    backgroundColor: c.surface,
    borderTopWidth: '1px',
    borderTopColor: c.border,
    borderRadius: '10px',
    boxShadow: 'none',
  })),
  on('card title is H4 with 20 padding', `${PROBE('probe-card')} .v-card-title`, () => ({
    fontSize: '15px',
    fontWeight: '600',
    lineHeight: '20px',
    paddingLeft: '20px',
    paddingTop: '20px',
  })),
  on('card text is Body with 20 padding', `${PROBE('probe-card')} .v-card-text`, () => ({
    fontSize: '14px',
    lineHeight: '20px',
    paddingLeft: '20px',
    paddingBottom: '20px',
  })),

  // ── Banner ───────────────────────────────────────────────────────────────
  on('info banner: 16% tint, status border, radius 10, status text', PROBE('probe-alert'), (c) => ({
    backgroundColor: 'rgba(76, 141, 255, 0.16)',
    borderTopColor: c.info,
    borderTopWidth: '1px',
    borderRadius: '10px',
    color: c.infoText,
  })),

  // ── Table ────────────────────────────────────────────────────────────────
  on('table: bordered radius-10 box', PROBE('probe-table'), (c) => ({
    borderTopColor: c.border,
    borderTopWidth: '1px',
    borderRadius: '10px',
  })),
  on('table header: 34 high, Caption strong, secondary text', `${TABLE_TH}:first-child`, (c) => ({
    height: '34px',
    fontSize: '12px',
    fontWeight: '500',
    color: c.onSurfaceVariant,
  })),
  on(
    'table row hover fill',
    `${PROBE('probe-table')} tbody > tr:first-child > td:first-child`,
    (c) => ({ backgroundColor: c.raised }),
    {
      act: [['hover', `${PROBE('probe-table')} tbody > tr:first-child > td:first-child`]],
      undo: [['hover', 'body']],
    },
  ),

  // ── Tabs ─────────────────────────────────────────────────────────────────
  on('tabs: 1px rule under the bar', PROBE('probe-tabs'), (c) => ({
    borderBottomWidth: '1px',
    borderBottomColor: c.border,
  })),
  on(
    'tab selected: accent label, square, Body strong',
    `${PROBE('probe-tabs')} .v-tab--selected`,
    (c) => ({
      color: c.primaryText,
      borderRadius: '0px',
      fontSize: '14px',
      fontWeight: '500',
    }),
  ),
  on('tab idle: secondary label', `${PROBE('probe-tabs')} .v-tab:not(.v-tab--selected)`, (c) => ({
    color: c.onSurfaceVariant,
  })),
  on('tab slider is the accent', `${PROBE('probe-tabs')} .v-tab__slider`, (c) => ({
    backgroundColor: c.primary,
  })),

  // ── Chips, progress, divider ─────────────────────────────────────────────
  on('chip baseline is Body small', `${PROBE('probe-toggles')} .v-chip`, () => ({
    fontSize: '13px',
    lineHeight: '19px',
  })),
  on(
    'progress takes the brand colour, pill ends',
    `${PROBE('probe-toggles')} .v-progress-linear`,
    (c) => ({
      color: c.primary,
      borderRadius: '999px',
    }),
  ),
  extra(
    'choice chip at rest: surface pill, default border',
    `${PROBE('extra-chips')} .v-chip:not(.v-chip--selected)`,
    (c) => ({
      backgroundColor: c.surface,
      borderTopColor: c.border,
      color: c.onSurfaceVariant,
    }),
  ),
  extra(
    'choice chip selected: container fill, accent border and label',
    `${PROBE('extra-chips')} .v-chip--selected`,
    (c) => ({
      backgroundColor: c.primaryContainer,
      borderTopColor: c.primary,
      color: c.primaryText,
    }),
  ),
  extra('divider is border-default at full strength', PROBE('extra-divider'), (c) => ({
    borderTopColor: c.border,
    opacity: '1',
  })),
  on(
    'table row rule is border-default',
    `${PROBE('probe-table')} tbody > tr:first-child > td:first-child`,
    (c) => ({
      borderBottomColor: c.border,
    }),
  ),

  // ── Overlays ─────────────────────────────────────────────────────────────
  on(
    'menu: radius 10 and the overlay shadow on the wrapper',
    '.v-overlay-container .v-menu > .v-overlay__content',
    (c) => ({ borderRadius: '10px', boxShadow: c.overlayShadow }),
    OPEN_MENU,
  ),
  on(
    'menu content: raised surface and 1px border',
    `.v-overlay-container ${PROBE('probe-menu-card')}`,
    (c) => ({ backgroundColor: c.raised, borderTopColor: c.border, borderTopWidth: '1px' }),
    OPEN_MENU,
  ),
  on(
    'dialog: raised surface, border, radius 10, overlay shadow',
    `.v-overlay-container ${PROBE('probe-dialog-card')}`,
    (c) => ({
      backgroundColor: c.raised,
      borderTopColor: c.border,
      borderRadius: '10px',
      boxShadow: c.overlayShadow,
    }),
    OPEN_DIALOG,
  ),
  on(
    'dialog text is Body with 20 padding, title aligned with it',
    `.v-overlay-container ${PROBE('probe-dialog-card')} .v-card-text`,
    () => ({ fontSize: '14px', lineHeight: '20px', paddingLeft: '20px', paddingBottom: '20px' }),
    OPEN_DIALOG,
  ),
  on(
    'dialog title shares the 20 left edge',
    `.v-overlay-container ${PROBE('probe-dialog-card')} .v-card-title`,
    () => ({ paddingLeft: '20px' }),
    OPEN_DIALOG,
  ),
  on(
    'tooltip: raised surface, border, radius 6, Caption',
    '.v-overlay-container .v-tooltip > .v-overlay__content',
    (c) => ({
      backgroundColor: c.raised,
      borderTopColor: c.border,
      borderRadius: '6px',
      fontSize: '12px',
      lineHeight: '16px',
      color: c.onSurface,
    }),
    OPEN_TIP,
  ),
]
