/**
 * PL-2.2 — nothing in the strategy module may draw server text as HTML. Every string (a rationale, a message line, a
 * title, an explanation) is drawn with `{{ }}`; a `v-html` or `innerHTML` anywhere in the module turns this red.
 */
import { readdirSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

function files(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const full = path.join(dir, name)
    if (statSync(full).isDirectory()) return files(full)
    return /\.(vue|ts)$/.test(name) && !name.endsWith('.spec.ts') ? [full] : []
  })
}

describe('strategy module draws no HTML (PL-2.2)', () => {
  it('has no v-html, innerHTML or insertAdjacentHTML in any source file', () => {
    const offenders = files(__dirname).filter((f) =>
      /v-html|innerHTML|outerHTML|insertAdjacentHTML/.test(readFileSync(f, 'utf8')),
    )
    expect(offenders.map((f) => path.relative(__dirname, f))).toEqual([])
  })

  it('PL-2.4: the market builder and the countries lists (waves module) draw no HTML either', () => {
    const dir = path.join(__dirname, '..', 'waves')
    const mine = files(dir).filter((f) =>
      /Market|marketDrafts|NewWaveDialog|WaveDetailPage/.test(f),
    )
    expect(mine.length).toBeGreaterThanOrEqual(5)
    const offenders = mine.filter((f) =>
      /v-html|innerHTML|outerHTML|insertAdjacentHTML/.test(readFileSync(f, 'utf8')),
    )
    expect(offenders.map((f) => path.relative(dir, f))).toEqual([])
  })
})
