/**
 * AH-115 L0.3 — import-graph isolation, the other direction.
 *
 * AH-114's `agency-theme-isolation` proved the creator tree cannot import the agency
 * theme. AH-115 puts a creator theme (`creator-dark` / `creator-light`, registered at
 * runtime by the creator, onboarding and auth layouts) next to it, and the byte-identical
 * guarantee for the agency rests on the mirror image of that spec:
 *
 *   1. CLOSURE: from the agency entry points (`AgencyLayout`, every file under
 *      `modules/agency/`, every page an agency-layout route loads) the transitive local
 *      import closure contains no file under `modules/creators/theme/`. A shared
 *      component an agency page renders may know `useNativeLook()` (it lives in
 *      `src/composables/`, outside the theme directory) but never the creator theme itself.
 *   2. IMPORTERS: only the creator-side layouts (creators, onboarding, auth) and the
 *      creator theme directory itself may import the creator theme directory.
 *   3. OUTSIDE `apps/main`: `apps/admin` and `packages/ui` never mention the creator theme;
 *      in `packages/design-tokens` only `native.css` may (its widened selector list).
 *   4. CREATOR -> AGENCY: creator code does not import `modules/agency/` (AH-114 spec 2
 *      stays the owner; this only re-asserts it for the new theme directory).
 *
 * Imports are read from source text (comments stripped), like `agency-theme-isolation`.
 */

import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const REPO_ROOT = path.resolve(__dirname, '../../../../..')
const SRC = path.join(REPO_ROOT, 'apps/main/src')
const UI_COMPONENTS = path.join(REPO_ROOT, 'packages/ui/src/components')
const AGENCY_DIR = path.join(SRC, 'modules/agency') + path.sep
const CREATOR_THEME_DIR = path.join(SRC, 'modules/creators/theme') + path.sep

/** The only non-theme files allowed to import the creator theme directory. */
const ALLOWED_THEME_IMPORTERS: readonly string[] = [
  path.join(SRC, 'modules/creators/layouts/CreatorDashboardLayout.vue'),
  path.join(SRC, 'modules/onboarding/layouts/OnboardingLayout.vue'),
  path.join(SRC, 'modules/auth/layouts/AuthLayout.vue'),
]

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

/** Pages loaded by a route entry whose `meta` declares `layout: 'agency'`. */
function agencyPagesLoadedBy(routesFile: string): string[] {
  // Whole-line comments only: the route table's prose holds `*/` and `//` inside text that
  // `stripComments` would read as the start of a comment and swallow the file with.
  const text = readFileSync(routesFile, 'utf8')
    .replace(/^\s*\/\*[\s\S]*?\*\/\s*$/gm, '')
    .replace(/^\s*\/\/.*$/gm, '')
  const out: string[] = []
  const imports = [...text.matchAll(/import\(\s*['"]([^'"]+)['"]\s*\)/g)]
  imports.forEach((match, i) => {
    const start = (match.index ?? 0) + match[0].length
    const end = imports[i + 1]?.index ?? text.length
    if (/layout:\s*'agency'/.test(text.slice(start, end))) {
      const resolved = resolveSpecifier(match[1] as string, routesFile)
      if (resolved !== null) out.push(resolved)
    }
  })
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

const AGENCY_PAGES = agencyPagesLoadedBy(path.join(SRC, 'modules/auth/routes.ts'))

/** The agency shell: its layout, its own module, every page an agency-layout route loads. */
const AGENCY_ENTRIES: string[] = [
  ...filesUnder(path.join(SRC, 'modules/agency'), /\.(vue|ts)$/).filter(
    (file) => !/\.(spec|test)\.ts$/.test(file),
  ),
  ...AGENCY_PAGES,
]

describe('creator theme isolation (agency side)', () => {
  const reachable = closure(AGENCY_ENTRIES)

  it('walks a real graph (sanity: the walk is not vacuous)', () => {
    expect(AGENCY_PAGES.length).toBeGreaterThanOrEqual(20)
    expect(reachable.size).toBeGreaterThan(150)
    const names = [...reachable.keys()].map(rel)
    expect(names).toContain('apps/main/src/modules/agency/layouts/AgencyLayout.vue')
    expect(names).toContain('apps/main/src/modules/dashboard/pages/DashboardPage.vue')
    expect(names).toContain('apps/main/src/modules/messaging/pages/AgencyMessagesPage.vue')
  })

  it('nothing reachable from the agency shell is under modules/creators/theme/', () => {
    const leaks = [...reachable.entries()]
      .filter(([file]) => file.startsWith(CREATOR_THEME_DIR))
      .map(
        ([file, via]) => `${rel(file)}  (imported by ${via === '(entry point)' ? via : rel(via)})`,
      )
    expect(leaks).toEqual([])
  })

  it('only the creator-side layouts and the theme directory import the creator theme', () => {
    const importers = filesUnder(SRC, /\.(ts|vue)$/)
      .filter((file) => !/\.(spec|test)\.ts$/.test(file) && !file.startsWith(CREATOR_THEME_DIR))
      .filter((file) => !ALLOWED_THEME_IMPORTERS.includes(file))
      .filter((file) => importsOf(file).some((imported) => imported.startsWith(CREATOR_THEME_DIR)))
      .map(rel)
    expect(importers).toEqual([])
  })

  it('apps/admin and packages/ui never mention the creator theme; design-tokens only in native.css', () => {
    const needle =
      /creatorThemes|modules\/creators\/theme|creator\.css|v-theme--creator|creator-(dark|light)\b/
    const scan = (roots: string[], pattern: RegExp, except: readonly string[] = []) =>
      roots
        .flatMap((root) => filesUnder(root, pattern))
        .filter((file) => !/[\\/]dist[\\/]/.test(file))
        .filter((file) => !except.includes(path.relative(REPO_ROOT, file)))
        .filter((file) => needle.test(readFileSync(file, 'utf8')))
        .map(rel)

    expect(
      scan(
        [
          path.join(REPO_ROOT, 'apps/admin/src'),
          path.join(REPO_ROOT, 'packages/ui'),
          path.join(REPO_ROOT, 'packages/api-client/src'),
        ],
        /\.(ts|vue|css|js|mjs)$/,
      ),
    ).toEqual([])
    expect(
      scan([path.join(REPO_ROOT, 'packages/design-tokens')], /\.(ts|vue|css|js|mjs)$/, [
        'packages/design-tokens/native.css',
      ]),
    ).toEqual([])
  })

  it('the creator theme module never imports the agency module', () => {
    if (!existsSync(CREATOR_THEME_DIR)) return
    const offenders = filesUnder(CREATOR_THEME_DIR, /\.(ts|vue)$/)
      .filter((file) => !/\.(spec|test)\.ts$/.test(file))
      .filter((file) => importsOf(file).some((imported) => imported.startsWith(AGENCY_DIR)))
      .map(rel)
    expect(offenders).toEqual([])
  })
})
