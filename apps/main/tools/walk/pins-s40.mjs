/**
 * S4.0 pins: the Campaigns filter row (carry-forward nit): the Brand select and the two native
 * date inputs read as one family. Same height, same width, and every label sits on the top border
 * (the date inputs always show their `mm/dd/yyyy` mask, so their label floats; the select's label
 * is forced to float too, instead of sitting inside an empty box).
 */

const STEP = 'S4.0'
const PAGE = 'real:campaigns-1600'

export const S40_PINS = [
  {
    step: STEP,
    name: 'the three filter fields share a height and a width',
    page: PAGE,
    sel: '[data-test="campaign-filters"]',
    expect: () => ({ sameHeight: true, sameWidth: true }),
    fn: `const r = (n) => document.querySelector('[data-test="campaign-filter-' + n + '"] .v-field').getBoundingClientRect(); const [a, b, c] = ['brand', 'starts-from', 'starts-to'].map(r); return { sameHeight: Math.abs(a.height - b.height) < 1 && Math.abs(b.height - c.height) < 1, sameWidth: Math.abs(a.width - b.width) < 1 && Math.abs(b.width - c.width) < 1 }`,
  },
  {
    step: STEP,
    name: 'every label floats onto the top border, the empty select included',
    page: PAGE,
    sel: '[data-test="campaign-filters"]',
    expect: () => ({ brand: true, from: true, to: true }),
    fn: `const a = (n) => document.querySelector('[data-test="campaign-filter-' + n + '"] .v-field').classList.contains('v-field--active'); return { brand: a('brand'), from: a('starts-from'), to: a('starts-to') }`,
  },
  {
    step: STEP,
    name: 'the labels share one size and one colour',
    page: PAGE,
    sel: '[data-test="campaign-filters"]',
    expect: () => ({ sameSize: true, sameColour: true }),
    fn: `const l = (n) => getComputedStyle(document.querySelector('[data-test="campaign-filter-' + n + '"] .v-label.v-field-label')); const [a, b, c] = ['brand', 'starts-from', 'starts-to'].map(l); return { sameSize: a.fontSize === b.fontSize && b.fontSize === c.fontSize, sameColour: a.color === b.color && b.color === c.color }`,
  },
]
