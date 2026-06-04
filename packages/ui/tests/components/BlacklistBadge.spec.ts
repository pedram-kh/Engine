/**
 * Unit tests for the shared `BlacklistBadge` (`@catalyst/ui`,
 * blacklist-in-pools chunk, D-1), under the package's theme-aware harness.
 *
 * What this pins (the extraction contract):
 *   - hard → `error` colour, soft → `warning` colour (the tonal hard/soft map
 *     the inline chips carried — the behavior-preserving migration depends on
 *     this being exact, D-2).
 *   - the pre-localized `label` renders verbatim (i18n-free contract) + is the
 *     accessible name.
 *   - `size` defaults to `small` but is overridable (so the roster's `x-small`
 *     migration is byte-for-byte).
 */

import { VChip } from 'vuetify/components'
import { describe, expect, it } from 'vitest'

import BlacklistBadge from '../../src/components/BlacklistBadge.vue'

import { mountThemed } from '../helpers/mountThemed'

describe('BlacklistBadge', () => {
  it('renders a hard blacklist as an error chip with the passed label', () => {
    const h = mountThemed(BlacklistBadge, { props: { type: 'hard', label: 'Blacklisted' } })
    try {
      const chip = h.wrapper.findComponent(VChip)
      expect(chip.props('color')).toBe('error')
      expect(chip.text()).toBe('Blacklisted')
      expect(chip.attributes('aria-label')).toBe('Blacklisted')
    } finally {
      h.unmount()
    }
  })

  it('renders a soft blacklist as a warning chip', () => {
    const h = mountThemed(BlacklistBadge, { props: { type: 'soft', label: 'Blacklist warning' } })
    try {
      const chip = h.wrapper.findComponent(VChip)
      expect(chip.props('color')).toBe('warning')
      expect(chip.text()).toBe('Blacklist warning')
    } finally {
      h.unmount()
    }
  })

  it('defaults to size "small" but honours an explicit size (behavior-preserving migration)', () => {
    const def = mountThemed(BlacklistBadge, { props: { type: 'hard', label: 'X' } })
    try {
      expect(def.wrapper.findComponent(VChip).props('size')).toBe('small')
    } finally {
      def.unmount()
    }

    const sized = mountThemed(BlacklistBadge, {
      props: { type: 'hard', label: 'X', size: 'x-small' },
    })
    try {
      expect(sized.wrapper.findComponent(VChip).props('size')).toBe('x-small')
    } finally {
      sized.unmount()
    }
  })
})
