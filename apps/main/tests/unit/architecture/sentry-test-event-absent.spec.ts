// @vitest-environment node
/**
 * AH-121 C3 (plan change 2): a build made WITHOUT `VITE_SENTRY_TEST_EVENT` contains no
 * test-event code and not the string `AH-121 test event`; the positive control (a build
 * WITH the flag) does, so the check can fail. Builds in memory (`write: false`) in the
 * plain `production` mode, so no env file with a DSN is read. Also proves the Capacitor SDK
 * is not in the entry chunk of a web build.
 */

import path from 'node:path'

import { build, type Rollup } from 'vite'
import { afterEach, describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../../..')

interface Chunk {
  readonly fileName: string
  readonly isEntry: boolean
  readonly code: string
}

async function buildChunks(flag: string | undefined): Promise<Chunk[]> {
  if (flag === undefined) {
    delete process.env.VITE_SENTRY_TEST_EVENT
  } else {
    process.env.VITE_SENTRY_TEST_EVENT = flag
  }
  const result = (await build({
    root: ROOT,
    configFile: path.join(ROOT, 'vite.config.ts'),
    mode: 'production',
    logLevel: 'silent',
    build: { write: false, reportCompressedSize: false, sourcemap: false },
  })) as Rollup.RollupOutput | Rollup.RollupOutput[]
  const outputs = Array.isArray(result) ? result : [result]
  return outputs
    .flatMap((o) => o.output)
    .filter((o): o is Rollup.OutputChunk => o.type === 'chunk')
    .map((o) => ({ fileName: o.fileName, isEntry: o.isEntry, code: o.code }))
}

afterEach(() => {
  delete process.env.VITE_SENTRY_TEST_EVENT
})

const MARKERS = ['AH-121 test event', 'ah121.sentry.testEvent']

describe('test-event path', () => {
  it('is absent from a build without the flag, present in one with it', async () => {
    const without = await buildChunks(undefined)
    for (const marker of MARKERS) {
      expect(without.filter((c) => c.code.includes(marker)).map((c) => c.fileName)).toEqual([])
    }
    expect(without.filter((c) => /sentryTestEvent/.test(c.fileName))).toEqual([])

    for (const flag of ['1', 'crash', 'early']) {
      const withFlag = await buildChunks(flag)
      for (const marker of MARKERS) {
        expect(withFlag.some((c) => c.code.includes(marker))).toBe(true)
      }
    }
  }, 240_000)

  it('an empty flag value counts as unset', async () => {
    const empty = await buildChunks('')
    for (const marker of MARKERS) {
      expect(empty.some((c) => c.code.includes(marker))).toBe(false)
    }
  }, 120_000)

  it('the Capacitor SDK is not in the entry chunk of a web build', async () => {
    const chunks = await buildChunks(undefined)
    const entry = chunks.filter((c) => c.isEntry)
    expect(entry.length).toBeGreaterThan(0)
    for (const chunk of entry) {
      expect(chunk.code).not.toContain('sentry.javascript.capacitor')
      expect(chunk.code).not.toContain('SentryCapacitor')
    }
    expect(chunks.some((c) => c.code.includes('SentryCapacitor'))).toBe(true)
  }, 120_000)
})
