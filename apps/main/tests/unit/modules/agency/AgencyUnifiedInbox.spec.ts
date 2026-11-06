/**
 * AH-119 — `AgencyUnifiedInbox`: the Direct + campaign list with its filters.
 *
 * Presentation only; the page feeds it normalised items and owns the filters. Pins the row
 * anatomy (tag text, brand dot, unread dot and badge, attachment preview), the unfiltered unread
 * pill, the Mine chip, the All / Unread toggle, Clear filters, the campaign popover's narrowing by
 * brand, client search, the two empty states, Load more and the active row.
 *
 * Break-revert: derive the pill from the visible rows → the independence case fails; stop
 * emitting owner `me` from Mine → the Mine case fails; list every campaign regardless of the
 * brand selection → the narrowing case fails; show Clear filters always → the clear case fails.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'
import AgencyUnifiedInbox, {
  type UnifiedInboxFilters,
  type UnifiedInboxItem,
} from '@/modules/agency/components/AgencyUnifiedInbox.vue'

const NONE: UnifiedInboxFilters = {
  brand: [],
  campaign: [],
  unread: false,
  owner: 'all',
  direct: false,
}

const DIRECT_KEY = 'relationship_thread:t1'
const CAMPAIGN_KEY = 'assignment_thread:t2'
const ATTACH_KEY = 'assignment_thread:t3'

function item(overrides: Partial<UnifiedInboxItem> & { key: string }): UnifiedInboxItem {
  return {
    title: 'Someone',
    preview: 'hello',
    previewIsAttachment: false,
    lastMessageAt: '2020-03-14T10:00:00+00:00',
    unreadCount: 0,
    avatarText: 'Someone',
    avatarUrl: null,
    to: '/messages/x',
    direct: true,
    brandName: null,
    brandColor: null,
    campaignName: null,
    ...overrides,
  }
}

const ITEMS: UnifiedInboxItem[] = [
  item({
    key: DIRECT_KEY,
    title: 'Maya Thompson',
    preview: 'Hello from Maya',
    unreadCount: 2,
    to: '/messages/01MAYA',
  }),
  item({
    key: CAMPAIGN_KEY,
    title: 'Daniel Okafor',
    preview: 'Draft is up',
    direct: false,
    brandName: 'Acme',
    brandColor: 'blue',
    campaignName: 'Summer',
    to: '/messages/c/k1/a/a1',
  }),
  item({
    key: ATTACH_KEY,
    title: 'Priya Rao',
    preview: null,
    previewIsAttachment: true,
    direct: false,
    brandName: 'Globex',
    brandColor: 'green',
    campaignName: 'Winter',
    to: '/messages/c/k2/a/a2',
  }),
]

const FACETS = {
  brands: [
    { id: 'b1', name: 'Acme', color: 'blue' as const, unread_threads: 2 },
    { id: 'b2', name: 'Globex', color: 'green' as const, unread_threads: 0 },
  ],
  campaigns: [
    { id: 'k1', name: 'Summer', brand_id: 'b1', unread_threads: 2 },
    { id: 'k2', name: 'Winter', brand_id: 'b2', unread_threads: 0 },
    { id: 'k3', name: 'Evergreen', brand_id: null, unread_threads: 1 },
  ],
}

async function mountInbox(props: Record<string, unknown> = {}) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/:pathMatch(.*)*', component: { template: '<div />' } }],
  })
  await router.push('/messages')
  await router.isReady()
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  const wrapper = mount(AgencyUnifiedInbox, {
    props: {
      items: ITEMS,
      facets: FACETS,
      unreadThreadsTotal: 0,
      filters: NONE,
      loading: false,
      loadError: false,
      loadingMore: false,
      hasMore: false,
      ...props,
    },
    global: { plugins: [router, i18n, vuetify] },
    attachTo: document.body,
  })
  await flushPromises()
  return wrapper
}

type W = Awaited<ReturnType<typeof mountInbox>>

const row = (w: W, key: string) => w.get(`[data-test="inbox-row-${key}"]`)

function inBody(testId: string): HTMLElement | null {
  return document.body.querySelector<HTMLElement>(`[data-test="${testId}"]`)
}

async function search(w: W, text: string): Promise<void> {
  await w.get('[data-test="agency-inbox-search"] input').setValue(text)
}

describe('AgencyUnifiedInbox', () => {
  afterEach(() => {
    document.body.innerHTML = ''
  })

  describe('rows', () => {
    it('renders a Direct row and an assignment row, linked by their own `to`', async () => {
      const w = await mountInbox()
      expect(row(w, DIRECT_KEY).text()).toContain('Maya Thompson')
      expect(row(w, DIRECT_KEY).attributes('href')).toBe('/messages/01MAYA')
      expect(row(w, CAMPAIGN_KEY).text()).toContain('Daniel Okafor')
      expect(row(w, CAMPAIGN_KEY).attributes('href')).toBe('/messages/c/k1/a/a1')
      w.unmount()
    })

    it('tags a Direct row "Direct" and a campaign row "Brand · Campaign"', async () => {
      const w = await mountInbox()
      expect(w.get(`[data-test="inbox-tag-${DIRECT_KEY}"]`).text()).toBe('Direct')
      expect(w.get(`[data-test="inbox-tag-${CAMPAIGN_KEY}"]`).text()).toBe('Acme · Summer')
      w.unmount()
    })

    it('tags a campaign row with only a campaign name by that name, and with neither by nothing', async () => {
      const w = await mountInbox({
        items: [
          item({ key: 'assignment_thread:a', direct: false, campaignName: 'Solo' }),
          item({ key: 'assignment_thread:b', direct: false }),
        ],
      })
      expect(w.get('[data-test="inbox-tag-assignment_thread:a"]').text()).toBe('Solo')
      expect(w.find('[data-test="inbox-tag-assignment_thread:b"]').exists()).toBe(false)
      w.unmount()
    })

    it('draws the brand colour dot on a campaign tag only, from var(--agency-brand-<key>)', async () => {
      const w = await mountInbox()
      const dot = w.get(`[data-test="inbox-tag-${CAMPAIGN_KEY}"] .agency-inbox-row__tag-dot`)
      expect(dot.attributes('style')).toContain('var(--agency-brand-blue)')
      expect(
        w
          .get(`[data-test="inbox-tag-${ATTACH_KEY}"] .agency-inbox-row__tag-dot`)
          .attributes('style'),
      ).toContain('var(--agency-brand-green)')
      expect(
        w.find(`[data-test="inbox-tag-${DIRECT_KEY}"] .agency-inbox-row__tag-dot`).exists(),
      ).toBe(false)
      w.unmount()
    })

    it('a brand with no colour gets a derived palette dot, the same one every time (A4: nothing is written)', async () => {
      const mk = (id: string) =>
        mountInbox({
          items: [
            item({
              key: 'assignment_thread:n',
              direct: false,
              brandName: 'Plain',
              brandId: id,
              brandColor: null,
              campaignName: 'C',
            }),
          ],
        })
      const dot = (w: Awaited<ReturnType<typeof mk>>) =>
        w
          .get('[data-test="inbox-tag-assignment_thread:n"] .agency-inbox-row__tag-dot')
          .attributes('style') ?? ''
      const a1 = await mk('01HBRANDDERIVED000000000A')
      const a2 = await mk('01HBRANDDERIVED000000000A')
      expect(dot(a1)).toMatch(/var\(--agency-brand-(violet|green|amber|blue|pink|teal|red|slate)\)/)
      expect(dot(a1)).toBe(dot(a2))
      a1.unmount()
      a2.unmount()
    })

    it('marks an unread row (dot class) and shows its message count; a read row has neither', async () => {
      const w = await mountInbox()
      expect(row(w, DIRECT_KEY).classes()).toContain('agency-inbox-row--unread')
      expect(w.get(`[data-test="inbox-unread-${DIRECT_KEY}"]`).text()).toBe('2')
      expect(row(w, CAMPAIGN_KEY).classes()).not.toContain('agency-inbox-row--unread')
      expect(w.find(`[data-test="inbox-unread-${CAMPAIGN_KEY}"]`).exists()).toBe(false)
      w.unmount()
    })

    it('shows a paperclip and "Attachment" for an attachment-only preview, and no paperclip for text', async () => {
      const w = await mountInbox()
      const attach = row(w, ATTACH_KEY)
      expect(attach.find('.mdi-paperclip').exists()).toBe(true)
      expect(attach.get('.agency-inbox-row__preview').text()).toBe('Attachment')
      expect(row(w, DIRECT_KEY).find('.mdi-paperclip').exists()).toBe(false)
      expect(row(w, DIRECT_KEY).get('.agency-inbox-row__preview').text()).toBe('Hello from Maya')
      w.unmount()
    })

    it('keeps the preview text next to the paperclip when an attachment message has a caption', async () => {
      const w = await mountInbox({
        items: [item({ key: 'k', preview: 'See attached', previewIsAttachment: true })],
      })
      expect(w.find('.mdi-paperclip').exists()).toBe(true)
      expect(w.get('.agency-inbox-row__preview').text()).toBe('See attached')
      w.unmount()
    })

    it('says "No messages yet." for a row with no preview and no attachment', async () => {
      const w = await mountInbox({ items: [item({ key: 'k', preview: null })] })
      expect(w.get('.agency-inbox-row__preview').text()).toBe('No messages yet.')
      w.unmount()
    })

    it('draws no subtitle element', async () => {
      const w = await mountInbox()
      expect(w.find('.agency-inbox__subtitle').exists()).toBe(false)
      expect(w.find('[data-test="agency-inbox-subtitle"]').exists()).toBe(false)
      w.unmount()
    })

    it('highlights the active row by activeKey, and only that row', async () => {
      const w = await mountInbox({ activeKey: CAMPAIGN_KEY })
      expect(row(w, CAMPAIGN_KEY).classes()).toContain('agency-inbox-row--active')
      expect(row(w, DIRECT_KEY).classes()).not.toContain('agency-inbox-row--active')
      expect(row(w, ATTACH_KEY).classes()).not.toContain('agency-inbox-row--active')
      w.unmount()
    })

    it('highlights nothing for a null activeKey', async () => {
      const w = await mountInbox({ activeKey: null })
      expect(w.find('.agency-inbox-row--active').exists()).toBe(false)
      w.unmount()
    })
  })

  describe('unread pill', () => {
    it('shows the unfiltered total, whatever the rows say', async () => {
      // Three rows, two unread messages in one of them; the pill is threads across ALL filters.
      const w = await mountInbox({ unreadThreadsTotal: 7 })
      expect(w.get('[data-test="agency-inbox-unread-pill"]').text()).toBe('7 unread')
      w.unmount()
    })

    it('does not follow the filtered rows', async () => {
      const w = await mountInbox({ unreadThreadsTotal: 7, items: [ITEMS[1]!] })
      expect(w.get('[data-test="agency-inbox-unread-pill"]').text()).toBe('7 unread')
      w.unmount()
    })

    it('does not follow the client search either', async () => {
      const w = await mountInbox({ unreadThreadsTotal: 4 })
      await search(w, 'zzz-no-match')
      expect(w.get('[data-test="agency-inbox-unread-pill"]').text()).toBe('4 unread')
      w.unmount()
    })

    it('is hidden at 0 even when a row carries unread messages', async () => {
      const w = await mountInbox({ unreadThreadsTotal: 0 })
      expect(w.find('[data-test="agency-inbox-unread-pill"]').exists()).toBe(false)
      w.unmount()
    })
  })

  describe('Direct chip', () => {
    it('is not pressed when off, and pressing it asks for direct, keeping Mine and Unread', async () => {
      const filters = { ...NONE, owner: 'me' as const, unread: true }
      const w = await mountInbox({ filters })
      const chip = w.get('[data-test="agency-inbox-direct"]')
      expect(chip.attributes('aria-pressed')).toBe('false')
      await chip.trigger('click')
      expect(w.emitted('update:filters')).toEqual([[{ ...filters, direct: true }]])
      w.unmount()
    })

    it('is pressed when on, and pressing it again turns it off', async () => {
      const w = await mountInbox({ filters: { ...NONE, direct: true } })
      const chip = w.get('[data-test="agency-inbox-direct"]')
      expect(chip.attributes('aria-pressed')).toBe('true')
      expect(chip.classes()).toContain('agency-inbox__mine--on')
      await chip.trigger('click')
      expect(w.emitted('update:filters')).toEqual([[{ ...NONE, direct: false }]])
      w.unmount()
    })

    it('drops a brand and campaign selection when it goes on (Direct rows have neither)', async () => {
      const w = await mountInbox({
        filters: { ...NONE, brand: ['b1'], campaign: ['k1'], unread: true },
      })
      await w.get('[data-test="agency-inbox-direct"]').trigger('click')
      expect(w.emitted('update:filters')).toEqual([[{ ...NONE, unread: true, direct: true }]])
      w.unmount()
    })
  })

  describe('Mine chip', () => {
    it('is not pressed for "all", and pressing it asks for owner "me", keeping the other filters', async () => {
      const filters = { ...NONE, brand: ['b1'], unread: true }
      const w = await mountInbox({ filters })
      const mine = w.get('[data-test="agency-inbox-mine"]')
      expect(mine.attributes('aria-pressed')).toBe('false')
      await mine.trigger('click')
      expect(w.emitted('update:filters')).toEqual([[{ ...filters, owner: 'me' }]])
      w.unmount()
    })

    it('is pressed for "me", and pressing it again asks for owner "all"', async () => {
      const w = await mountInbox({ filters: { ...NONE, owner: 'me' } })
      const mine = w.get('[data-test="agency-inbox-mine"]')
      expect(mine.attributes('aria-pressed')).toBe('true')
      expect(mine.classes()).toContain('agency-inbox__mine--on')
      await mine.trigger('click')
      expect(w.emitted('update:filters')).toEqual([[{ ...NONE, owner: 'all' }]])
      w.unmount()
    })
  })

  describe('All / Unread toggle', () => {
    it('shows All selected by default and Unread after the filter is on', async () => {
      const w = await mountInbox()
      expect(w.get('[data-test="agency-inbox-toggle-all"]').attributes('aria-checked')).toBe('true')
      expect(w.get('[data-test="agency-inbox-toggle-unread"]').attributes('aria-checked')).toBe(
        'false',
      )
      await w.setProps({ filters: { ...NONE, unread: true } })
      expect(w.get('[data-test="agency-inbox-toggle-all"]').attributes('aria-checked')).toBe(
        'false',
      )
      expect(w.get('[data-test="agency-inbox-toggle-unread"]').attributes('aria-checked')).toBe(
        'true',
      )
      w.unmount()
    })

    it('Unread emits unread true and All emits unread false, keeping the rest', async () => {
      const w = await mountInbox({ filters: { ...NONE, owner: 'me' } })
      await w.get('[data-test="agency-inbox-toggle-unread"]').trigger('click')
      await w.setProps({ filters: { ...NONE, owner: 'me', unread: true } })
      await w.get('[data-test="agency-inbox-toggle-all"]').trigger('click')
      expect(w.emitted('update:filters')).toEqual([
        [{ ...NONE, owner: 'me', unread: true }],
        [{ ...NONE, owner: 'me', unread: false }],
      ])
      w.unmount()
    })
  })

  describe('Clear filters', () => {
    it('is absent while no filter is on', async () => {
      const w = await mountInbox()
      expect(w.find('[data-test="agency-inbox-clear-filters"]').exists()).toBe(false)
      w.unmount()
    })

    it.each([
      ['brand', { brand: ['b1'] }],
      ['campaign', { campaign: ['k1'] }],
      ['unread', { unread: true }],
      ['owner', { owner: 'me' as const }],
      ['direct', { direct: true }],
    ])('appears for the %s filter and emits the empty filters', async (_name, patch) => {
      const w = await mountInbox({ filters: { ...NONE, ...patch } })
      const clear = w.get('[data-test="agency-inbox-clear-filters"]')
      expect(clear.text()).toBe('Clear filters')
      await clear.trigger('click')
      expect(w.emitted('update:filters')).toEqual([
        [{ brand: [], campaign: [], unread: false, owner: 'all', direct: false }],
      ])
      w.unmount()
    })
  })

  describe('brand and campaign popovers', () => {
    it('the brand chip lists the facet brands, with counts', async () => {
      const w = await mountInbox()
      await w.get('[data-test="inbox-filter-brand"]').trigger('click')
      await flushPromises()
      expect(inBody('inbox-filter-brand-option-b1')?.textContent).toContain('Acme')
      expect(inBody('inbox-filter-brand-option-b2')?.textContent).toContain('Globex')
      expect(inBody('inbox-filter-brand-count-b1')?.textContent).toBe('2')
      expect(inBody('inbox-filter-brand-count-b2')).toBeNull()
      w.unmount()
    })

    it('picking a brand emits the filters with that brand and the rest kept', async () => {
      const w = await mountInbox({ filters: { ...NONE, unread: true } })
      await w.get('[data-test="inbox-filter-brand"]').trigger('click')
      await flushPromises()
      inBody('inbox-filter-brand-option-b1')!.click()
      await flushPromises()
      expect(w.emitted('update:filters')).toEqual([[{ ...NONE, unread: true, brand: ['b1'] }]])
      w.unmount()
    })

    it('picking a campaign emits the filters with that campaign', async () => {
      const w = await mountInbox()
      await w.get('[data-test="inbox-filter-campaign"]').trigger('click')
      await flushPromises()
      inBody('inbox-filter-campaign-option-k3')!.click()
      await flushPromises()
      expect(w.emitted('update:filters')).toEqual([[{ ...NONE, campaign: ['k3'] }]])
      w.unmount()
    })

    async function campaignOptionIds(w: W): Promise<string[]> {
      await w.get('[data-test="inbox-filter-campaign"]').trigger('click')
      await flushPromises()
      return [
        ...document.body.querySelectorAll<HTMLElement>(
          '[data-test^="inbox-filter-campaign-option-"]',
        ),
      ].map((el) =>
        (el.getAttribute('data-test') as string).replace('inbox-filter-campaign-option-', ''),
      )
    }

    it('with no brand selected, lists every campaign', async () => {
      const w = await mountInbox()
      expect(await campaignOptionIds(w)).toEqual(['k1', 'k2', 'k3'])
      w.unmount()
    })

    it('with brands selected, lists only those brands\u2019 campaigns plus brand-less ones', async () => {
      const w = await mountInbox({ filters: { ...NONE, brand: ['b1'] } })
      expect(await campaignOptionIds(w)).toEqual(['k1', 'k3'])
      w.unmount()
    })

    it('with two brands selected, lists both brands\u2019 campaigns', async () => {
      const w = await mountInbox({ filters: { ...NONE, brand: ['b1', 'b2'] } })
      expect(await campaignOptionIds(w)).toEqual(['k1', 'k2', 'k3'])
      w.unmount()
    })

    it('draws no colour dots in the campaign popover, but does in the brand popover', async () => {
      const w = await mountInbox()
      await w.get('[data-test="inbox-filter-brand"]').trigger('click')
      await flushPromises()
      expect(
        inBody('inbox-filter-brand-option-b1')
          ?.querySelector('.inbox-pop__dot')
          ?.getAttribute('style'),
      ).toContain('var(--agency-brand-blue)')
      await w.get('[data-test="inbox-filter-campaign"]').trigger('click')
      await flushPromises()
      expect(inBody('inbox-filter-campaign-option-k1')?.querySelector('.inbox-pop__dot')).toBeNull()
      w.unmount()
    })
  })

  describe('client search', () => {
    const visible = (w: W): string[] =>
      w.findAll('[data-test^="inbox-row-"]').map((r) => r.attributes('data-test') as string)

    it('shows every row with an empty or blank query', async () => {
      const w = await mountInbox()
      await search(w, '   ')
      expect(visible(w)).toHaveLength(3)
      w.unmount()
    })

    it('matches the title, case-insensitively', async () => {
      const w = await mountInbox()
      await search(w, 'maya')
      expect(visible(w)).toEqual([`inbox-row-${DIRECT_KEY}`])
      w.unmount()
    })

    it('matches the preview', async () => {
      const w = await mountInbox()
      await search(w, 'draft is')
      expect(visible(w)).toEqual([`inbox-row-${CAMPAIGN_KEY}`])
      w.unmount()
    })

    it('matches the brand name', async () => {
      const w = await mountInbox()
      await search(w, 'globex')
      expect(visible(w)).toEqual([`inbox-row-${ATTACH_KEY}`])
      w.unmount()
    })

    it('matches the campaign name', async () => {
      const w = await mountInbox()
      await search(w, 'summer')
      expect(visible(w)).toEqual([`inbox-row-${CAMPAIGN_KEY}`])
      w.unmount()
    })

    it('handles rows with a null preview, brand and campaign without throwing', async () => {
      const w = await mountInbox()
      await search(w, 'hello')
      expect(visible(w)).toEqual([`inbox-row-${DIRECT_KEY}`])
      w.unmount()
    })

    it('says "No conversations match." when nothing matches, and the list is empty', async () => {
      const w = await mountInbox()
      await search(w, 'zzz-no-match')
      expect(visible(w)).toHaveLength(0)
      expect(w.get('[data-test="agency-inbox-no-match"]').text()).toBe('No conversations match.')
      w.unmount()
    })

    it('does not say "no match" when something matches', async () => {
      const w = await mountInbox()
      await search(w, 'maya')
      expect(w.find('[data-test="agency-inbox-no-match"]').exists()).toBe(false)
      w.unmount()
    })
  })

  describe('empty states', () => {
    it('with no items and no filter: the empty text and a start button that emits start', async () => {
      const w = await mountInbox({ items: [] })
      const empty = w.get('[data-test="relationship-inbox-empty"]')
      expect(empty.text()).toContain('No conversations yet.')
      await w.get('[data-test="relationship-inbox-start"]').trigger('click')
      expect(w.emitted('start')).toHaveLength(1)
      expect(w.find('[data-test="agency-inbox-filtered-empty"]').exists()).toBe(false)
      w.unmount()
    })

    it.each([
      ['brand', { brand: ['b1'] }],
      ['campaign', { campaign: ['k1'] }],
      ['unread', { unread: true }],
      ['owner', { owner: 'me' as const }],
    ])(
      'with no items and the %s filter on: the filtered-empty text, no start button',
      async (_n, patch) => {
        const w = await mountInbox({ items: [], filters: { ...NONE, ...patch } })
        expect(w.get('[data-test="agency-inbox-filtered-empty"]').text()).toBe(
          'No conversations match these filters.',
        )
        expect(w.find('[data-test="relationship-inbox-empty"]').exists()).toBe(false)
        expect(w.find('[data-test="relationship-inbox-start"]').exists()).toBe(false)
        w.unmount()
      },
    )

    it('shows the load error instead of the empty state', async () => {
      const w = await mountInbox({ items: [], loadError: true })
      expect(w.find('.v-alert').exists()).toBe(true)
      expect(w.find('[data-test="relationship-inbox-empty"]').exists()).toBe(false)
      w.unmount()
    })

    it('shows a skeleton, not the empty state, while loading', async () => {
      const w = await mountInbox({ items: [], loading: true })
      expect(w.find('.v-skeleton-loader').exists()).toBe(true)
      expect(w.find('[data-test="relationship-inbox-empty"]').exists()).toBe(false)
      w.unmount()
    })

    it('the header new-conversation button emits start', async () => {
      const w = await mountInbox()
      await w.get('[data-test="agency-inbox-new"]').trigger('click')
      expect(w.emitted('start')).toHaveLength(1)
      w.unmount()
    })
  })

  describe('Load more', () => {
    it('is hidden without more pages', async () => {
      const w = await mountInbox({ hasMore: false })
      expect(w.find('[data-test="agency-inbox-load-more"]').exists()).toBe(false)
      w.unmount()
    })

    it('shows when there are more pages, and emits loadMore on click', async () => {
      const w = await mountInbox({ hasMore: true })
      const more = w.get('[data-test="agency-inbox-load-more"]')
      expect(more.text()).toBe('Load more')
      await more.trigger('click')
      expect(w.emitted('loadMore')).toHaveLength(1)
      w.unmount()
    })

    it('is hidden while the first page loads or after a load error', async () => {
      const loading = await mountInbox({ hasMore: true, loading: true })
      expect(loading.find('[data-test="agency-inbox-load-more"]').exists()).toBe(false)
      loading.unmount()
      const failed = await mountInbox({ hasMore: true, loadError: true })
      expect(failed.find('[data-test="agency-inbox-load-more"]').exists()).toBe(false)
      failed.unmount()
    })
  })
})
