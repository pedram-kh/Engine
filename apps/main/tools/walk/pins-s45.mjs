/**
 * S4.5 pins: the two notification pages, which are shared with the creator app and so are
 * restyled by the agency scope alone (no edit to either page). They check that the scope reaches
 * them: the page card is the agency surface with the default border, and the unread rows carry
 * the accent wash.
 */

const STEP = 'S4.5'

const pin = (page, name, sel, expect, more = {}) => ({
  step: STEP,
  name,
  sel,
  expect,
  page,
  ...more,
})

export const S45_PINS = [
  pin(
    'real:notifications-1600',
    'notifications: the page card is the agency surface, 1px border, radius 10',
    '[data-test="notifications-page"]',
    (c) => ({
      backgroundColor: c.surface,
      borderTopWidth: '1px',
      borderTopColor: c.border,
      borderTopLeftRadius: '10px',
    }),
  ),
  pin(
    'real:notifications-1600',
    'notifications: the feed holds four rows, two unread',
    '[data-test="notifications-page"]',
    () => ({ rows: 4, unread: 2 }),
    {
      fn: `return { rows: document.querySelectorAll('[data-test^="notification-row"]').length, unread: document.querySelectorAll('[data-test*="notification-row--unread"]').length }`,
    },
  ),
  pin(
    'real:notification-prefs-1600',
    'notification settings: the page card is the agency surface, 1px border, radius 10',
    '[data-test="notification-preferences-page"]',
    (c) => ({
      backgroundColor: c.surface,
      borderTopWidth: '1px',
      borderTopColor: c.border,
      borderTopLeftRadius: '10px',
    }),
  ),
  pin(
    'real:notification-prefs-1600',
    'notification settings: the title is the scoped text-h5 (28/36/500)',
    '[data-test="prefs-heading"]',
    () => ({ fontSize: '28px', lineHeight: '36px', fontWeight: '500' }),
  ),
]
