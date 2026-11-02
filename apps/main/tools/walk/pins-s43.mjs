/**
 * S4.3 pins: the form pages (brand create and edit, campaign create, talent pool create and
 * edit), Settings and the bulk invite, read off the REAL pages over the L4 fixtures in both agency
 * themes. They check R3 (the header, the 36px back button) and R10 (the form sits in one agency
 * card, 720 wide at most, no Vuetify card left).
 */

const STEP = 'S4.3'

const pin = (page, name, sel, expect, more = {}) => ({
  step: STEP,
  name,
  sel,
  expect,
  page,
  ...more,
})

/** [id, root test id, heading test id, has a back button] */
const FORMS = [
  ['brand-create', 'brand-create-page', 'brand-create-heading', true],
  ['brand-edit', 'brand-edit-page', 'brand-edit-heading', true],
  ['campaign-create', 'campaign-create-page', 'campaign-create-heading', true],
  ['pool-create', 'pool-create-page', 'pool-create-heading', true],
  ['pool-edit', 'pool-edit-page', 'pool-edit-heading', true],
  ['settings', 'settings-page', 'settings-heading', false],
  ['bulk-invite', 'bulk-invite-page', 'bulk-invite-heading', false],
]

const formPins = ([id, root, heading, back]) => {
  const page = `real:${id}-1600`
  const r = `[data-test="${root}"]`
  const rowGap = id === 'settings' ? '24px' : '20px'
  return [
    pin(page, `${id}: the page frame stacks its blocks ${rowGap} apart`, r, () => ({
      display: 'flex',
      flexDirection: 'column',
      rowGap,
    })),
    pin(page, `${id}: title is Heading/H1 26/32/500`, `[data-test="${heading}"]`, (c) => ({
      fontSize: '26px',
      lineHeight: '32px',
      fontWeight: '500',
      color: c.onSurface,
    })),
    pin(
      page,
      `${id}: one agency card (720 at most, padding 24), no v-card`,
      `${r} .agency-card`,
      () => ({ maxWidth: '720px', paddingTop: '24px', paddingLeft: '24px', vCard: 0 }),
      {
        fn: `const e = document.querySelector('${r} .agency-card'); const s = getComputedStyle(e); return { maxWidth: s.maxWidth, paddingTop: s.paddingTop, paddingLeft: s.paddingLeft, vCard: document.querySelectorAll('${r} .v-card').length }`,
      },
    ),
    ...(back
      ? [
          pin(
            page,
            `${id}: the back button is the 36px square`,
            `${r} .agency-icon-square`,
            () => ({ width: '36px', height: '36px', borderTopWidth: '1px' }),
          ),
        ]
      : []),
  ]
}

export const S43_PINS = [
  ...FORMS.flatMap(formPins),
  pin(
    'real:bulk-invite-1600',
    'bulk invite: the description is the header line (13/19, secondary)',
    '[data-test="bulk-invite-page"] .agency-page-header__subtitle',
    () => ({ fontSize: '13px', lineHeight: '19px' }),
  ),
  pin(
    'real:settings-1600',
    'settings: the three fields are in the card, the form is one column',
    '[data-test="settings-form"]',
    () => ({ fields: 3 }),
    {
      fn: `const f = document.querySelector('[data-test="settings-form"]'); return { fields: f.querySelectorAll('.v-select, .v-switch').length }`,
    },
  ),
]
