import { describe, expect, it } from 'vitest'

import { deepMergeLocale } from '@/core/i18n/deepMerge'

describe('deepMergeLocale', () => {
  it('merges disjoint top-level keys', () => {
    const merged = deepMergeLocale({ a: 1 }, { b: 2 })
    expect(merged).toEqual({ a: 1, b: 2 })
  })

  it('preserves both subtrees when a shared top-level key collides (the admin.* case)', () => {
    const creators = { admin: { creators: { title: 'Creators' } } }
    const agencies = { admin: { agencies: { title: 'Agencies' } } }

    const merged = deepMergeLocale(creators, agencies)

    expect(merged).toEqual({
      admin: {
        creators: { title: 'Creators' },
        agencies: { title: 'Agencies' },
      },
    })
  })

  it('recurses through multiple nesting levels without dropping siblings', () => {
    const a = { admin: { nav: { dashboard: 'D', shared: { x: 1 } } } }
    const b = { admin: { nav: { agencies: 'A', shared: { y: 2 } } } }

    const merged = deepMergeLocale(a, b)

    expect(merged).toEqual({
      admin: {
        nav: {
          dashboard: 'D',
          agencies: 'A',
          shared: { x: 1, y: 2 },
        },
      },
    })
  })

  it('lets a later leaf override an earlier leaf (last-wins on conflict)', () => {
    const merged = deepMergeLocale({ k: 'first' }, { k: 'second' })
    expect(merged).toEqual({ k: 'second' })
  })

  it('does not mutate its inputs', () => {
    const a = { admin: { creators: { title: 'Creators' } } }
    const b = { admin: { agencies: { title: 'Agencies' } } }
    const aSnapshot = structuredClone(a)
    const bSnapshot = structuredClone(b)

    deepMergeLocale(a, b)

    expect(a).toEqual(aSnapshot)
    expect(b).toEqual(bSnapshot)
  })
})
