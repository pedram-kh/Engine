/**
 * AH-103d — the ordering the reinstall marker depends on, pinned as SOURCE.
 *
 * The behaviour specs (`useAuthStore.installMarker.spec.ts`) prove the gates work
 * when they exist; this spec proves they cannot be deleted, reordered or bypassed
 * by a new early token reader without someone deciding to. It reads files as text
 * (no import: that would drag Vuetify, Pinia and the page graph in; §5.15).
 *
 * The facts it pins, each from the plan (`reinstall-marker-plan.md` §1-§2):
 *   1. `app.use(router)` starts the first navigation BEFORE mount, and
 *      `requireAuth` -> `bootstrap()` -> `tokens.read()` can run before then, so the
 *      reconcile must be awaited INSIDE `bootstrap()`, before the read.
 *   2. `requireGuest` does NOT call `bootstrap()` (the sign-in page is reachable
 *      first), so `loginWithToken()` must await it before it WRITES a token, or a
 *      late reconcile would wipe the session just made.
 *   3. The reconcile starts eagerly in `main.ts`, after Pinia and before the router.
 *   4. The token store is NOT gated: the sign-out reads the token to revoke it, so
 *      a gate there would deadlock the wipe.
 *   5. Only three files may read the token store; a new reader must be a decision.
 */

import { promises as fs } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const SRC = path.resolve(__dirname, '../../../src')

async function read(rel: string): Promise<string> {
  return fs.readFile(path.join(SRC, rel), 'utf8')
}

/** The text of one top-level function inside the auth store's setup closure. */
function functionBody(source: string, signature: string): string {
  const start = source.indexOf(signature)
  expect(start, `${signature} not found: has the store been refactored?`).toBeGreaterThan(-1)
  const rest = source.slice(start + signature.length)
  const next = rest.search(/\n {2}(?:async )?function \w+/)
  expect(next, 'could not find the end of the function body').toBeGreaterThan(-1)
  return rest.slice(0, next)
}

describe('install marker — the gates exist and are ordered', () => {
  it('bootstrap() awaits the reconcile BEFORE the native token read', async () => {
    const store = await read('modules/auth/stores/useAuthStore.ts')
    const body = functionBody(store, 'async function bootstrap(): Promise<void> {')
    const gate = body.indexOf('await reconcileInstall()')
    const tokenRead = body.indexOf('tokens.read()')
    expect(gate).toBeGreaterThan(-1)
    expect(tokenRead).toBeGreaterThan(-1)
    expect(gate).toBeLessThan(tokenRead)
  })

  it('loginWithToken() awaits the reconcile BEFORE it writes the token', async () => {
    const store = await read('modules/auth/stores/useAuthStore.ts')
    const body = functionBody(store, 'async function loginWithToken(')
    const gate = body.indexOf('await reconcileInstall()')
    const write = body.indexOf('.write(result.token')
    expect(gate).toBeGreaterThan(-1)
    expect(write).toBeGreaterThan(-1)
    expect(gate).toBeLessThan(write)
  })

  it('main.ts starts the reconcile after Pinia is active and before the router is installed', async () => {
    const main = await read('main.ts')
    const pinia = main.indexOf('app.use(createPinia())')
    const start = main.indexOf('void useAuthStore().reconcileInstall()')
    const router = main.indexOf('app.use(router)')
    expect(pinia).toBeGreaterThan(-1)
    expect(start).toBeGreaterThan(pinia)
    expect(router).toBeGreaterThan(start)
  })
})

describe('install marker — why the second gate is needed, and why the store is not gated', () => {
  it('requireGuest does not call bootstrap() (so the login gate is what protects that path); requireAuth does', async () => {
    const guards = await read('core/router/guards.ts')
    const guest = guards.slice(guards.indexOf('export async function requireGuest'))
    const guestBody = guest.slice(0, guest.indexOf('\n}\n'))
    expect(guestBody).not.toContain('bootstrap(')

    const auth = guards.slice(guards.indexOf('export async function requireAuth'))
    const authBody = auth.slice(0, auth.indexOf('\n}\n'))
    expect(authBody).toContain('await store.bootstrap()')
  })

  it('the token store does not import or call the reconcile (a gate there would deadlock the sign-out)', async () => {
    const tokenStore = await read('core/auth/tokenStore.ts')
    expect(tokenStore).not.toContain('installMarker')
    expect(tokenStore).not.toContain('reconcileInstall')
  })

  it('the marker module imports nothing from the auth store or the router (it stays a pure policy)', async () => {
    const marker = await read('core/auth/installMarker.ts')
    const imports = marker.match(/^import .*$/gm) ?? []
    expect(imports).toEqual(["import type { TokenStore } from '@/core/auth/tokenStore'"])
  })
})

describe('install marker — nothing reads the token before the gate unless it is named here', () => {
  it('exactly these non-spec files use the native token store', async () => {
    const walk = async (dir: string): Promise<string[]> => {
      const out: string[] = []
      for (const entry of await fs.readdir(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name)
        if (entry.isDirectory()) {
          out.push(...(await walk(full)))
        } else if (/\.(ts|vue)$/.test(entry.name) && !entry.name.endsWith('.spec.ts')) {
          out.push(full)
        }
      }
      return out
    }
    const users: string[] = []
    for (const file of await walk(SRC)) {
      if ((await fs.readFile(file, 'utf8')).includes('nativeTokenStore')) {
        users.push(path.relative(SRC, file).split(path.sep).join('/'))
      }
    }
    // tokenStore.ts defines it; api/index.ts reads it per request AFTER a request is
    // made (the first is /me inside the gated bootstrap); the auth store is the gate.
    // A new reader here must be reviewed against AH-103d's ordering.
    expect(users.sort()).toEqual([
      'core/api/index.ts',
      'core/auth/tokenStore.ts',
      'modules/auth/stores/useAuthStore.ts',
    ])
  })
})
