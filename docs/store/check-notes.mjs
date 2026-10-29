#!/usr/bin/env node
/**
 * AH-105 S7 — checks the ASC "Notes" paste block in docs/store/review-notes.md is within 4000
 * characters and carries no real credential (only the two placeholders).
 */
import { readFileSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const file = path.join(path.dirname(fileURLToPath(import.meta.url)), 'review-notes.md')
const lines = readFileSync(file, 'utf8').split('\n')
const h = lines.findIndex((l) => l.trim() === '## Paste this')
const open = lines.findIndex((l, i) => i > h && l.startsWith('```'))
const close = lines.findIndex((l, i) => i > open && l.startsWith('```'))
const text = lines.slice(open + 1, close).join('\n')
const n = [...text].length

let failed = false
console.log(`review notes: ${n} / 4000`)
if (n > 4000) failed = true
for (const placeholder of ['<DEMO_EMAIL>', '<DEMO_PASSWORD>']) {
  if (!text.includes(placeholder)) {
    failed = true
    console.log(`MISSING placeholder ${placeholder}`)
  }
}
// Any "Email:" or "Password:" line must hold a placeholder, never a value.
for (const l of text.split('\n')) {
  if (/^(Email|Password):/.test(l) && !/<DEMO_(EMAIL|PASSWORD)>/.test(l)) {
    failed = true
    console.log(`REAL VALUE? ${l}`)
  }
}
process.exit(failed ? 1 : 0)
