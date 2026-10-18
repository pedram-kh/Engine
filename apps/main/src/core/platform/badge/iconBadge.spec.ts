import { describe, expect, it, vi } from 'vitest'

import { createIconBadge, type CatalystBadgePlugin } from './iconBadge'

function setup(options: { ios?: boolean; plugin?: Partial<CatalystBadgePlugin> } = {}) {
  const set = vi.fn<CatalystBadgePlugin['set']>().mockResolvedValue({ applied: true, count: 0 })
  const plugin = vi.fn((): CatalystBadgePlugin => ({ set, ...options.plugin }))
  const badge = createIconBadge({ isIos: () => options.ios ?? true, plugin })
  return { badge, set, plugin }
}

describe('iconBadge — native iOS only (AH-108 4b)', () => {
  it('writes the count to the plugin on iOS', async () => {
    const { badge, set } = setup()

    await badge.set(7)

    expect(set).toHaveBeenCalledTimes(1)
    expect(set).toHaveBeenCalledWith({ count: 7 })
  })

  it('NEVER touches the plugin off iOS: not to set, force-set or clear (web and Android)', async () => {
    const { badge, set, plugin } = setup({ ios: false })

    await badge.set(7)
    await badge.set(7, { force: true })
    await badge.clear()

    expect(set).not.toHaveBeenCalled()
    expect(plugin).not.toHaveBeenCalled()
  })

  it('writes the real number: no 99+ cap (the OS shows what it is given)', async () => {
    const { badge, set } = setup()

    await badge.set(140)

    expect(set).toHaveBeenCalledWith({ count: 140 })
  })

  it.each([
    [-3, 0],
    [Number.NaN, 0],
    [Number.POSITIVE_INFINITY, 0],
    [2.9, 2],
  ])(
    'normalises %s to %i so a bad caller cannot leave a stale or odd badge',
    async (input, out) => {
      const { badge, set } = setup()

      await badge.set(input)

      expect(set).toHaveBeenCalledWith({ count: out })
    },
  )

  it('skips a repeat of the last written value, but force writes it again (the resume case)', async () => {
    const { badge, set } = setup()

    await badge.set(4)
    await badge.set(4)
    expect(set).toHaveBeenCalledTimes(1)

    await badge.set(4, { force: true })
    expect(set).toHaveBeenCalledTimes(2)

    await badge.set(5)
    expect(set).toHaveBeenCalledTimes(3)
  })

  it('writes a real 0 (clearing is a write, not a skip)', async () => {
    const { badge, set } = setup()

    await badge.set(3)
    await badge.set(0)

    expect(set).toHaveBeenLastCalledWith({ count: 0 })
  })

  it('clear() writes 0 and then refuses writes, so a late poll cannot revive a signed-out badge', async () => {
    const { badge, set } = setup()
    await badge.set(6)

    await badge.clear()
    expect(set).toHaveBeenLastCalledWith({ count: 0 })
    set.mockClear()

    await badge.set(9)
    await badge.set(9, { force: true })

    expect(set).not.toHaveBeenCalled()
  })

  it('enable() lets a new session write again after a clear, even the same number as before', async () => {
    const { badge, set } = setup()
    await badge.set(6)
    await badge.clear()
    set.mockClear()

    badge.enable()
    await badge.set(6)

    expect(set).toHaveBeenCalledTimes(1)
    expect(set).toHaveBeenCalledWith({ count: 6 })
  })

  it('never rejects: a plugin failure (a declined permission, a missing plugin) ends quietly', async () => {
    const { badge } = setup({
      plugin: { set: vi.fn().mockRejectedValue(new Error('not implemented')) },
    })

    await expect(badge.set(2)).resolves.toBeUndefined()
    await expect(badge.clear()).resolves.toBeUndefined()
  })

  it('never rejects when the platform probe itself throws; it just does nothing', async () => {
    const plugin = vi.fn()
    const badge = createIconBadge({
      isIos: () => {
        throw new Error('probe broke')
      },
      plugin,
    })

    await expect(badge.set(2)).resolves.toBeUndefined()
    await expect(badge.clear()).resolves.toBeUndefined()
    expect(plugin).not.toHaveBeenCalled()
  })
})
