/**
 * S3.4 pins: the Messages page, read off the REAL `AgencyMessagesPage` and
 * `AgencyRelationshipThreadPage` over the L3 fixtures (`l3-fixtures.mjs`: five conversations,
 * Maya Thompson open) in both agency themes, at 1600 and at 1280. Values are the Figma numbers
 * (Messages frame 21:85): a 460px list pane on the sidebar fill with a right border, the thread
 * filling the rest, nothing padded around them. The inbox and thread internals are pinned in
 * S2.2 / S2.3; this step pins the page that joins them.
 */

const STEP = 'S3.4'
const LIST = 'real:messages-1600'
const LIST_NARROW = 'real:messages-1280'
const OPEN = 'real:messages-thread-1600'
const OPEN_NARROW = 'real:messages-thread-1280'

const pin = (page, name, sel, expect, more = {}) => ({
  step: STEP,
  name,
  sel,
  expect,
  page,
  ...more,
})
const each = (pages, name, sel, expect, more = {}) =>
  pages.map((page) =>
    pin(page, `${name} (${page.endsWith('1600') ? 1600 : 1280})`, sel, expect, more),
  )

const shell = '[data-test="agency-messages-page"]'
const listPane = '[data-test="messages-list-pane"]'
const detailPane = '[data-test="messages-detail-pane"]'
const row = (id) => `[data-test="relationship-inbox-row-${id}"]`
const MAYA = '01HCREATORMAYA00000000001'

export const S34_PINS = [
  // ── the shell is full bleed ───────────────────────────────────────────────────────────
  ...each(
    [LIST, LIST_NARROW, OPEN, OPEN_NARROW],
    'the content container has no padding on this route',
    '[data-test="agency-main"] .v-container',
    () => ({
      paddingTop: '0px',
      paddingRight: '0px',
      paddingBottom: '0px',
      paddingLeft: '0px',
    }),
  ),
  ...each(
    [OPEN, OPEN_NARROW],
    'the panes fill the viewport below the app bar',
    shell,
    () => ({
      top: 65,
      bottom: 1000,
      left: 280,
      right: 'viewport',
    }),
    {
      fn: `const r = document.querySelector('[data-test="agency-messages-page"]').getBoundingClientRect(); return { top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left), right: Math.abs(r.right - window.innerWidth) < 1 ? 'viewport' : String(r.right) }`,
    },
  ),

  // ── list pane (Figma 21:168) ──────────────────────────────────────────────────────────
  ...each(
    [LIST, LIST_NARROW, OPEN, OPEN_NARROW],
    'list pane is 460 wide',
    listPane,
    () => ({
      width: 460,
    }),
    {
      fn: `return { width: Math.round(document.querySelector('[data-test="messages-list-pane"]').getBoundingClientRect().width) }`,
    },
  ),
  ...each(
    [OPEN, OPEN_NARROW],
    'thread pane takes the rest',
    detailPane,
    () => ({
      left: 740,
      right: 'viewport',
    }),
    {
      fn: `const r = document.querySelector('[data-test="messages-detail-pane"]').getBoundingClientRect(); return { left: Math.round(r.left), right: Math.abs(r.right - window.innerWidth) < 1 ? 'viewport' : String(r.right) }`,
    },
  ),
  pin(OPEN, 'list pane: sidebar fill, 1px right border in the default colour', listPane, (c) => ({
    backgroundColor: c.sidebar,
    borderRightWidth: '1px',
    borderRightColor: c.border,
    borderLeftWidth: '0px',
  })),
  pin(OPEN, 'thread pane has no fill of its own', detailPane, () => ({
    backgroundColor: 'rgba(0, 0, 0, 0)',
  })),

  // ── the words the page hands the inbox ────────────────────────────────────────────────
  pin(
    OPEN,
    'search placeholder, unread pill and the two segments',
    listPane,
    () => ({
      placeholder: 'Search creators or messages',
      pill: '2 unread',
      all: 'All',
      unread: 'Unread',
    }),
    {
      fn: `const q = (s) => document.querySelector(s); return { placeholder: q('[data-test="agency-inbox-search"] input')?.getAttribute('placeholder') ?? 'MISSING', pill: q('[data-test="agency-inbox-unread-pill"]')?.textContent.trim() ?? 'MISSING', all: q('[data-test="agency-inbox-toggle-all"]')?.textContent.trim() ?? 'MISSING', unread: q('[data-test="agency-inbox-toggle-unread"]')?.textContent.trim() ?? 'MISSING' }`,
    },
  ),
  pin(
    OPEN,
    'the new-conversation button sits in the inbox header, once',
    listPane,
    () => ({
      inbox: 1,
      pageLevel: 0,
    }),
    {
      fn: `return { inbox: document.querySelectorAll('[data-test="agency-inbox-new"]').length, pageLevel: document.querySelectorAll('[data-test="agency-new-conversation"]').length }`,
    },
  ),

  // ── dates (ruling: today a time, this year "14 Mar", older "14 Mar 2020") ─────────────
  pin(
    OPEN,
    'a conversation from today shows a time',
    row(MAYA),
    () => ({
      date: /^\d{1,2}:\d{2}\s?(AM|PM)$/,
    }),
    {
      fn: `const t = document.querySelector('[data-test="relationship-inbox-row-01HCREATORMAYA00000000001"] .agency-inbox-row__time'); return { date: t ? t.textContent.trim() : 'MISSING' }`,
    },
  ),
  pin(
    OPEN,
    'a conversation from this year shows day and month only',
    row('01HCREATORPRIYA0000000003'),
    () => ({
      date: /^[A-Z][a-z]{2} \d{1,2}$/,
    }),
    {
      fn: `const t = document.querySelector('[data-test="relationship-inbox-row-01HCREATORPRIYA0000000003"] .agency-inbox-row__time'); return { date: t ? t.textContent.trim() : 'MISSING' }`,
    },
  ),
  pin(
    OPEN,
    'an older conversation shows the year',
    row('01HCREATORAMARA0000000005'),
    () => ({
      date: /^[A-Z][a-z]{2} \d{1,2}, \d{4}$/,
    }),
    {
      fn: `const t = document.querySelector('[data-test="relationship-inbox-row-01HCREATORAMARA0000000005"] .agency-inbox-row__time'); return { date: t ? t.textContent.trim() : 'MISSING' }`,
    },
  ),

  // ── the open conversation ─────────────────────────────────────────────────────────────
  pin(OPEN, 'the open conversation row: subtle fill, 1px accent border', row(MAYA), (c) => ({
    backgroundColor: c.primaryContainer,
    borderTopColor: c.primary,
    borderTopWidth: '1px',
  })),
  pin(
    OPEN,
    'the thread header names the open creator',
    detailPane,
    () => ({
      title: 'Maya Thompson',
      back: 0,
    }),
    {
      fn: `return { title: document.querySelector('[data-test="relationship-thread-title"]')?.textContent.trim() ?? 'MISSING', back: document.querySelectorAll('[data-test="relationship-thread-back"]').length }`,
    },
  ),

  // ── nothing selected ──────────────────────────────────────────────────────────────────
  pin(
    LIST,
    'with nothing open the right pane shows the placeholder in secondary text',
    '[data-test="messages-placeholder"]',
    (c) => ({
      color: c.onSurfaceVariant,
      display: 'flex',
    }),
  ),
]
