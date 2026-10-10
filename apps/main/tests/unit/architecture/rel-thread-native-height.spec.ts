/**
 * AH-101 (U3) — the relationship thread's height inside the native shell.
 *
 * The defect: `.rel-thread` sized itself with a web-mobile constant
 * (`100dvh - 140px`), which is ~90pt too tall inside the Capacitor app whose
 * chrome adds the notch and home-indicator insets. The composer was in the DOM
 * but sat under the fixed tab bar, so a conversation read as having no reply
 * field. Nothing in jsdom can see that — there is no layout engine — so this is a
 * SOURCE-INSPECTION pin, the same shape as the repo's other architecture specs,
 * and the geometric fact (composer bottom edge above the tab bar's top edge) is
 * verified on a device.
 *
 * Both polarities: the native rule exists, derives from the live layout vars and
 * the safe-area tokens, and is ordered after the web rules; the web rules are
 * byte-for-byte what they were.
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { describe, expect, it } from 'vitest'

const SOURCE = readFileSync(
  resolve(__dirname, '../../../src/modules/messaging/components/RelationshipThreadView.vue'),
  'utf8',
)

const STYLE = SOURCE.slice(SOURCE.indexOf('<style scoped>'))

/** The body of the first rule whose selector line is exactly `selector {`. */
function ruleBody(selector: string): string {
  const start = STYLE.indexOf(`${selector} {`)
  expect(start, `no \`${selector}\` rule`).toBeGreaterThan(-1)
  const open = STYLE.indexOf('{', start)
  const close = STYLE.indexOf('\n}', open)
  return STYLE.slice(open + 1, close)
}

describe('RelationshipThreadView — native height (U3)', () => {
  it('has a `.catalyst-native .rel-thread` rule (the native scope)', () => {
    expect(STYLE).toContain(':global(.catalyst-native .rel-thread)')
  })

  it('derives the native height from the live layout vars and the safe-area tokens', () => {
    const body = ruleBody(':global(.catalyst-native .rel-thread)')

    for (const token of [
      '--v-layout-top',
      '--v-layout-bottom',
      '--native-safe-top',
      '--native-safe-bottom',
    ]) {
      expect(body, `native height does not read ${token}`).toContain(token)
    }
    expect(body).toContain('100dvh')
    // The magic number that caused the defect must not be the native answer.
    expect(body).not.toContain('140px')
    expect(body).not.toContain('180px')
    // Released like the mobile rule: a floor would re-introduce overflow.
    expect(body).toMatch(/min-height:\s*0/)
  })

  it('is declared AFTER the web mobile media block, so it wins at equal specificity', () => {
    const media = STYLE.indexOf('@media (max-width: 959px)')
    const native = STYLE.indexOf(':global(.catalyst-native .rel-thread)')

    expect(media).toBeGreaterThan(-1)
    expect(native).toBeGreaterThan(media)
  })

  it('leaves the web rules exactly as they were (web unchanged)', () => {
    expect(ruleBody('.rel-thread')).toContain('height: calc(100dvh - 180px)')
    expect(ruleBody('.rel-thread')).toContain('min-height: 420px')
    expect(STYLE).toContain('height: calc(100dvh - 140px)')
  })
})
