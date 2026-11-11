/**
 * AH-115 (local walk) — the invitation and job cards at `md` and above.
 *
 * Before: the cards were the phone column stretched wide (the page's desktop row rule centred the
 * card's children from 600 px up). In `creatorNative.css`: the card is a stretched column at every
 * width; from 960 px the lists stay ONE column (cards full width) and the invitation actions sit on one right-aligned
 * line with Accept last. Below 960 nothing of the desktop block applies.
 *
 * Break-revert: drop `align-items: stretch` from the card, add a grid to the lists in the media block,
 * or drop the right alignment → this fails.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const css = readFileSync(
  path.resolve(__dirname, '../../../src/modules/creators/layouts/creatorNative.css'),
  'utf8',
).replace(/\s+/g, ' ')

const mediaStart = css.indexOf('@media (min-width: 960px) {')
const desktop =
  mediaStart === -1 ? '' : css.slice(mediaStart, css.indexOf('/* Empty states', mediaStart))

describe('invitation and job cards on desktop', () => {
  it('the card is a stretched column at every width (not centred)', () => {
    const m = css.match(
      /\.creator-main--native :deep\(\.assignment\), \.creator-main--native :deep\(\.job\) \{([^}]*)\}/,
    )
    expect(m).not.toBeNull()
    expect(m![1]).toMatch(/flex-direction:\s*column/)
    expect(m![1]).toMatch(/align-items:\s*stretch/)
  })

  it('has a min-width: 960px block (md), so below md the phone layout is untouched', () => {
    expect(mediaStart).toBeGreaterThan(-1)
  })

  it('lists stay one column from md: no grid on .assignments or .jobs in the md block', () => {
    expect(desktop).not.toMatch(/:deep\(\.assignments\)/)
    expect(desktop).not.toMatch(/:deep\(\.jobs\)/)
    expect(desktop).not.toMatch(/grid-template-columns/)
    expect(desktop).not.toMatch(/display:\s*grid/)
  })

  it('the actions are one right-aligned line with Accept last', () => {
    expect(desktop).toMatch(/:deep\(\.assignment__actions\) \{[^}]*display:\s*flex/)
    expect(desktop).toMatch(/justify-content:\s*flex-end/)
    expect(desktop).toMatch(/creator-assignment-accept-[^}]*\{[^}]*order:\s*1/)
  })
})
