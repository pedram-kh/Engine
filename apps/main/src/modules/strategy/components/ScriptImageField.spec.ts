/**
 * PL-3.2b — the reference image field of a hook or a line: a drop zone while empty, tiles with an Add tile otherwise, the
 * limits shown near the limit only, and refusals in plain words BEFORE any request.
 */
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api/strategy.api', () => ({
  strategyApi: {
    initScriptImage: vi.fn(),
    completeScriptImage: vi.fn(),
    deleteScriptImage: vi.fn(),
  },
}))

import { strategyApi } from '../api/strategy.api'
import { makeImage } from '../scriptsTestKit'
import { mountStrategy } from '../strategyTestKit'
import ScriptImageField from './ScriptImageField.vue'

const SCOPE = { agencyId: 'A', brandId: 'B', waveId: 'W' } as never
const LIMITS = { images: 10, bytes: 300 * 1024 * 1024 }

async function mountField(images = 0, scriptBytes = 0) {
  const w = await mountStrategy(ScriptImageField, {
    scope: SCOPE,
    scriptId: 'S',
    block: 'line',
    blockId: 'L',
    images: Array.from({ length: images }, (_, i) => makeImage(i + 1)),
    scriptBytes,
    limits: LIMITS,
    testId: 'f',
  })
  await flushPromises()
  return w
}
async function pick(w: Awaited<ReturnType<typeof mountField>>, files: File[]) {
  const input = w.get('[data-test="f-image-input"]').element as HTMLInputElement
  Object.defineProperty(input, 'files', { value: files, configurable: true })
  input.dispatchEvent(new Event('change', { bubbles: true }))
  await flushPromises()
}
const file = (name: string, type: string, bytes = 10) =>
  new File([new Uint8Array(bytes)], name, { type })

describe('ScriptImageField (PL-3.2b)', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('empty: a dashed drop zone naming the types, no tiles, no Add tile', async () => {
    const w = await mountField(0)
    expect(w.find('[data-test="f-image-drop"]').exists()).toBe(true)
    expect(w.get('[data-test="f-image-drop"]').text()).toContain('GIF')
    expect(w.findAll('[data-test="script-image-tile"]')).toHaveLength(0)
    expect(w.find('[data-test="f-image-add"]').exists()).toBe(false)
  })

  it('with images: one tile each and an Add tile; usage stays hidden until 8 of 10', async () => {
    const few = await mountField(3)
    expect(few.findAll('[data-test="script-image-tile"]')).toHaveLength(3)
    expect(few.find('[data-test="f-image-add"]').exists()).toBe(true)
    expect(few.find('[data-test="f-image-usage"]').exists()).toBe(false)
    const near = await mountField(8)
    expect(near.find('[data-test="f-image-usage"]').exists()).toBe(true)
  })

  it('full block: no Add tile, the block says it is full', async () => {
    const w = await mountField(10)
    expect(w.find('[data-test="f-image-add"]').exists()).toBe(false)
    expect(w.get('[data-test="f-image-full"]').text()).toContain('10')
  })

  it('a PDF and an empty file are refused with the name and the reason, and nothing is requested', async () => {
    const w = await mountField(0)
    await pick(w, [file('notes.pdf', 'application/pdf'), file('empty.png', 'image/png', 0)])
    const notices = w.get('[data-test="f-upload-notices"]').text()
    expect(notices).toContain('notes.pdf')
    expect(notices).toContain('empty.png')
    expect(strategyApi.initScriptImage).not.toHaveBeenCalled()
  })

  it('a file that would pass the 300 MB of the script is refused with the limit in the words, before any request', async () => {
    const w = await mountField(1, LIMITS.bytes - 5)
    await pick(w, [file('big.png', 'image/png', 50)])
    expect(w.get('[data-test="f-upload-notices"]').text()).toContain('300')
    expect(strategyApi.initScriptImage).not.toHaveBeenCalled()
  })

  it('choosing more files than the block has room for: the first fits, the rest are refused by name', async () => {
    vi.mocked(strategyApi.initScriptImage).mockRejectedValue(new Error('network'))
    const w = await mountField(9)
    await pick(w, [file('one.png', 'image/png'), file('two.png', 'image/png')])
    expect(strategyApi.initScriptImage).toHaveBeenCalledTimes(1)
    const notices = w.get('[data-test="f-upload-notices"]').text()
    expect(notices).toContain('two.png')
    expect(notices).not.toContain('one.png was not added')
  })

  it('an accepted file asks the server for a reservation with its block, type and size', async () => {
    vi.mocked(strategyApi.initScriptImage).mockRejectedValue(new Error('network'))
    const w = await mountField(0)
    await pick(w, [file('ok.gif', 'image/gif', 42)])
    expect(strategyApi.initScriptImage).toHaveBeenCalledWith(SCOPE, 'S', {
      block: 'line',
      id: 'L',
      mime_type: 'image/gif',
      declared_bytes: 42,
      original_filename: 'ok.gif',
    })
    // the failure keeps its line with Retry
    expect(w.find('[data-test="f-upload-retry"]').exists()).toBe(true)
  })
})
