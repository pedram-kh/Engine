#!/usr/bin/env node
/**
 * AH-105 S7 — verifies the App Store Connect field limits in docs/store/listing-*.md.
 *
 * Reads the FIRST fenced block under each heading, counts characters (code points, the way ASC
 * counts), and exits non-zero if any field is over its limit. Run: node docs/store/check-listing.mjs
 */
import { readFileSync, readdirSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const dir = path.dirname(fileURLToPath(import.meta.url))
// Heading prefix (matched at the start of the line after "## ") -> limit. en and es headings.
const LIMITS = [
  [/^(Name|Nombre)\b/, 30, 'name'],
  [/^(Subtitle|Subtítulo)\b/, 30, 'subtitle'],
  [/^(Promotional text|Texto promocional)\b/, 170, 'promotional text'],
  [/^(Description|Descripción)\b/, 4000, 'description'],
  [/^(Keywords|Palabras clave)\b/, 100, 'keywords'],
]

let failed = false
for (const file of readdirSync(dir).filter((f) => /^listing-.*\.md$/.test(f)).sort()) {
  const lines = readFileSync(path.join(dir, file), 'utf8').split('\n')
  console.log(file)
  for (let i = 0; i < lines.length; i++) {
    const m = /^## (.+)$/.exec(lines[i])
    if (!m) continue
    const rule = LIMITS.find(([re]) => re.test(m[1]))
    if (!rule) continue
    const open = lines.findIndex((l, j) => j > i && l.startsWith('```'))
    const close = lines.findIndex((l, j) => j > open && l.startsWith('```'))
    const text = lines.slice(open + 1, close).join('\n')
    const n = [...text].length
    const ok = n <= rule[1]
    if (!ok) failed = true
    console.log(`  ${ok ? 'ok  ' : 'OVER'} ${rule[2].padEnd(16)} ${String(n).padStart(4)} / ${rule[1]}`)
    if (rule[2] === 'keywords' && /,\s/.test(text)) {
      failed = true
      console.log('  OVER keywords contain a space after a comma (ASC counts it)')
    }
  }
}
process.exit(failed ? 1 : 0)
