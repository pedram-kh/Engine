#!/usr/bin/env node
/**
 * AH-121 C2: fails the build if any `*.map` file remains under the given
 * directories (default `dist`). Wired into each SPA's `build` script AFTER
 * `vite build`, so a build that failed to delete its source maps is never published
 * (on Netlify a failed build leaves the previous deploy live).
 *
 *   node scripts/check-no-sourcemaps.mjs [dir ...]
 *
 * MIRRORED BYTE-FOR-BYTE between `apps/main` and `apps/admin` (parity-tested).
 */

import { existsSync, readdirSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

/** Every `*.map` path under `dir`, recursively. A missing directory has none. */
export function findSourceMaps(dir) {
  if (!existsSync(dir)) {
    return []
  }
  const found = []
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name)
    if (entry.isDirectory()) {
      found.push(...findSourceMaps(full))
    } else if (entry.name.endsWith('.map')) {
      found.push(full)
    }
  }
  return found
}

if (
  process.argv[1] !== undefined &&
  path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)
) {
  const dirs = process.argv.length > 2 ? process.argv.slice(2) : ['dist']
  const maps = dirs.flatMap((dir) => findSourceMaps(dir))
  if (maps.length > 0) {
    console.error(`[sentry] FAIL: ${maps.length} source map file(s) remain in the build output:`)
    for (const file of maps) {
      console.error(`  ${file}`)
    }
    process.exit(1)
  }
  console.log(`[sentry] ok: no .map files under ${dirs.join(', ')}`)
}
