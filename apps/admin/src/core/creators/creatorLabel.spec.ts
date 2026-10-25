import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as components from 'vuetify/components'

import DeletionRequestedChip from './DeletionRequestedChip.vue'
import {
  accountLabel,
  creatorDeletionPending,
  creatorEmail,
  creatorFromResource,
  creatorIsErased,
  creatorLabel,
  creatorRawName,
} from './creatorLabel'

const t = (key: string): string => `«${key}»`

describe('creatorLabel', () => {
  it('says "Deleted creator" for an erased creator even if a name leaked', () => {
    expect(creatorLabel({ display_name: 'Leaked Name', is_erased: true }, t)).toBe(
      '«admin.creators.erased»',
    )
    expect(creatorLabel({ display_name: null, is_erased: true }, t, 'Unnamed')).toBe(
      '«admin.creators.erased»',
    )
  })

  it('returns the trimmed name, else the fallback', () => {
    expect(creatorLabel({ display_name: '  Jane  ' }, t)).toBe('Jane')
    expect(creatorLabel({ display_name: '  ' }, t, 'Unnamed')).toBe('Unnamed')
    expect(creatorLabel(null, t, 'Unnamed')).toBe('Unnamed')
    expect(creatorLabel(undefined, t)).toBe('')
  })

  it('reads an account the same way', () => {
    expect(accountLabel({ name: 'Leaked', is_erased: true }, t)).toBe('«admin.creators.erased»')
    expect(accountLabel({ name: ' Dana ' }, t)).toBe('Dana')
    expect(accountLabel({ name: null }, t, 'x')).toBe('x')
  })

  it('never returns the placeholder email of an erased account', () => {
    expect(creatorEmail({ email: 'erased-01h@erased.invalid', is_erased: true })).toBeNull()
    expect(creatorEmail({ email: ' a@b.test ' })).toBe('a@b.test')
    expect(creatorEmail({ email: '' })).toBeNull()
    expect(creatorEmail(null)).toBeNull()
  })

  it('pending is true only for a live account that asked to leave', () => {
    expect(creatorDeletionPending({ deletion_pending: true })).toBe(true)
    expect(creatorDeletionPending({ deletion_pending: true, is_erased: true })).toBe(false)
    expect(creatorDeletionPending({ deletion_pending: false })).toBe(false)
    expect(creatorDeletionPending({})).toBe(false)
    expect(creatorIsErased({ is_erased: true })).toBe(true)
    expect(creatorIsErased(null)).toBe(false)
  })

  it('maps a creator resource and tolerates missing flags', () => {
    expect(
      creatorFromResource({
        attributes: { display_name: 'Jane' },
        admin_attributes: { email: 'j@x.test', is_erased: true, deletion_pending: true },
      }),
    ).toEqual({ display_name: 'Jane', email: 'j@x.test', is_erased: true, deletion_pending: true })
    expect(creatorFromResource({ attributes: {} })).toEqual({
      display_name: null,
      email: null,
      is_erased: false,
      deletion_pending: false,
    })
    expect(creatorFromResource(null).is_erased).toBe(false)
  })

  it('offers no raw name to edit for an erased creator', () => {
    expect(creatorRawName({ display_name: 'Jane', is_erased: true })).toBe('')
    expect(creatorRawName({ display_name: 'Jane' })).toBe('Jane')
    expect(creatorRawName(null)).toBe('')
  })
})

describe('DeletionRequestedChip', () => {
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    messages: { en: { admin: { creators: { deletion_requested: 'Deletion requested' } } } },
  })
  const chip = (account: Parameters<typeof creatorIsErased>[0]) =>
    mount(DeletionRequestedChip, {
      props: { account },
      global: { plugins: [i18n, createVuetify({ components })] },
    })

  it('shows for an account that asked to leave', () => {
    expect(chip({ deletion_pending: true }).text()).toBe('Deletion requested')
  })

  it('is absent for an ordinary, an erased and a missing account', () => {
    expect(chip({ deletion_pending: false }).find('.v-chip').exists()).toBe(false)
    expect(chip({ deletion_pending: true, is_erased: true }).find('.v-chip').exists()).toBe(false)
    expect(chip(null).find('.v-chip').exists()).toBe(false)
  })
})
