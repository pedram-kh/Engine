/**
 * S2.1 pins: the agency status pill, every tone, read off the second probe page
 * (`?probe=extra`) under both agency themes. The colours are restated in
 * pins-colours.mjs (`pills`), so a pill that reads the wrong theme key fails.
 */

const STEP = 'S2.1'
const TONES = ['success', 'info', 'warning', 'danger', 'neutral', 'ai']

const pill = (tone) => `[data-test="extra-pill-${tone}"]`
const extra = (name, sel, expect, more = {}) => ({
  step: STEP,
  name,
  sel,
  expect,
  page: 'extra',
  ...more,
})

export const S21_PINS = TONES.flatMap((tone) => [
  extra(`pill ${tone}: shape, type and word colour`, pill(tone), (c) => ({
    alignItems: 'center',
    height: '24px',
    paddingTop: '4px',
    paddingBottom: '4px',
    paddingLeft: '10px',
    paddingRight: '10px',
    columnGap: '8px',
    borderTopLeftRadius: '999px',
    fontSize: '12px',
    lineHeight: '16px',
    fontWeight: '500',
    color: c.pills[tone].text,
  })),
  extra(
    `pill ${tone}: dot is the status fill, 6px round`,
    `${pill(tone)} .agency-status-pill__dot`,
    (c) => ({
      width: '6px',
      height: '6px',
      borderTopLeftRadius: '50%',
      backgroundColor: c.pills[tone].fill,
    }),
  ),
  // The 15% tint is the ::before layer: the status fill at 0.15 opacity.
  extra(
    `pill ${tone}: 15% tint of the status fill`,
    pill(tone),
    (c) => ({
      tintBackground: c.pills[tone].fill,
      tintOpacity: '0.15',
    }),
    {
      fn: `const s = getComputedStyle(document.querySelector('${pill(tone)}'), '::before'); return { tintBackground: s.backgroundColor, tintOpacity: s.opacity }`,
    },
  ),
])
