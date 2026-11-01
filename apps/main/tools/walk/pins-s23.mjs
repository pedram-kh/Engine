/**
 * S2.3 pins: the agency conversation pane fork, read off the second probe page (`?probe=extra`)
 * under both agency themes. Stand-in conversation behind a fake transport: two days, theirs
 * (`t1`, `t4`), own and read (`t2`, `t3` with a file card), own and sent (`t5`). Values are the
 * Figma numbers (Messages frames 18:543, 18:564, 18:569, 18:585, 18:571, 18:602).
 */

const STEP = 'S2.3'
const TRANSPARENT = 'rgba(0, 0, 0, 0)'

const pin = (name, sel, expect, more = {}) => ({
  step: STEP,
  name,
  sel,
  expect,
  page: 'extra',
  ...more,
})
const msg = (id) => `[data-test="relationship-message-${id}"]`
const bubble = (id) => `${msg(id)} .agency-bubble`

export const S23_PINS = [
  pin('header: padding 16/24, a 1px rule under it, 12 between', '.agency-thread__header', (c) => ({
    paddingTop: '16px',
    paddingRight: '24px',
    paddingBottom: '16px',
    paddingLeft: '24px',
    columnGap: '12px',
    borderBottomWidth: '1px',
    borderBottomColor: c.border,
  })),
  pin('header: avatar 40px accent disc', '.agency-thread__header .v-avatar', (c) => ({
    width: '40px',
    height: '40px',
    backgroundColor: c.primary,
  })),
  pin('header: name is 15/20/600', '[data-test="relationship-thread-title"]', (c) => ({
    fontSize: '15px',
    lineHeight: '20px',
    fontWeight: '600',
    color: c.onSurface,
  })),
  pin('feed: 24px padding, scrolls', '[data-test="relationship-thread-feed"]', () => ({
    paddingTop: '24px',
    paddingRight: '24px',
    paddingBottom: '24px',
    paddingLeft: '24px',
    overflowY: 'auto',
  })),
  pin('messages: 16px between bubbles and separators', '.agency-thread__messages', () => ({
    rowGap: '16px',
  })),
  pin(
    'day separator: 12/16/500 tertiary label between two 1px rules',
    '[data-test="agency-thread-day"]',
    (c) => ({
      labelFontSize: '12px',
      labelLineHeight: '16px',
      labelFontWeight: '500',
      labelColor: c.tertiary,
      ruleHeight: '1px',
      ruleColor: c.border,
      rulesEitherSide: true,
    }),
    {
      fn: `const d = document.querySelector('[data-test="agency-thread-day"]'); const l = d.querySelector('.agency-thread__day-label'); const rules = d.querySelectorAll('.agency-thread__day-rule'); const ls = getComputedStyle(l); const rs = getComputedStyle(rules[0]); const lr = l.getBoundingClientRect(); return { labelFontSize: ls.fontSize, labelLineHeight: ls.lineHeight, labelFontWeight: ls.fontWeight, labelColor: ls.color, ruleHeight: rs.height, ruleColor: rs.backgroundColor, rulesEitherSide: rules.length === 2 && rules[0].getBoundingClientRect().right <= lr.left && rules[1].getBoundingClientRect().left >= lr.right }`,
    },
  ),
  pin(
    'there are two day separators, one per day',
    '.agency-thread__messages',
    () => ({ days: 2 }),
    {
      fn: `return { days: document.querySelectorAll('[data-test="agency-thread-day"]').length }`,
    },
  ),
  pin('their bubble: raised surface, radius 12/12/12/4, padding 12/16', bubble('t1'), (c) => ({
    backgroundColor: c.raised,
    color: c.onSurface,
    borderTopLeftRadius: '12px',
    borderTopRightRadius: '12px',
    borderBottomRightRadius: '12px',
    borderBottomLeftRadius: '4px',
    paddingTop: '12px',
    paddingRight: '16px',
    paddingBottom: '12px',
    paddingLeft: '16px',
    borderTopColor: TRANSPARENT,
  })),
  pin('own bubble: accent tint and accent border, radius 12/12/4/12', bubble('t2'), (c) => ({
    backgroundColor: c.primaryContainer,
    borderTopColor: c.primary,
    borderTopWidth: '1px',
    borderTopLeftRadius: '12px',
    borderTopRightRadius: '12px',
    borderBottomRightRadius: '4px',
    borderBottomLeftRadius: '12px',
  })),
  pin('bubble text: 14/20', `${bubble('t1')} .agency-bubble__body`, (c) => ({
    fontSize: '14px',
    lineHeight: '20px',
    fontWeight: '400',
    color: c.onSurface,
  })),
  pin(
    'bubbles: own on the right, theirs on the left, none wider than 460px',
    msg('t1'),
    () => ({
      theirsLeft: true,
      ownRight: true,
      maxWidthOk: true,
    }),
    {
      fn: `const f = document.querySelector('[data-test="relationship-thread-feed"]').getBoundingClientRect(); const b = (id) => document.querySelector('[data-test="relationship-message-' + id + '"] .agency-bubble').getBoundingClientRect(); const t = b('t1'); const o = b('t2'); return { theirsLeft: Math.abs(t.left - (f.left + 24)) < 1, ownRight: Math.abs(o.right - (f.right - 24 - (f.width - document.querySelector('[data-test="relationship-thread-feed"]').clientWidth))) < 1, maxWidthOk: ['t1','t2','t3','t4','t5'].every((id) => b(id).width <= 460.5) }`,
    },
  ),
  pin('meta line: 12/16 tertiary', `${msg('t1')} .agency-bubble__meta`, (c) => ({
    fontSize: '12px',
    lineHeight: '16px',
    color: c.tertiary,
  })),
  pin(
    'own meta line starts with the name',
    `${msg('t2')} .agency-bubble__who`,
    () => ({ text: 'Jordan ·' }),
    {
      fn: `return { text: document.querySelector('[data-test="relationship-message-t2"] .agency-bubble__who').textContent.replace(/\\s+/g, ' ').trim() }`,
    },
  ),
  pin(
    'tick: read is accent text, sent is tertiary',
    msg('t2'),
    (c) => ({
      read: c.primaryText,
      sent: c.tertiary,
    }),
    {
      fn: `const col = (id) => getComputedStyle(document.querySelector('[data-test="relationship-message-' + id + '"] .agency-bubble__tick')).color; return { read: col('t2'), sent: col('t5') }`,
    },
  ),
  pin(
    'file card: bordered, radius 8, padding 12, 12 between, on the surface',
    `${msg('t3')} .agency-attach`,
    (c) => ({
      borderTopWidth: '1px',
      borderTopColor: c.border,
      borderTopLeftRadius: '8px',
      paddingTop: '12px',
      paddingLeft: '12px',
      columnGap: '12px',
      backgroundColor: c.surface,
    }),
  ),
  pin('file card: 32px tile, radius 6, accent tint', `${msg('t3')} .agency-attach__tile`, (c) => ({
    width: '32px',
    height: '32px',
    borderTopLeftRadius: '6px',
    backgroundColor: c.primaryContainer,
    color: c.primaryText,
  })),
  pin(
    'file card: name 14/20/500, meta 12/16 tertiary',
    `${msg('t3')} .agency-attach`,
    (c) => ({
      nameFontSize: '14px',
      nameLineHeight: '20px',
      nameFontWeight: '500',
      metaFontSize: '12px',
      metaLineHeight: '16px',
      metaColor: c.tertiary,
      metaText: 'PDF · 1.2 MB',
    }),
    {
      fn: `const a = document.querySelector('[data-test="relationship-message-t3"] .agency-attach'); const n = getComputedStyle(a.querySelector('.agency-attach__name')); const m = getComputedStyle(a.querySelector('.agency-attach__meta')); return { nameFontSize: n.fontSize, nameLineHeight: n.lineHeight, nameFontWeight: n.fontWeight, metaFontSize: m.fontSize, metaLineHeight: m.lineHeight, metaColor: m.color, metaText: a.querySelector('.agency-attach__meta').textContent.replace(/\\s+/g, ' ').trim() }`,
    },
  ),
  pin(
    'composer: padding 16/24, a 1px rule above, inside the pane',
    '[data-test="relationship-compose"]',
    (c) => ({
      paddingTop: '16px',
      paddingRight: '24px',
      paddingBottom: '16px',
      paddingLeft: '24px',
      borderTopWidth: '1px',
      borderTopColor: c.border,
    }),
  ),
  pin(
    'composer: attach and send are 40px squares, 12 from the field',
    '.agency-thread__compose-row',
    () => ({
      attachW: '40px',
      attachH: '40px',
      sendW: '40px',
      sendH: '40px',
      columnGap: '12px',
      insidePane: true,
    }),
    {
      fn: `const row = document.querySelector('.agency-thread__compose-row'); const a = getComputedStyle(row.querySelector('[data-test="relationship-attach-toggle"]')); const s = getComputedStyle(row.querySelector('[data-test="relationship-send"]')); const pane = document.querySelector('[data-test="extra-thread-pane"]').getBoundingClientRect(); const cb = document.querySelector('[data-test="relationship-compose"]').getBoundingClientRect(); return { attachW: a.width, attachH: a.height, sendW: s.width, sendH: s.height, columnGap: getComputedStyle(row).columnGap, insidePane: cb.bottom <= pane.bottom + 0.5 }`,
    },
  ),
  pin(
    'send: disabled while the field is empty',
    '[data-test="relationship-send"]',
    () => ({
      disabled: true,
      hasAccentOpacity: true,
    }),
    {
      fn: `const b = document.querySelector('[data-test="relationship-send"]'); return { disabled: b.disabled, hasAccentOpacity: Number(getComputedStyle(b).opacity) < 1 || b.classList.contains('v-btn--disabled') }`,
    },
  ),
]
