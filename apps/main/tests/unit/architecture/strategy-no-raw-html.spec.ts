/**
 * PL-2.1 — nothing in `modules/strategy` may render user text as markup. USP, persona, angle and audience text is
 * typed by agency staff and reaches other staff's screens; it is only ever drawn through Vue interpolation.
 * Forbidden under `src/modules/strategy`: `v-html`, `innerHTML`, `outerHTML`, `insertAdjacentHTML`,
 * `document.write`, `createContextualFragment`, a render function `innerHTML` prop, `DOMParser`.
 */
import { promises as fs } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../../../src/modules/strategy')

const FORBIDDEN: ReadonlyArray<{ pattern: RegExp; description: string }> = [
  { pattern: /v-html/i, description: 'v-html' },
  { pattern: /\binnerHTML\b/, description: 'innerHTML' },
  { pattern: /\bouterHTML\b/, description: 'outerHTML' },
  { pattern: /\binsertAdjacentHTML\b/, description: 'insertAdjacentHTML' },
  { pattern: /\bdocument\.write(ln)?\s*\(/, description: 'document.write' },
  { pattern: /\bcreateContextualFragment\b/, description: 'createContextualFragment' },
  { pattern: /\bDOMParser\b/, description: 'DOMParser' },
  { pattern: /\bdomProps\s*:\s*\{[^}]*innerHTML/, description: 'domProps innerHTML' },
]

async function walk(dir: string): Promise<string[]> {
  const out: string[] = []
  for (const entry of await fs.readdir(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name)
    if (entry.isDirectory()) out.push(...(await walk(full)))
    else if (/\.(vue|ts|tsx|js)$/.test(entry.name)) out.push(full)
  }
  return out
}

describe('strategy module renders user text as text only (PL-2.1)', () => {
  it('has files to inspect (the check is not vacuous)', async () => {
    const files = await walk(ROOT)
    expect(files.filter((f) => f.endsWith('.vue')).length).toBeGreaterThanOrEqual(13)
  })

  it('uses no v-html, innerHTML or other markup sink', async () => {
    const offenders: string[] = []
    for (const file of await walk(ROOT)) {
      // The specs name the sinks to assert they are absent; they are not shipped code.
      if (/\.spec\.ts$/.test(file) || file.endsWith('strategyTestKit.ts')) continue
      const text = await fs.readFile(file, 'utf8')
      for (const { pattern, description } of FORBIDDEN) {
        if (pattern.test(text)) offenders.push(`${path.relative(ROOT, file)}: ${description}`)
      }
    }
    expect(offenders).toEqual([])
  })
})
