import { describe, expect, it, vi } from 'vitest'

import { startAppLinks, type LinkSource } from './startAppLinks'

const LINK = 'https://platform.catalyst-growth.com/creator/jobs/01J9ZK3Q8M5T7WXYABCDEFGHJK'

async function settle(): Promise<void> {
  for (let i = 0; i < 12; i++) {
    await Promise.resolve()
  }
}

function fakeSource(over: Partial<LinkSource> = {}) {
  let emit: ((url: string) => void) | null = null
  const off = vi.fn()
  const subscribe = vi.fn(async (onUrl: (url: string) => void) => {
    emit = onUrl
    return off
  })
  const launchUrl = vi.fn(async (): Promise<string | null> => null)

  const source: LinkSource = { subscribe, launchUrl, ...over }

  return {
    source,
    subscribe,
    launchUrl,
    off,
    emit: (url: string) => emit?.(url),
  }
}

describe('startAppLinks', () => {
  it('on the web it listens to nothing and the stop function is still callable', () => {
    const { source, subscribe, launchUrl } = fakeSource()

    const stop = startAppLinks({ onLink: vi.fn(), source, isNative: () => false })

    expect(subscribe).not.toHaveBeenCalled()
    expect(launchUrl).not.toHaveBeenCalled()
    expect(() => stop()).not.toThrow()
  })

  it('on native it subscribes and reads the launch URL once', async () => {
    const { source, subscribe, launchUrl } = fakeSource()

    startAppLinks({ onLink: vi.fn(), source, isNative: () => true })
    await settle()

    expect(subscribe).toHaveBeenCalledTimes(1)
    expect(launchUrl).toHaveBeenCalledTimes(1)
  })

  it('delivers an appUrlOpen event to the handler', async () => {
    const onLink = vi.fn()
    const { source, emit } = fakeSource()

    startAppLinks({ onLink, source, isNative: () => true })
    await settle()
    emit(LINK)

    expect(onLink).toHaveBeenCalledTimes(1)
    expect(onLink).toHaveBeenCalledWith(LINK)
  })

  it('delivers the cold-start launch URL to the handler', async () => {
    const onLink = vi.fn()
    const { source } = fakeSource({ launchUrl: async () => LINK })

    startAppLinks({ onLink, source, isNative: () => true })
    await settle()

    expect(onLink).toHaveBeenCalledTimes(1)
    expect(onLink).toHaveBeenCalledWith(LINK)
  })

  it('a launch URL of null delivers nothing', async () => {
    const onLink = vi.fn()
    const { source } = fakeSource({ launchUrl: async () => null })

    startAppLinks({ onLink, source, isNative: () => true })
    await settle()

    expect(onLink).not.toHaveBeenCalled()
  })

  it('the same link through both sources reaches the handler twice (the duplicate guard is the handler’s job, D3)', async () => {
    const onLink = vi.fn()
    const { source, emit } = fakeSource({ launchUrl: async () => LINK })

    startAppLinks({ onLink, source, isNative: () => true })
    await settle()
    emit(LINK)

    expect(onLink).toHaveBeenCalledTimes(2)
  })

  it('a subscribe that fails is swallowed and the launch URL is still read', async () => {
    const onLink = vi.fn()
    const { source } = fakeSource({
      subscribe: async () => {
        throw new Error('no bridge')
      },
      launchUrl: async () => LINK,
    })

    expect(() => startAppLinks({ onLink, source, isNative: () => true })).not.toThrow()
    await settle()

    expect(onLink).toHaveBeenCalledWith(LINK)
  })

  it('a launch URL read that fails is swallowed and events still flow', async () => {
    const onLink = vi.fn()
    const { source, emit } = fakeSource({
      launchUrl: async () => {
        throw new Error('no launch url')
      },
    })

    expect(() => startAppLinks({ onLink, source, isNative: () => true })).not.toThrow()
    await settle()
    emit(LINK)

    expect(onLink).toHaveBeenCalledWith(LINK)
  })

  it('a handler that throws never reaches the bridge', async () => {
    const onLink = vi.fn(() => {
      throw new Error('handler bug')
    })
    const { source, emit } = fakeSource({ launchUrl: async () => LINK })

    startAppLinks({ onLink, source, isNative: () => true })
    await settle()

    expect(() => emit(LINK)).not.toThrow()
  })

  it('stop() unsubscribes, and later events and a late launch URL are dropped', async () => {
    const onLink = vi.fn()
    const { source, off, emit } = fakeSource()

    const stop = startAppLinks({ onLink, source, isNative: () => true })
    await settle()
    stop()

    expect(off).toHaveBeenCalledTimes(1)
    emit(LINK)
    expect(onLink).not.toHaveBeenCalled()
  })

  it('stop() before the subscription resolves releases it the moment it does', async () => {
    let resolve!: (off: () => void) => void
    const off = vi.fn()
    const { source } = fakeSource({
      subscribe: () =>
        new Promise<() => void>((r) => {
          resolve = r
        }),
    })

    const stop = startAppLinks({ onLink: vi.fn(), source, isNative: () => true })
    stop()
    resolve(off)
    await settle()

    expect(off).toHaveBeenCalledTimes(1)
  })
})
