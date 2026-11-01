/**
 * AH-114 spec 2 — import-graph isolation. The byte-identical guarantee for the
 * creator web and native app rests on this: no file the creator, onboarding or
 * auth trees can reach may import anything from `src/modules/agency/` (the agency
 * theme module, `agency.css`, the agency layout, agency-only components), not
 * even a type-only import.
 *
 *   1. CLOSURE: from the creator, onboarding and auth entry points (their layouts,
 *      every page their route tables load, and the auth pages), the transitive
 *      local import closure contains no file under `modules/agency/`. Route tables
 *      are not traversed into: they import every page of the app, agency included,
 *      and `App.vue` (which switches layouts and so imports `AgencyLayout`) is
 *      deliberately not an entry. What the creator tree actually renders is covered
 *      by `creator-no-agency-theme.spec.ts`.
 *   2. IMPORTERS: only files inside `modules/agency/` may import the agency theme
 *      directory (the theme module and `agency.css`).
 *   3. OUTSIDE `apps/main`: `apps/admin` and `packages/*` never mention the agency
 *      theme (names, files, class).
 *
 * Imports are read from source text (comments stripped): `from '…'`, side-effect
 * `import '…'`, `import('…')`, and `@catalyst/ui` named imports (resolved to
 * `packages/ui/src/components/<Name>.vue`).
 */

import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const REPO_ROOT = path.resolve(__dirname, '../../../../..')
const SRC = path.join(REPO_ROOT, 'apps/main/src')
const UI_COMPONENTS = path.join(REPO_ROOT, 'packages/ui/src/components')
const AGENCY_DIR = path.join(SRC, 'modules/agency') + path.sep
const AGENCY_THEME_DIR = path.join(SRC, 'modules/agency/theme') + path.sep

const RESOLVE_SUFFIXES = ['', '.ts', '.vue', '.js', '.css', '/index.ts', '/index.vue']

function resolveSpecifier(specifier: string, from: string): string | null {
  let base: string
  if (specifier.startsWith('@/')) base = path.join(SRC, specifier.slice(2))
  else if (specifier.startsWith('.')) base = path.resolve(path.dirname(from), specifier)
  else return null
  for (const suffix of RESOLVE_SUFFIXES) {
    const candidate = base + suffix
    if (existsSync(candidate) && statSync(candidate).isFile()) return candidate
  }
  return null
}

function stripComments(text: string): string {
  return text.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:'"`\\])\/\/[^\n]*/g, '$1')
}

const importCache = new Map<string, readonly string[]>()

function importsOf(file: string): readonly string[] {
  const cached = importCache.get(file)
  if (cached !== undefined) return cached

  const found = new Set<string>()
  if (/\.(ts|vue|js)$/.test(file) && !/\.(spec|test)\.ts$/.test(file)) {
    const text = stripComments(readFileSync(file, 'utf8'))
    const record = (specifier: string) => {
      const resolved = resolveSpecifier(specifier, file)
      if (resolved !== null) found.add(resolved)
    }
    for (const match of text.matchAll(/\bfrom\s*['"]([^'"]+)['"]/g)) record(match[1] as string)
    for (const match of text.matchAll(/\bimport\s*['"]([^'"]+)['"]/g)) record(match[1] as string)
    for (const match of text.matchAll(/\bimport\(\s*['"]([^'"]+)['"]\s*\)/g))
      record(match[1] as string)
    for (const match of text.matchAll(/\{([^}]*)\}\s*from\s*['"]@catalyst\/ui['"]/g)) {
      for (const name of (match[1] as string).matchAll(/\b([A-Z][A-Za-z0-9]+)\b/g)) {
        const component = path.join(UI_COMPONENTS, `${name[1]}.vue`)
        if (existsSync(component)) found.add(component)
      }
    }
  }
  const result = [...found]
  importCache.set(file, result)
  return result
}

function closure(entries: readonly string[]): Map<string, string> {
  /** file -> the file that first imported it (for the failure message). */
  const seen = new Map<string, string>()
  const stack: Array<[string, string]> = entries.map((entry) => [entry, '(entry point)'])
  while (stack.length > 0) {
    const [file, via] = stack.pop() as [string, string]
    if (seen.has(file)) continue
    seen.set(file, via)
    for (const next of importsOf(file)) {
      // Never traverse INTO a route table: it imports every page of the app.
      if (/[\\/]routes\.ts$/.test(next)) continue
      stack.push([next, file])
    }
  }
  return seen
}

function pagesLoadedBy(routesFile: string): string[] {
  const text = stripComments(readFileSync(routesFile, 'utf8'))
  const out: string[] = []
  for (const match of text.matchAll(/import\(\s*['"]([^'"]+)['"]\s*\)/g)) {
    const resolved = resolveSpecifier(match[1] as string, routesFile)
    if (resolved !== null) out.push(resolved)
  }
  return out
}

function filesUnder(dir: string, pattern: RegExp): string[] {
  const out: string[] = []
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    if (entry.name === 'node_modules' || entry.name === 'dist') continue
    const full = path.join(dir, entry.name)
    if (entry.isDirectory()) out.push(...filesUnder(full, pattern))
    else if (pattern.test(entry.name)) out.push(full)
  }
  return out
}

const rel = (file: string) => path.relative(REPO_ROOT, file)

/** Creator, onboarding and auth: layouts, every page their route tables load, the auth pages. */
const CREATOR_SIDE_ENTRIES: string[] = [
  path.join(SRC, 'modules/creators/layouts/CreatorDashboardLayout.vue'),
  path.join(SRC, 'modules/onboarding/layouts/OnboardingLayout.vue'),
  path.join(SRC, 'modules/auth/layouts/AuthLayout.vue'),
  ...pagesLoadedBy(path.join(SRC, 'modules/creators/routes.ts')),
  ...pagesLoadedBy(path.join(SRC, 'modules/onboarding/routes.ts')),
  ...filesUnder(path.join(SRC, 'modules/auth/pages'), /\.vue$/),
]

describe('agency theme isolation', () => {
  const reachable = closure(CREATOR_SIDE_ENTRIES)

  it('walks a real graph (sanity: the walk is not vacuous)', () => {
    expect(CREATOR_SIDE_ENTRIES.length).toBeGreaterThan(20)
    expect(reachable.size).toBeGreaterThan(150)
    const names = [...reachable.keys()].map(rel)
    expect(names).toContain('apps/main/src/modules/creators/layouts/CreatorDashboardLayout.vue')
    expect(names).toContain('apps/main/src/modules/auth/pages/SignInPage.vue')
    expect(names.some((name) => name.includes('/modules/onboarding/pages/'))).toBe(true)
  })

  it('nothing reachable from the creator, onboarding or auth trees is under modules/agency/', () => {
    const leaks = [...reachable.entries()]
      .filter(([file]) => file.startsWith(AGENCY_DIR))
      .map(
        ([file, via]) => `${rel(file)}  (imported by ${via === '(entry point)' ? via : rel(via)})`,
      )
    expect(leaks).toEqual([])
  })

  it('only files inside modules/agency/ import the agency theme directory', () => {
    const importers = filesUnder(SRC, /\.(ts|vue)$/)
      .filter((file) => !/\.(spec|test)\.ts$/.test(file) && !file.startsWith(AGENCY_DIR))
      .filter((file) => importsOf(file).some((imported) => imported.startsWith(AGENCY_THEME_DIR)))
      .map(rel)
    expect(importers).toEqual([])
  })

  it('apps/admin and packages/* never mention the agency theme', () => {
    const roots = [
      path.join(REPO_ROOT, 'apps/admin/src'),
      path.join(REPO_ROOT, 'packages/ui'),
      path.join(REPO_ROOT, 'packages/design-tokens'),
      path.join(REPO_ROOT, 'packages/api-client/src'),
    ]
    const needle =
      /agencyThemes|modules\/agency\/theme|agency\.css|v-theme--agency|agency-(dark|light)\b/
    const mentions = roots
      .flatMap((root) => filesUnder(root, /\.(ts|vue|css|js|mjs)$/))
      .filter((file) => !/[\\/]dist[\\/]/.test(file))
      .filter((file) => needle.test(readFileSync(file, 'utf8')))
      .map(rel)
    expect(mentions).toEqual([])
  })
})
