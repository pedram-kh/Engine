/**
 * S2.2 pins: the agency inbox fork, read off the second probe page (`?probe=extra`) under
 * both agency themes. Stand-in rows: `a` active and read, `u` unread with a tag, `r` read,
 * `l` unread with a name and preview too long for the row. Values are the Figma numbers
 * (Messages frames 20:87, 20:130, 20:146); colours come from pins-colours.mjs.
 */

const STEP = 'S2.2'
const TRANSPARENT = 'rgba(0, 0, 0, 0)'

const pin = (name, sel, expect, more = {}) => ({
  step: STEP,
  name,
  sel,
  expect,
  page: 'extra',
  ...more,
})
const row = (id) => `[data-test="relationship-inbox-row-${id}"]`

export const S22_PINS = [
  pin('header: padding, rule and block gap', '[data-test="agency-inbox-header"]', (c) => ({
    paddingTop: '20px',
    paddingRight: '20px',
    paddingBottom: '16px',
    paddingLeft: '20px',
    rowGap: '16px',
    borderBottomWidth: '1px',
    borderBottomColor: c.border,
  })),
  pin('header: icon tile 40px, radius 10, accent tint', '.agency-inbox__icon-tile', (c) => ({
    width: '40px',
    height: '40px',
    borderTopLeftRadius: '10px',
    backgroundColor: c.primaryContainer,
    color: c.primaryText,
  })),
  pin('header: title is the 22/28/600 heading', '.agency-inbox__title', (c) => ({
    fontSize: '22px',
    lineHeight: '28px',
    fontWeight: '600',
    color: c.onSurface,
  })),
  pin('header: subtitle is the 12/16 caption, secondary', '.agency-inbox__subtitle', (c) => ({
    fontSize: '12px',
    lineHeight: '16px',
    color: c.onSurfaceVariant,
  })),
  pin(
    'header: unread pill is an accent capsule',
    '[data-test="agency-inbox-unread-pill"]',
    (c) => ({
      backgroundColor: c.primary,
      color: c.onPrimary,
      borderTopLeftRadius: '999px',
      paddingTop: '2px',
      paddingLeft: '8px',
      fontSize: '12px',
      fontWeight: '500',
    }),
  ),
  pin('toggle: bordered track, radius 8, 3px inset', '[data-test="agency-inbox-toggle"]', (c) => ({
    paddingTop: '3px',
    borderTopWidth: '1px',
    borderTopColor: c.border,
    borderTopLeftRadius: '8px',
    backgroundColor: c.surface,
  })),
  pin('toggle: selected segment is raised', '[data-test="agency-inbox-toggle-all"]', (c) => ({
    backgroundColor: c.raised,
    color: c.onSurface,
    borderTopLeftRadius: '6px',
    fontSize: '12px',
    fontWeight: '500',
  })),
  pin(
    'toggle: other segment is flat and tertiary',
    '[data-test="agency-inbox-toggle-unread"]',
    (c) => ({
      backgroundColor: TRANSPARENT,
      color: c.tertiary,
    }),
  ),
  pin('row: padding 12/12/12/10, gap 12, radius 10', row('u'), () => ({
    paddingTop: '12px',
    paddingRight: '12px',
    paddingBottom: '12px',
    paddingLeft: '10px',
    columnGap: '12px',
    borderTopLeftRadius: '10px',
    borderTopColor: TRANSPARENT,
    backgroundColor: TRANSPARENT,
  })),
  pin('row: the open conversation has the accent tint and border', row('a'), (c) => ({
    backgroundColor: c.primaryContainer,
    borderTopColor: c.primary,
  })),
  pin('dot: unread is an 8px accent dot', '[data-test="agency-inbox-dot-u"]', (c) => ({
    width: '8px',
    height: '8px',
    borderTopLeftRadius: '50%',
    backgroundColor: c.primary,
  })),
  pin('dot: read keeps the slot, invisible', '[data-test="agency-inbox-dot-r"]', () => ({
    width: '8px',
    height: '8px',
    backgroundColor: TRANSPARENT,
  })),
  pin(
    'avatar: 40px accent disc, initial in the on-accent colour',
    `${row('u')} .v-avatar`,
    (c) => ({
      width: '40px',
      height: '40px',
      backgroundColor: c.primary,
    }),
  ),
  pin('avatar initial: 12/16/500', `${row('u')} .agency-inbox-row__initial`, (c) => ({
    fontSize: '12px',
    lineHeight: '16px',
    fontWeight: '500',
    color: c.onPrimary,
  })),
  pin('name: 14/20/500', `${row('u')} .agency-inbox-row__name`, (c) => ({
    fontSize: '14px',
    lineHeight: '20px',
    fontWeight: '500',
    color: c.onSurface,
  })),
  pin('time: unread is accent text, 12/16/500', `${row('u')} .agency-inbox-row__time`, (c) => ({
    fontSize: '12px',
    lineHeight: '16px',
    fontWeight: '500',
    color: c.primaryText,
  })),
  pin('time: read is tertiary', `${row('r')} .agency-inbox-row__time`, (c) => ({
    color: c.tertiary,
  })),
  pin('preview: 13/19, secondary', `${row('u')} .agency-inbox-row__preview`, (c) => ({
    fontSize: '13px',
    lineHeight: '19px',
    fontWeight: '400',
    color: c.onSurfaceVariant,
  })),
  pin('count: 20px accent capsule', '[data-test="relationship-inbox-unread-u"]', (c) => ({
    minWidth: '20px',
    height: '20px',
    paddingLeft: '6px',
    borderTopLeftRadius: '999px',
    backgroundColor: c.primary,
    color: c.onPrimary,
    fontSize: '12px',
    fontWeight: '500',
  })),
  pin('tag: neutral text colour, 12/16/500', '[data-test="agency-inbox-tag-u"]', (c) => ({
    color: c.pills.neutral.text,
    fontSize: '12px',
    lineHeight: '16px',
    fontWeight: '500',
    borderTopLeftRadius: '999px',
    paddingTop: '2px',
    paddingLeft: '8px',
  })),
  pin(
    'tag: 15% tint of the neutral fill',
    '[data-test="agency-inbox-tag-u"]',
    (c) => ({ tintBackground: c.pills.neutral.fill, tintOpacity: '0.15' }),
    {
      fn: `const s = getComputedStyle(document.querySelector('[data-test="agency-inbox-tag-u"]'), '::before'); return { tintBackground: s.backgroundColor, tintOpacity: s.opacity }`,
    },
  ),
  pin('tag: only the row that has one', row('u'), () => ({ tags: 1, otherRows: 0 }), {
    fn: `const t = (id) => document.querySelectorAll('[data-test="relationship-inbox-row-' + id + '"] .agency-inbox-row__tag').length; return { tags: t('u'), otherRows: t('a') + t('r') + t('l') }`,
  }),
  pin(
    'long name and preview are cut with an ellipsis and stay inside the row',
    row('l'),
    () => ({
      nameCut: true,
      previewCut: true,
      nameInside: true,
      previewInside: true,
      chevInside: true,
    }),
    {
      fn: `const r = document.querySelector('[data-test="relationship-inbox-row-l"]'); const rb = r.getBoundingClientRect(); const q = (s) => r.querySelector(s); const cut = (el) => el.scrollWidth > el.clientWidth && getComputedStyle(el).textOverflow === 'ellipsis'; const inside = (el) => el.getBoundingClientRect().right <= rb.right + 0.5; return { nameCut: cut(q('.agency-inbox-row__name')), previewCut: cut(q('.agency-inbox-row__preview')), nameInside: inside(q('.agency-inbox-row__name')), previewInside: inside(q('.agency-inbox-row__preview')), chevInside: inside(q('.agency-inbox-row__chev')) }`,
    },
  ),
  pin(
    'avatars line up: every row has its avatar at the same x',
    row('u'),
    () => ({ sameX: true }),
    {
      fn: `const xs = ['a','u','r','l'].map((id) => document.querySelector('[data-test="relationship-inbox-row-' + id + '"] .v-avatar').getBoundingClientRect().left); return { sameX: xs.every((x) => Math.abs(x - xs[0]) < 0.5) }`,
    },
  ),
]
