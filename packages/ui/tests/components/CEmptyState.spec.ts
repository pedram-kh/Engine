/**
 * Unit tests for the shared `CEmptyState` scaffold (`@catalyst/ui`).
 *
 * Runs under the package's own Vitest harness (Sprint 4 Chunk 1 sub-step
 * 1a) via the theme-aware `mountThemed` helper.
 *
 * What this pins (§ 1.4 + Decision D-empty-state + Q-chunk-2-3 slot-only):
 *   - `dataTest` is applied to the root (anchor preservation during the
 *     5 call-site migrations).
 *   - `title` / `body` render only when provided (the variant cleanliness
 *     that lets one component serve both "no-results-at-all" and
 *     "no-results-matching-filter").
 *   - `icon` / `action` slots render only when supplied.
 *
 * CEmptyState's own template uses no Vuetify components; mounting via
 * `mountThemed` simply installs the themed Vuetify plugin (harmless) and
 * keeps the spec consistent with the rest of the harness.
 */

import { describe, expect, it } from 'vitest'

import CEmptyState from '../../src/components/CEmptyState.vue'

import { mountThemed } from '../helpers/mountThemed'

describe('CEmptyState — anchoring', () => {
  it('applies the dataTest prop to the root element', () => {
    const { wrapper } = mountThemed(CEmptyState, { props: { dataTest: 'members-empty-state' } })
    expect(wrapper.find('[data-test="members-empty-state"]').exists()).toBe(true)
  })
})

describe('CEmptyState — title/body props', () => {
  it('renders the title in an <h3> by default when provided', () => {
    const { wrapper } = mountThemed(CEmptyState, { props: { title: 'No brands yet' } })
    const title = wrapper.find('.c-empty-state__title')
    expect(title.exists()).toBe(true)
    expect(title.element.tagName).toBe('H3')
    expect(title.text()).toBe('No brands yet')
  })

  it('renders the title with the titleTag override (h2 for BrandListPage)', () => {
    const { wrapper } = mountThemed(CEmptyState, {
      props: { title: 'No brands yet', titleTag: 'h2' },
    })
    const title = wrapper.find('.c-empty-state__title')
    expect(title.exists()).toBe(true)
    expect(title.element.tagName).toBe('H2')
    expect(title.text()).toBe('No brands yet')
  })

  it('honours an h4 titleTag override', () => {
    const { wrapper } = mountThemed(CEmptyState, { props: { title: 'Nested', titleTag: 'h4' } })
    expect(wrapper.find('.c-empty-state__title').element.tagName).toBe('H4')
  })

  it('omits the title node entirely when no title prop is given', () => {
    const { wrapper } = mountThemed(CEmptyState, { props: { body: 'Body only' } })
    expect(wrapper.find('.c-empty-state__title').exists()).toBe(false)
  })

  it('renders the body in a <p> when provided', () => {
    const { wrapper } = mountThemed(CEmptyState, {
      props: { body: 'Invite your team to get started.' },
    })
    const body = wrapper.find('.c-empty-state__body')
    expect(body.exists()).toBe(true)
    expect(body.element.tagName).toBe('P')
    expect(body.text()).toBe('Invite your team to get started.')
  })

  it('omits the body node entirely when no body prop is given', () => {
    const { wrapper } = mountThemed(CEmptyState, { props: { title: 'Title only' } })
    expect(wrapper.find('.c-empty-state__body').exists()).toBe(false)
  })
})

describe('CEmptyState — icon/action slots', () => {
  it('renders the icon slot wrapper only when the slot is supplied', () => {
    const withIcon = mountThemed(CEmptyState, {
      props: { body: 'x' },
      slots: { icon: '<span class="stub-icon">i</span>' },
    })
    expect(withIcon.wrapper.find('.c-empty-state__icon').exists()).toBe(true)
    expect(withIcon.wrapper.find('.stub-icon').exists()).toBe(true)

    const withoutIcon = mountThemed(CEmptyState, { props: { body: 'x' } })
    expect(withoutIcon.wrapper.find('.c-empty-state__icon').exists()).toBe(false)
  })

  it('renders the action slot wrapper only when the slot is supplied', () => {
    const withAction = mountThemed(CEmptyState, {
      props: { title: 'x' },
      slots: { action: '<button class="stub-cta">Create</button>' },
    })
    expect(withAction.wrapper.find('.c-empty-state__action').exists()).toBe(true)
    expect(withAction.wrapper.find('.stub-cta').exists()).toBe(true)

    const withoutAction = mountThemed(CEmptyState, { props: { title: 'x' } })
    expect(withoutAction.wrapper.find('.c-empty-state__action').exists()).toBe(false)
  })
})

describe('CEmptyState — variants', () => {
  it('supports the icon + body "filtered" variant (no title, no action)', () => {
    const { wrapper } = mountThemed(CEmptyState, {
      props: { dataTest: 'members-empty-filtered', body: 'No matches' },
      slots: { icon: '<span class="stub-icon" />' },
    })
    expect(wrapper.find('[data-test="members-empty-filtered"]').exists()).toBe(true)
    expect(wrapper.find('.c-empty-state__icon').exists()).toBe(true)
    expect(wrapper.find('.c-empty-state__body').exists()).toBe(true)
    expect(wrapper.find('.c-empty-state__title').exists()).toBe(false)
    expect(wrapper.find('.c-empty-state__action').exists()).toBe(false)
  })

  it('supports the full icon + title + body + action "first-run" variant', () => {
    const { wrapper } = mountThemed(CEmptyState, {
      props: { dataTest: 'brand-empty-state', title: 'No brands yet', body: 'Create your first.' },
      slots: {
        icon: '<span class="stub-icon" />',
        action: '<button class="stub-cta">Create</button>',
      },
    })
    expect(wrapper.find('[data-test="brand-empty-state"]').exists()).toBe(true)
    expect(wrapper.find('.c-empty-state__icon').exists()).toBe(true)
    expect(wrapper.find('.c-empty-state__title').text()).toBe('No brands yet')
    expect(wrapper.find('.c-empty-state__body').text()).toBe('Create your first.')
    expect(wrapper.find('.stub-cta').exists()).toBe(true)
  })
})
