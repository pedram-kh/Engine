/**
 * Unit tests for the optional kind pill on `PortfolioGallery` (AH-100d).
 *
 * The creator app passes `kindLabels` to draw Figma frame 04's "Image / Video /
 * Link" pill over each thumbnail; every other consumer (both SPAs' web surfaces)
 * passes nothing and must get exactly the markup it always had.
 *
 * Consumer surfaces — apps/main: ConnectionsPortfolioSection (creator wizard +
 * Edit profile; passes labels, native only), DiscoverProfilePage,
 * roster CreatorDetailPage, DraftReviewPanel; apps/admin: creators
 * CreatorDetailPage. Only the first passes `kindLabels`.
 */

import { describe, expect, it } from 'vitest'

import PortfolioGallery from '../../src/components/PortfolioGallery.vue'

import { mountThemed } from '../helpers/mountThemed'

const items = [
  {
    id: 'a',
    kind: 'image' as const,
    title: 'Summer lookbook',
    description: null,
    thumbnailUrl: 'https://cdn.example.test/a.jpg',
    externalUrl: null,
    altText: 'Summer lookbook',
  },
  {
    id: 'b',
    kind: 'video' as const,
    title: 'Product unboxing',
    description: null,
    thumbnailUrl: null,
    externalUrl: null,
    altText: 'Product unboxing',
  },
  {
    id: 'c',
    kind: 'link' as const,
    title: 'Reel',
    description: null,
    thumbnailUrl: null,
    externalUrl: 'https://example.test/reel',
    altText: 'Reel',
  },
]

describe('PortfolioGallery — kind pill', () => {
  it('draws a pill per tile with the supplied label when kindLabels is given', () => {
    const { wrapper } = mountThemed(PortfolioGallery, {
      props: { items, kindLabels: { image: 'Image', video: 'Video', link: 'Link' } },
    })

    expect(wrapper.find('[data-testid="portfolio-gallery-kind-a"]').text()).toBe('Image')
    expect(wrapper.find('[data-testid="portfolio-gallery-kind-b"]').text()).toBe('Video')
    expect(wrapper.find('[data-testid="portfolio-gallery-kind-c"]').text()).toBe('Link')
    expect(wrapper.findAll('.portfolio-gallery__kind')).toHaveLength(3)
  })

  it('draws no pill at all when kindLabels is omitted (the web markup is unchanged)', () => {
    const { wrapper } = mountThemed(PortfolioGallery, { props: { items } })

    expect(wrapper.find('.portfolio-gallery__kind').exists()).toBe(false)
    expect(wrapper.html()).not.toContain('portfolio-gallery-kind-')
  })

  it('draws a pill only for the kinds that have a label', () => {
    const { wrapper } = mountThemed(PortfolioGallery, {
      props: { items, kindLabels: { video: 'Video' } },
    })

    expect(wrapper.findAll('.portfolio-gallery__kind')).toHaveLength(1)
    expect(wrapper.find('[data-testid="portfolio-gallery-kind-b"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="portfolio-gallery-kind-a"]').exists()).toBe(false)
  })

  it('leaves the remove affordance and titles alone', () => {
    const { wrapper } = mountThemed(PortfolioGallery, {
      props: { items, editable: true, kindLabels: { image: 'Image' } },
    })

    expect(wrapper.find('[data-testid="portfolio-gallery-remove-a"]').exists()).toBe(true)
    expect(wrapper.findAll('.portfolio-gallery__title').map((n) => n.text())).toEqual([
      'Summer lookbook',
      'Product unboxing',
      'Reel',
    ])
  })
})
