/**
 * Sprint 6 Chunk 2b — Vitest coverage for the add-to-pool picker dialog
 * (D-2b-9). The toggle reflects the creator's current membership (is_member)
 * and calls the add/remove endpoints; both flows emit a `changed` message for
 * the parent snackbar.
 */

import type { TalentPoolPickerItem } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'
import { createI18n } from 'vue-i18n'

import enApp from '@/core/i18n/locales/en/app.json'

import AddToPoolDialog from './AddToPoolDialog.vue'

vi.mock('../api/talentPools.api', () => ({
  talentPoolsApi: {
    poolsForCreator: vi.fn(),
    addCreator: vi.fn(),
    removeCreator: vi.fn(),
  },
}))

import { talentPoolsApi } from '../api/talentPools.api'

const CREATOR = '01CREATORULIDXXXXXXXXXXXXXX'

function pickerItem(overrides: Partial<TalentPoolPickerItem['attributes']> & { id?: string } = {}) {
  const { id, ...attrs } = overrides
  return {
    id: id ?? '01POOLULIDXXXXXXXXXXXXXXXX',
    type: 'talent_pools' as const,
    attributes: {
      name: 'Acme Q3',
      brand_name: null,
      is_member: false,
      ...attrs,
    },
  }
}

function mountDialog(pools: TalentPoolPickerItem[]) {
  vi.mocked(talentPoolsApi.poolsForCreator).mockResolvedValue({ data: pools })

  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: enApp } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({
    components: vuetifyComponents,
    directives: vuetifyDirectives,
  })

  return mount(AddToPoolDialog, {
    props: { modelValue: true, agencyId: 'agency-ulid', creatorUlid: CREATOR },
    global: { plugins: [i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
}

describe('AddToPoolDialog (Sprint 6 Chunk 2b)', () => {
  let wrapper: ReturnType<typeof mount> | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
  })

  it('fetches the creator pool membership in one call when opened', async () => {
    wrapper = mountDialog([pickerItem({ is_member: true })])
    await flushPromises()
    expect(talentPoolsApi.poolsForCreator).toHaveBeenCalledTimes(1)
    expect(talentPoolsApi.poolsForCreator).toHaveBeenCalledWith('agency-ulid', CREATOR)
  })

  it('renders a row per pool reflecting current membership', async () => {
    const inPool = pickerItem({ id: '01IN', name: 'Member Pool', is_member: true })
    const outPool = pickerItem({ id: '01OUT', name: 'Other Pool', is_member: false })
    wrapper = mountDialog([inPool, outPool])
    await flushPromises()

    expect(document.querySelector('[data-test="add-to-pool-row-01IN"]')).not.toBeNull()
    expect(document.querySelector('[data-test="add-to-pool-row-01OUT"]')).not.toBeNull()
  })

  it('toggling an out pool ON calls addCreator and emits changed', async () => {
    const outPool = pickerItem({ id: '01OUT', name: 'Other Pool', is_member: false })
    wrapper = mountDialog([outPool])
    await flushPromises()

    vi.mocked(talentPoolsApi.addCreator).mockResolvedValue({
      data: {} as unknown as Awaited<ReturnType<typeof talentPoolsApi.addCreator>>['data'],
    })

    await (wrapper.vm as unknown as { toggle: (p: TalentPoolPickerItem) => Promise<void> }).toggle(
      outPool,
    )
    await flushPromises()

    expect(talentPoolsApi.addCreator).toHaveBeenCalledWith('agency-ulid', '01OUT', CREATOR)
    expect(talentPoolsApi.removeCreator).not.toHaveBeenCalled()
    expect(wrapper.emitted('changed')?.[0]?.[0]).toContain('Other Pool')
  })

  it('toggling an in pool OFF calls removeCreator', async () => {
    const inPool = pickerItem({ id: '01IN', name: 'Member Pool', is_member: true })
    wrapper = mountDialog([inPool])
    await flushPromises()

    vi.mocked(talentPoolsApi.removeCreator).mockResolvedValue({
      data: {} as unknown as Awaited<ReturnType<typeof talentPoolsApi.removeCreator>>['data'],
    })

    await (wrapper.vm as unknown as { toggle: (p: TalentPoolPickerItem) => Promise<void> }).toggle(
      inPool,
    )
    await flushPromises()

    expect(talentPoolsApi.removeCreator).toHaveBeenCalledWith('agency-ulid', '01IN', CREATOR)
    expect(talentPoolsApi.addCreator).not.toHaveBeenCalled()
  })

  it('shows an empty state when the agency has no pools', async () => {
    wrapper = mountDialog([])
    await flushPromises()
    expect(document.querySelector('[data-test="add-to-pool-empty"]')).not.toBeNull()
  })
})
