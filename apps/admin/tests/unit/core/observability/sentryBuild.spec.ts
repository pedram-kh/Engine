/**
 * AH-121 C2: release naming and source-map settings (tools/sentryBuild.ts), and the
 * `.map` build check. Mirrored in both SPAs.
 */

import { spawnSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import path from 'node:path'

import { afterEach, describe, expect, it } from 'vitest'

import { resolveRelease, sentryBuildSettings, type GitRunner } from '../../../../tools/sentryBuild'
import { findSourceMaps } from '../../../../scripts/check-no-sourcemaps.mjs'

const APP_TREE = 'a'.repeat(40)
const PKG_TREE = 'b'.repeat(40)

const fakeGit =
  (dirty = false): GitRunner =>
  (args) => {
    if (args[0] === 'rev-parse' && args[1]?.startsWith('HEAD:apps/')) return APP_TREE
    if (args[0] === 'rev-parse' && args[1] === 'HEAD:packages') return PKG_TREE
    if (args[0] === 'status') return dirty ? ' M apps/x/y.ts' : ''
    throw new Error(`unexpected git ${args.join(' ')}`)
  }

describe('release name', () => {
  it('is catalyst-<surface>@<12 hex of sha1(app tree + packages tree)>', () => {
    const expected = createHash('sha1')
      .update(APP_TREE + PKG_TREE)
      .digest('hex')
      .slice(0, 12)
    expect(resolveRelease('main', {}, fakeGit())).toEqual({
      release: `catalyst-main@${expected}`,
      warning: null,
    })
    expect(resolveRelease('admin', {}, fakeGit()).release).toBe(`catalyst-admin@${expected}`)
  })

  it('appends -dirty for a dirty tree', () => {
    expect(resolveRelease('main', {}, fakeGit(true)).release).toMatch(
      /^catalyst-main@[0-9a-f]{12}-dirty$/,
    )
  })

  it('checks dirtiness with root-anchored pathspecs (the build runs from inside apps/<surface>)', () => {
    const seen: string[][] = []
    resolveRelease('admin', {}, (args) => {
      seen.push([...args])
      return fakeGit()(args)
    })
    expect(seen.find((a) => a[0] === 'status')).toEqual([
      'status',
      '--porcelain',
      '--',
      ':/apps/admin',
      ':/packages',
    ])
  })

  it('SENTRY_RELEASE overrides', () => {
    expect(resolveRelease('main', { SENTRY_RELEASE: ' custom@1 ' }, fakeGit()).release).toBe(
      'custom@1',
    )
  })

  it('has NO release and a loud warning when git is unavailable and there is no override', () => {
    const noGit: GitRunner = () => {
      throw new Error('not a git repository')
    }
    const result = resolveRelease('main', {}, noGit)
    expect(result.release).toBe('')
    expect(result.warning).toContain('WARNING')
    expect(result.warning).toContain('SENTRY_RELEASE')
  })
})

describe('source maps', () => {
  it('emits no maps and no plugin without SENTRY_AUTH_TOKEN', () => {
    expect(sentryBuildSettings('catalyst-main@0123456789ab', {}, 'dist/**/*.map')).toEqual({
      sourcemap: false,
      plugins: [],
    })
  })

  it('emits no maps without a release even with a token (they could not be matched)', () => {
    expect(sentryBuildSettings('', { SENTRY_AUTH_TOKEN: 'x' }, 'dist/**/*.map')).toEqual({
      sourcemap: false,
      plugins: [],
    })
  })

  it('emits hidden maps and one upload plugin with a token and a release', () => {
    const settings = sentryBuildSettings(
      'catalyst-main@0123456789ab',
      { SENTRY_AUTH_TOKEN: 'x' },
      'dist/**/*.map',
    )
    expect(settings.sourcemap).toBe('hidden')
    expect(settings.plugins.length).toBeGreaterThan(0)
  })
})

describe('the .map build check', () => {
  const dirs: string[] = []
  afterEach(() => {
    for (const dir of dirs.splice(0)) rmSync(dir, { recursive: true, force: true })
  })
  const makeDist = (files: string[]): string => {
    const dir = mkdtempSync(path.join(tmpdir(), 'dist-'))
    dirs.push(dir)
    for (const file of files) {
      mkdirSync(path.dirname(path.join(dir, file)), { recursive: true })
      writeFileSync(path.join(dir, file), 'x')
    }
    return dir
  }
  const script = path.resolve(__dirname, '../../../../scripts/check-no-sourcemaps.mjs')

  it('finds maps recursively and ignores everything else', () => {
    const dist = makeDist(['index.html', 'assets/a.js', 'assets/deep/b.js.map', 'c.css.map'])
    expect(
      findSourceMaps(dist)
        .map((f: string) => path.relative(dist, f))
        .sort(),
    ).toEqual(['assets/deep/b.js.map', 'c.css.map'])
    expect(findSourceMaps(path.join(dist, 'missing'))).toEqual([])
  })

  it('CLI exits 1 and names the file when a .map remains, 0 when none', () => {
    const bad = makeDist(['assets/a.js', 'assets/a.js.map'])
    const failed = spawnSync('node', [script, bad], { encoding: 'utf8' })
    expect(failed.status).toBe(1)
    expect(failed.stderr).toContain('a.js.map')
    const good = makeDist(['assets/a.js'])
    expect(spawnSync('node', [script, good], { encoding: 'utf8' }).status).toBe(0)
  })
})
