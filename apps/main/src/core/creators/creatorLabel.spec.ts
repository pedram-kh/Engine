import { describe, expect, it } from 'vitest'

import {
  creatorAvatarAlt,
  creatorAvatarUrl,
  creatorInitial,
  creatorIsErased,
  creatorLabel,
} from './creatorLabel'

const t = (key: string): string => (key === 'app.creatorErased' ? 'Deleted creator' : key)

const erased = { display_name: 'Leaked Name', avatar_url: 'https://x/a.png', is_erased: true }
const named = { display_name: '  ada  ', avatar_url: 'https://x/a.png', is_erased: false }

describe('creatorLabel', () => {
  it('says "Deleted creator" for an erased creator, even if a name leaked into the payload', () => {
    expect(creatorLabel(erased, t)).toBe('Deleted creator')
    expect(creatorLabel({ is_erased: true }, t, 'Unnamed')).toBe('Deleted creator')
  })

  it('returns the trimmed name for a normal creator', () => {
    expect(creatorLabel(named, t)).toBe('ada')
    expect(creatorLabel({ display_name: 'Bo' }, t)).toBe('Bo')
  })

  it('falls back for a nameless creator, null or undefined', () => {
    expect(creatorLabel({ display_name: '   ' }, t, 'Unnamed')).toBe('Unnamed')
    expect(creatorLabel({ display_name: null }, t)).toBe('')
    expect(creatorLabel(null, t, 'x')).toBe('x')
    expect(creatorLabel(undefined, t)).toBe('')
  })

  it('treats only a literal true as erased', () => {
    expect(creatorIsErased({ is_erased: true })).toBe(true)
    expect(creatorIsErased({ is_erased: false })).toBe(false)
    expect(creatorIsErased({})).toBe(false)
    expect(creatorIsErased(null)).toBe(false)
  })
})

describe('creatorInitial', () => {
  it('is the upper-cased first letter', () => {
    expect(creatorInitial(named)).toBe('A')
  })
  it('is ? for erased, nameless or missing', () => {
    expect(creatorInitial(erased)).toBe('?')
    expect(creatorInitial({ display_name: ' ' })).toBe('?')
    expect(creatorInitial(null)).toBe('?')
  })
})

describe('creatorAvatarUrl / creatorAvatarAlt', () => {
  it('never returns an image for an erased creator', () => {
    expect(creatorAvatarUrl(erased)).toBeNull()
    expect(creatorAvatarAlt(erased)).toBe('')
  })
  it('returns the signed url and the name otherwise', () => {
    expect(creatorAvatarUrl(named)).toBe('https://x/a.png')
    expect(creatorAvatarAlt({ display_name: 'Ada' })).toBe('Ada')
  })
  it('returns null / empty when nothing is there', () => {
    expect(creatorAvatarUrl({})).toBeNull()
    expect(creatorAvatarUrl(undefined)).toBeNull()
    expect(creatorAvatarAlt(undefined)).toBe('')
  })
})
