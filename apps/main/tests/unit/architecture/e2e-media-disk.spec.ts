/**
 * Source-inspection architecture gate (AH-053) — the E2E media disk.
 *
 * The brand-logo pipeline writes to `Storage::disk('media')`. Under E2E there
 * is no object store: the `e2e-main` CI job provisions Postgres and Redis
 * only, and `make dev`'s MinIO is not part of it. Every disk in
 * `config/filesystems.php` is `'throw' => false`, so an S3 write against an
 * absent bucket does not raise — `put()` returns `false`. Without an override
 * the upload endpoint would answer 200 having stored nothing, and the
 * Playwright logo leg would pass over a lost file: a green test asserting the
 * opposite of the truth.
 *
 * `MEDIA_DISK_DRIVER=local` is the fix, and it only works if BOTH halves stay
 * in place — the config branch that reads the variable, and the webServer env
 * that sets it. Either one silently dropped restores the dishonest-green, and
 * nothing else in the suite would notice. Hence this gate.
 *
 * The third assertion is the one that protects production: the branch must
 * DEFAULT to s3, so an environment that never sets the variable (staging,
 * production, a developer's `pnpm dev`) is unaffected by its existence.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const REPO_ROOT = path.resolve(__dirname, '../../../../..')

const read = (rel: string): string => readFileSync(path.resolve(REPO_ROOT, rel), 'utf8')

describe('E2E media disk (the logo pipeline must not pass over a lost file)', () => {
  const config = read('apps/main/playwright.config.ts')
  const filesystems = read('apps/api/config/filesystems.php')

  it('playwright.config.ts pins the E2E media driver to local', () => {
    expect(config).toContain("const E2E_MEDIA_DISK_DRIVER = 'local'")
  })

  it('playwright.config.ts applies the driver to the API webServer env', () => {
    expect(config).toContain('MEDIA_DISK_DRIVER: E2E_MEDIA_DISK_DRIVER')
  })

  it('the media disk reads MEDIA_DISK_DRIVER and defaults to s3', () => {
    expect(filesystems).toContain("env('MEDIA_DISK_DRIVER', 's3') === 'local'")
  })

  it('the local branch serves signed URLs, so temporaryUrl() keeps its production shape', () => {
    // Without `serve => true` the local driver throws
    // "This driver does not support creating temporary URLs" and every
    // `logo_url` emission 500s — see LocalFilesystemAdapter::temporaryUrl.
    const branch = filesystems.slice(
      filesystems.indexOf("env('MEDIA_DISK_DRIVER', 's3') === 'local'"),
      filesystems.indexOf("'contracts' => ["),
    )
    expect(branch).toContain("'driver' => 'local'")
    expect(branch).toContain("'serve' => true")
  })

  it('the served route stays off /storage, which the local disk already claims', () => {
    // `serve => true` with no `url` defaults the route to `/storage/{path}`,
    // where `{path}` is a `.*` wildcard — the exact URI the `local` disk
    // registers first. First route wins, so `storage.local` would swallow
    // every `/storage/media/...` request, be handed `media/agencies/...`,
    // find nothing, and 404. The signature validates either way (it is
    // computed over the URL, not the route), so the only symptom is a logo
    // that silently never renders.
    const branch = filesystems.slice(
      filesystems.indexOf("env('MEDIA_DISK_DRIVER', 's3') === 'local'"),
      filesystems.indexOf("'contracts' => ["),
    )
    expect(branch).toContain("'url' => env('APP_URL').'/e2e-media'")
    expect(branch).not.toContain("'/storage")
  })
})
