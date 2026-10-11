/**
 * Source-inspection regression test (AH-102b C0.2): every Vuetify component
 * used in a `.vue` file of the admin SPA — or of `@catalyst/ui`, which the
 * SPA imports through a barrel — MUST be pre-declared in `vite.config.ts`'s
 * `optimizeDeps.include` list (`vuetifyComponents`).
 *
 * Why: Vuetify's `autoImport` injects `vuetify/components/<Module>` imports
 * into each SFC. SFCs in lazily-loaded routes are invisible to Vite's startup
 * dep-scan, so on a COLD dev server the first visit discovers the module,
 * re-bundles, and emits `optimized dependencies changed. reloading` — a full
 * page reload that interrupts whatever the page was doing. In CI every E2E run
 * starts cold; the reload landed between "TOTP submitted" and "dashboard
 * rendered" in `admin-sign-in.spec.ts:69`, which then saw `/sign-in` (the
 * document was re-requested at that URL; the guest page does not bootstrap).
 * Evidence + reproduction: docs/reviews/admin-observability-review.md (C0.2).
 *
 * The check is structural: tag → module is resolved from Vuetify's own
 * `lib/components/<Module>/index.js` export lists, so sub-components
 * (VCardText → VCard, VListItem → VList, VRow → VGrid …) map correctly with no
 * hand-kept table. It scans static tags (`<v-btn`, `<VBtn`). Components used
 * only via `<component :is>` or registered by hand are out of its reach.
 */

import { readFileSync, readdirSync, statSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const ADMIN_ROOT = path.resolve(__dirname, '../../..')
const REPO_ROOT = path.resolve(ADMIN_ROOT, '../..')
const VUETIFY_COMPONENTS = path.resolve(ADMIN_ROOT, 'node_modules/vuetify/lib/components')

const SCANNED_ROOTS = [path.join(ADMIN_ROOT, 'src'), path.join(REPO_ROOT, 'packages/ui/src')]

function walkVue(dir: string, out: string[] = []): string[] {
  for (const entry of readdirSync(dir)) {
    const full = path.join(dir, entry)
    const stat = statSync(full)
    if (stat.isDirectory()) {
      if (entry === 'node_modules') continue
      walkVue(full, out)
    } else if (entry.endsWith('.vue')) {
      out.push(full)
    }
  }
  return out
}

/** Exported component name → the Vuetify module directory that exports it. */
function buildExportMap(): Map<string, string> {
  const map = new Map<string, string>()
  for (const dir of readdirSync(VUETIFY_COMPONENTS)) {
    const index = path.join(VUETIFY_COMPONENTS, dir, 'index.js')
    try {
      statSync(index)
    } catch {
      continue
    }
    for (const match of readFileSync(index, 'utf8').matchAll(
      /export\s*\{\s*([A-Za-z0-9_]+)\s*\}\s*from/g,
    )) {
      map.set(match[1]!, dir)
    }
  }
  return map
}

function kebabToPascal(tag: string): string {
  return tag
    .split('-')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('')
}

function usedVuetifyComponents(): Map<string, Set<string>> {
  const used = new Map<string, Set<string>>()
  const note = (name: string, file: string): void => {
    const set = used.get(name) ?? new Set<string>()
    set.add(path.relative(REPO_ROOT, file))
    used.set(name, set)
  }

  for (const root of SCANNED_ROOTS) {
    for (const file of walkVue(root)) {
      const source = readFileSync(file, 'utf8')
      for (const m of source.matchAll(/<(v-[a-z0-9-]+)[\s/>]/g)) note(kebabToPascal(m[1]!), file)
      for (const m of source.matchAll(/<(V[A-Z][A-Za-z0-9]+)[\s/>]/g)) note(m[1]!, file)
    }
  }
  return used
}

function declaredModules(): Set<string> {
  const config = readFileSync(path.join(ADMIN_ROOT, 'vite.config.ts'), 'utf8')
  const block = /const vuetifyComponents = \[([\s\S]*?)\]\.map/.exec(config)
  if (block === null) {
    throw new Error(
      'vite.config.ts no longer declares `const vuetifyComponents = [...].map(...)` — update this spec with it.',
    )
  }
  return new Set([...block[1]!.matchAll(/'([A-Za-z0-9]+)'/g)].map((m) => m[1]!))
}

describe('vite.config.ts pre-bundles every Vuetify component the SPA uses (cold-start reload guard)', () => {
  const exportMap = buildExportMap()
  const used = usedVuetifyComponents()
  const declared = declaredModules()

  it("can resolve Vuetify's own export lists (the spec is not vacuous)", () => {
    expect(exportMap.size).toBeGreaterThan(100)
    expect(exportMap.get('VCardText')).toBe('VCard')
    expect(exportMap.get('VRow')).toBe('VGrid')
    expect(used.size).toBeGreaterThan(10)
  })

  it('every Vuetify tag used in the admin SPA / @catalyst/ui resolves to a real Vuetify component', () => {
    const unknown = [...used.keys()].filter((name) => !exportMap.has(name))
    expect(unknown, `unrecognised Vuetify components: ${unknown.join(', ')}`).toEqual([])
  })

  it('every module those components live in is listed in optimizeDeps.include', () => {
    const missing = new Map<string, string[]>()
    for (const [name, files] of used) {
      const module = exportMap.get(name)
      if (module === undefined || declared.has(module)) continue
      missing.set(module, [...(missing.get(module) ?? []), `${name} (${[...files][0]})`])
    }

    const report = [...missing]
      .map(([module, who]) => `  '${module}' — used by ${who.join(', ')}`)
      .join('\n')
    expect(
      missing.size,
      `Add these to \`vuetifyComponents\` in apps/admin/vite.config.ts:\n${report}`,
    ).toBe(0)
  })

  it('every declared module exists in the installed Vuetify (no typo can break dev-server start)', () => {
    const real = new Set(readdirSync(VUETIFY_COMPONENTS))
    const bogus = [...declared].filter((name) => !real.has(name))
    expect(bogus).toEqual([])
  })
})
