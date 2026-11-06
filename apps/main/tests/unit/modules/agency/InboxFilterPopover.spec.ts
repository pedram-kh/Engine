/**
 * AH-119 — `InboxFilterPopover`: the chip, the searchable multi-select and its footer.
 *
 * Vuetify menus teleport to `document.body`, so the popover is queried there.
 *
 * Break-revert: show a "0" badge for a thread-less item → the zero-count case fails; read the
 * chip label from the first id only → the "2 selected" case fails; fall back to nothing for an
 * unknown colour key → the slate fallback case fails.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'
import InboxFilterPopover, {
  type InboxFilterItem,
} from '@/modules/agency/components/InboxFilterPopover.vue'

const ITEMS: InboxFilterItem[] = [
  { id: 'b1', name: 'Acme', color: 'blue', unreadThreads: 3 },
  { id: 'b2', name: 'Globex', color: 'green', unreadThreads: 0 },
  { id: 'b3', name: 'Initech', color: 'mystery', unreadThreads: 1 },
  { id: 'b4', name: 'Umbrella', color: null, unreadThreads: 12 },
]

function mountPopover(props: Record<string, unknown> = {}) {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  return mount(InboxFilterPopover, {
    props: {
      modelValue: [],
      items: ITEMS,
      allLabel: 'All brands',
      heading: 'Brands',
      searchPlaceholder: 'Search brands',
      noMatch: 'No brands match',
      icon: 'mdi-tag-outline',
      dots: true,
      testKey: 'brand',
      ...props,
    },
    global: { plugins: [i18n, vuetify] },
    attachTo: document.body,
  })
}

const chip = (wrapper: ReturnType<typeof mount>) => wrapper.get('[data-test="inbox-filter-brand"]')

function inBody<T extends HTMLElement = HTMLElement>(testId: string): T | null {
  return document.body.querySelector<T>(`[data-test="${testId}"]`)
}

async function open(wrapper: ReturnType<typeof mount>): Promise<void> {
  await chip(wrapper).trigger('click')
  await flushPromises()
}

describe('InboxFilterPopover', () => {
  afterEach(() => {
    document.body.innerHTML = ''
  })

  describe('chip label', () => {
    it('reads the "all" word when nothing is selected', () => {
      const wrapper = mountPopover()
      expect(chip(wrapper).text()).toBe('All brands')
      expect(chip(wrapper).classes()).not.toContain('inbox-chip--on')
      wrapper.unmount()
    })

    it("reads the item's name when one is selected", () => {
      const wrapper = mountPopover({ modelValue: ['b2'] })
      expect(chip(wrapper).text()).toBe('Globex')
      expect(chip(wrapper).classes()).toContain('inbox-chip--on')
      wrapper.unmount()
    })

    it('reads "2 selected" for two and "3 selected" for three', async () => {
      const wrapper = mountPopover({ modelValue: ['b1', 'b2'] })
      expect(chip(wrapper).text()).toBe('2 selected')
      await wrapper.setProps({ modelValue: ['b1', 'b2', 'b3'] })
      expect(chip(wrapper).text()).toBe('3 selected')
      wrapper.unmount()
    })

    it('falls back to the "all" word when the single selected id is not among the items', () => {
      const wrapper = mountPopover({ modelValue: ['gone'] })
      expect(chip(wrapper).text()).toBe('All brands')
      wrapper.unmount()
    })
  })

  describe('opening and searching', () => {
    it('is closed until the chip is clicked, then shows heading, search box and every item', async () => {
      const wrapper = mountPopover()
      expect(chip(wrapper).attributes('aria-expanded')).toBe('false')
      expect(inBody('inbox-filter-brand-popover')).toBeNull()

      await open(wrapper)
      expect(chip(wrapper).attributes('aria-expanded')).toBe('true')
      const pop = inBody('inbox-filter-brand-popover')
      expect(pop).not.toBeNull()
      expect(pop?.textContent).toContain('Brands')
      expect(
        inBody<HTMLInputElement>('inbox-filter-brand-search')?.getAttribute('placeholder'),
      ).toBe('Search brands')
      for (const item of ITEMS) {
        expect(inBody(`inbox-filter-brand-option-${item.id}`)?.textContent).toContain(item.name)
      }
      wrapper.unmount()
    })

    it('search filters the items by name, case-insensitively', async () => {
      const wrapper = mountPopover()
      await open(wrapper)
      const input = inBody<HTMLInputElement>('inbox-filter-brand-search')!
      input.value = '  gLoB '
      input.dispatchEvent(new Event('input'))
      await flushPromises()

      expect(inBody('inbox-filter-brand-option-b2')).not.toBeNull()
      expect(inBody('inbox-filter-brand-option-b1')).toBeNull()
      expect(inBody('inbox-filter-brand-option-b4')).toBeNull()
      wrapper.unmount()
    })

    it('shows the no-match text when the search finds nothing', async () => {
      const wrapper = mountPopover()
      await open(wrapper)
      const input = inBody<HTMLInputElement>('inbox-filter-brand-search')!
      input.value = 'zzz'
      input.dispatchEvent(new Event('input'))
      await flushPromises()

      expect(inBody('inbox-filter-brand-popover')?.textContent).toContain('No brands match')
      expect(document.body.querySelectorAll('[role="checkbox"]')).toHaveLength(0)
      wrapper.unmount()
    })

    it('shows no no-match text while there are matches', async () => {
      const wrapper = mountPopover()
      await open(wrapper)
      expect(inBody('inbox-filter-brand-popover')?.textContent).not.toContain('No brands match')
      wrapper.unmount()
    })
  })

  describe('selecting', () => {
    it('clicking an unselected row emits the selection with it added', async () => {
      const wrapper = mountPopover({ modelValue: ['b1'] })
      await open(wrapper)
      inBody('inbox-filter-brand-option-b2')!.click()
      await flushPromises()
      expect(wrapper.emitted('update:modelValue')).toEqual([[['b1', 'b2']]])
      wrapper.unmount()
    })

    it('clicking a selected row emits the selection with it removed', async () => {
      const wrapper = mountPopover({ modelValue: ['b1', 'b2'] })
      await open(wrapper)
      inBody('inbox-filter-brand-option-b1')!.click()
      await flushPromises()
      expect(wrapper.emitted('update:modelValue')).toEqual([[['b2']]])
      wrapper.unmount()
    })

    it('marks selected rows aria-checked and leaves the others unchecked', async () => {
      const wrapper = mountPopover({ modelValue: ['b2'] })
      await open(wrapper)
      expect(inBody('inbox-filter-brand-option-b2')?.getAttribute('aria-checked')).toBe('true')
      expect(inBody('inbox-filter-brand-option-b1')?.getAttribute('aria-checked')).toBe('false')
      wrapper.unmount()
    })

    it('keeps the menu open after a pick, so several can be chosen', async () => {
      const wrapper = mountPopover()
      await open(wrapper)
      inBody('inbox-filter-brand-option-b1')!.click()
      await flushPromises()
      expect(inBody('inbox-filter-brand-popover')).not.toBeNull()
      wrapper.unmount()
    })
  })

  describe('unread-thread counts', () => {
    it('shows each item its count and shows nothing for a 0', async () => {
      const wrapper = mountPopover()
      await open(wrapper)
      expect(inBody('inbox-filter-brand-count-b1')?.textContent).toBe('3')
      expect(inBody('inbox-filter-brand-count-b3')?.textContent).toBe('1')
      expect(inBody('inbox-filter-brand-count-b4')?.textContent).toBe('12')
      expect(inBody('inbox-filter-brand-count-b2')).toBeNull()
      // And no stray "0" text inside that row.
      expect(inBody('inbox-filter-brand-option-b2')?.textContent?.trim()).toBe('Globex')
      wrapper.unmount()
    })
  })

  describe('footer', () => {
    it('is absent while nothing is selected', async () => {
      const wrapper = mountPopover()
      await open(wrapper)
      expect(inBody('inbox-filter-brand-clear')).toBeNull()
      wrapper.unmount()
    })

    it('shows "N selected" and a Clear action that emits an empty selection', async () => {
      const wrapper = mountPopover({ modelValue: ['b1', 'b2'] })
      await open(wrapper)
      const foot = document.body.querySelector('.inbox-pop__foot')
      expect(foot?.textContent).toContain('2 selected')
      const clear = inBody('inbox-filter-brand-clear')!
      expect(clear.textContent?.trim()).toBe('Clear')
      clear.click()
      await flushPromises()
      expect(wrapper.emitted('update:modelValue')).toEqual([[[]]])
      wrapper.unmount()
    })
  })

  describe('colour dots', () => {
    function dotStyle(id: string): string {
      return (
        inBody(`inbox-filter-brand-option-${id}`)
          ?.querySelector('.inbox-pop__dot')
          ?.getAttribute('style') ?? ''
      )
    }

    it('colours a dot with var(--agency-brand-<key>)', async () => {
      const wrapper = mountPopover()
      await open(wrapper)
      expect(dotStyle('b1')).toContain('var(--agency-brand-blue)')
      expect(dotStyle('b2')).toContain('var(--agency-brand-green)')
      wrapper.unmount()
    })

    it('falls back to slate for an unknown key and for none', async () => {
      const wrapper = mountPopover()
      await open(wrapper)
      expect(dotStyle('b3')).toContain('var(--agency-brand-slate)')
      expect(dotStyle('b4')).toContain('var(--agency-brand-slate)')
      wrapper.unmount()
    })

    it('draws no dots unless asked (campaigns)', async () => {
      const wrapper = mountPopover({ dots: false })
      await open(wrapper)
      expect(document.body.querySelector('.inbox-pop__dot')).toBeNull()
      wrapper.unmount()
    })
  })
})
