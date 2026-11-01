/**
 * S2.0 pins: the two L1 review carry-forwards (even table rule pitch, form hint spacing),
 * read off the second probe page (`?probe=extra`) under both agency themes. See
 * pins-s14.mjs for the pin shapes.
 */

const STEP = 'S2.0'
const extra = (name, sel, expect, more = {}) => ({
  step: STEP,
  name,
  sel,
  expect,
  page: 'extra',
  ...more,
})

const TABLE = '[data-test="extra-table"]'

export const S20_PINS = [
  extra(
    'table: every row but the last is one row-height, the last is one pixel short',
    TABLE,
    () => ({ pitch: '52', last: '51' }),
    {
      // Row pitch is the distance between consecutive rules. The last row has no rule of
      // its own, so it must be 1px short for the rule under it to land on the pitch.
      fn: `const rows = [...document.querySelector('${TABLE}').querySelectorAll('tbody > tr')].map((tr) => tr.getBoundingClientRect()); const heights = rows.slice(0, -1).map((r) => Math.round(r.height)); const last = rows[rows.length - 1]; return { pitch: [...new Set(heights)].join('/'), last: String(Math.round(last.height)) }`,
    },
  ),
  extra(
    'hint text uses the 16px Caption line height',
    '[data-test="extra-hint"] .v-messages__message',
    () => ({
      lineHeight: '16px',
      fontSize: '12px',
    }),
  ),
  extra(
    'a field with a hint has 4px of room under it',
    '[data-test="extra-hint"] .v-input__details',
    () => ({
      paddingBottom: '4px',
      paddingTop: '6px',
    }),
  ),
  extra(
    'a field with no message keeps Vuetify details rhythm (no extra room)',
    '[data-test="extra-autocomplete"] .v-input__details',
    () => ({
      paddingBottom: '0px',
      minHeight: '22px',
    }),
  ),
]
