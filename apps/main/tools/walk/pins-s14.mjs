/**
 * S1.4 pins: actions and inputs (buttons, fields, checkbox, switch, file input),
 * read off the probe page under both agency themes. `expect` is a function of the
 * theme's colours (see pins-colours.mjs).
 *
 * Pin shapes (see pins.mjs): `sel` reads an element already on the probe page, `html`
 * injects a snippet, `fn` evaluates a function body in the page and returns an object.
 * `act` / `undo` are lists of [focus|blur|click, selector] run before and after reading.
 */

const STEP = 'S1.4'
const BTN = (n) => `[data-test="probe-buttons"] .v-btn:nth-child(${n})`
const FIELDS = '[data-test="probe-fields"]'
const TOGGLES = '[data-test="probe-toggles"]'

const on = (name, sel, expect, extra = {}) => ({ step: STEP, name, sel, expect, ...extra })

/** Pins on the second probe page (`?probe=extra`). */
const extra = (name, sel, expect, more = {}) => on(name, sel, expect, { page: 'extra', ...more })
const SW = (id) => `[data-test="${id}"]`

export const S14_PINS = [
  // ── Buttons ──────────────────────────────────────────────────────────────
  on('button Primary', BTN(1), (c) => ({
    height: '40px',
    borderRadius: '8px',
    fontWeight: '600',
    backgroundColor: c.primary,
    color: c.onPrimary,
  })),
  on('button Secondary', BTN(2), (c) => ({
    height: '40px',
    borderRadius: '8px',
    fontWeight: '500',
    backgroundColor: c.surface,
    borderTopColor: c.outline,
    borderTopWidth: '1px',
    color: c.onSurface,
  })),
  on('button Soft', BTN(3), (c) => ({
    height: '40px',
    backgroundColor: c.primaryContainer,
    color: c.primaryText,
  })),
  on('button Ghost', BTN(4), () => ({
    height: '40px',
    borderRadius: '8px',
    fontWeight: '500',
    backgroundColor: 'rgba(0, 0, 0, 0)',
  })),
  on('button Danger', BTN(5), (c) => ({
    height: '40px',
    backgroundColor: 'rgba(240, 80, 110, 0.16)',
    borderTopColor: c.error,
    color: c.errorText,
  })),
  on('button Sm', BTN(6), () => ({ height: '32px', borderRadius: '8px' })),
  on('button disabled', BTN(7), (c) => ({
    height: '40px',
    backgroundColor: c.raised,
    color: c.tertiary,
  })),
  on('icon button keeps its size and circle', BTN(8), () => ({
    width: '48px',
    height: '48px',
    borderRadius: '50%',
  })),

  // ── Fields ───────────────────────────────────────────────────────────────
  on('text field box', `${FIELDS} .v-text-field .v-field`, (c) => ({
    borderRadius: '8px',
    backgroundColor: c.canvas,
    fontSize: '14px',
  })),
  on('text field is 40 high (compact control)', `${FIELDS} .v-text-field .v-field__input`, () => ({
    height: '40px',
  })),
  on('text field border at rest', `${FIELDS} .v-text-field .v-field__outline__start`, (c) => ({
    borderTopColor: c.outline,
    borderTopWidth: '1px',
    opacity: '1',
  })),
  on('text field input text', `${FIELDS} .v-text-field input`, () => ({ fontSize: '14px' })),
  on('field label at rest', `${FIELDS} .v-text-field .v-field-label`, (c) => ({
    fontSize: '14px',
    color: c.onSurfaceVariant,
    opacity: '1',
  })),
  on('select box', '[data-test="probe-select"] .v-field', (c) => ({
    borderRadius: '8px',
    backgroundColor: c.canvas,
  })),
  on('textarea drawn as the same box', `${FIELDS} .v-textarea .v-field`, (c) => ({
    borderRadius: '8px',
    backgroundColor: c.canvas,
    borderTopColor: c.outline,
    borderTopWidth: '1px',
  })),
  on('file input drawn as the same box', `${FIELDS} .v-file-input .v-field`, (c) => ({
    borderRadius: '8px',
    backgroundColor: c.canvas,
    borderTopColor: c.outline,
    borderTopWidth: '1px',
  })),
  on(
    'text field focused: accent border, 2px',
    `${FIELDS} .v-text-field .v-field__outline__start`,
    (c) => ({ borderTopColor: c.primary, borderTopWidth: '2px' }),
    {
      act: [['focus', `${FIELDS} .v-text-field input`]],
      undo: [['blur', `${FIELDS} .v-text-field input`]],
    },
  ),
  on(
    'text field focused: accent label',
    `${FIELDS} .v-text-field .v-field-label`,
    (c) => ({ color: c.primaryText }),
    {
      act: [['focus', `${FIELDS} .v-text-field input`]],
      undo: [['blur', `${FIELDS} .v-text-field input`]],
    },
  ),
  on(
    'textarea focused: accent border',
    `${FIELDS} .v-textarea .v-field`,
    (c) => ({ borderTopColor: c.primary }),
    {
      act: [['focus', `${FIELDS} .v-textarea textarea`]],
      undo: [['blur', `${FIELDS} .v-textarea textarea`]],
    },
  ),
  {
    step: STEP,
    name: 'hint and error lines are Caption in tertiary text',
    html: '<div class="v-messages">Hint</div>',
    expect: (c) => ({ fontSize: '12px', lineHeight: '16px', opacity: '1', color: c.tertiary }),
  },

  // ── Checkbox and switch ──────────────────────────────────────────────────
  on(
    'checkbox checked is accent; 24px glyph (18 visible)',
    `${TOGGLES} .v-checkbox .v-icon`,
    (c) => ({
      color: c.primary,
      fontSize: '24px',
    }),
  ),
  on('checkbox label is Body', `${TOGGLES} .v-checkbox .v-label`, (c) => ({
    fontSize: '14px',
    opacity: '1',
    color: c.onSurface,
  })),
  on('switch on: track 32 x 18, accent', `${TOGGLES} .v-switch .v-switch__track`, (c) => ({
    width: '32px',
    height: '18px',
    backgroundColor: c.primary,
    opacity: '1',
  })),
  on('switch thumb 14, white', `${TOGGLES} .v-switch .v-switch__thumb`, (c) => ({
    width: '14px',
    height: '14px',
    backgroundColor: c.white,
  })),
  {
    step: STEP,
    name: 'switch on: thumb inset 2px from the right end',
    fn: `const t = document.querySelector('${TOGGLES} .v-switch .v-switch__track').getBoundingClientRect(); const h = document.querySelector('${TOGGLES} .v-switch .v-switch__thumb').getBoundingClientRect(); return { left: String(Math.round(h.left - t.left)), right: String(Math.round(t.right - h.right)) }`,
    expect: () => ({ left: '16', right: '2' }),
  },
  {
    step: STEP,
    name: 'switch off: thumb inset 2px from the left end, neutral track',
    fn: `const t = document.querySelector('${TOGGLES} .v-switch .v-switch__track'); const r = t.getBoundingClientRect(); const h = document.querySelector('${TOGGLES} .v-switch .v-switch__thumb').getBoundingClientRect(); return { left: String(Math.round(h.left - r.left)), right: String(Math.round(r.right - h.right)), bg: getComputedStyle(t).backgroundColor }`,
    expect: (c) => ({ left: '2', right: '16', bg: c.outline }),
    act: [['click', `${TOGGLES} .v-switch input`]],
    undo: [['click', `${TOGGLES} .v-switch input`]],
  },

  // ── Inset switches (the shape the agency pages use) ──────────────────────
  extra(
    'inset switch on: track 32 x 18, accent',
    `${SW('extra-switch-on')} .v-switch__track`,
    (c) => ({
      width: '32px',
      height: '18px',
      backgroundColor: c.primary,
      opacity: '1',
    }),
  ),
  extra(
    'inset switch thumb is 14 and white, not scaled',
    `${SW('extra-switch-on')} .v-switch__thumb`,
    (c) => ({
      width: '14px',
      height: '14px',
      backgroundColor: c.white,
      transform: 'none',
    }),
  ),
  {
    step: STEP,
    page: 'extra',
    name: 'inset switch: thumb inset 2px at both ends',
    fn: `const geo = (id) => { const t = document.querySelector('[data-test="' + id + '"] .v-switch__track').getBoundingClientRect(); const h = document.querySelector('[data-test="' + id + '"] .v-switch__thumb').getBoundingClientRect(); return [Math.round(h.left - t.left), Math.round(t.right - h.right)] }; const on = geo('extra-switch-on'); const off = geo('extra-switch-off'); return { on: on.join('/'), off: off.join('/') }`,
    expect: () => ({ on: '16/2', off: '2/16' }),
  },
  extra('inset switch off: neutral track', `${SW('extra-switch-off')} .v-switch__track`, (c) => ({
    backgroundColor: c.outline,
    opacity: '1',
  })),

  // ── Variants the default probe page does not carry ───────────────────────
  extra(
    'outlined primary: label is primary-text',
    `${'[data-test="extra-buttons"]'} .v-btn:nth-child(1)`,
    (c) => ({
      color: c.primaryText,
      height: '40px',
    }),
  ),
  extra(
    'text primary: label is primary-text',
    '[data-test="extra-buttons"] .v-btn:nth-child(2)',
    (c) => ({
      color: c.primaryText,
    }),
  ),
  extra('tonal primary is Soft', '[data-test="extra-buttons"] .v-btn:nth-child(3)', (c) => ({
    backgroundColor: c.primaryContainer,
    color: c.primaryText,
  })),
  extra(
    'text error: label is error-text',
    '[data-test="extra-buttons"] .v-btn:nth-child(4)',
    (c) => ({
      color: c.errorText,
    }),
  ),
  extra(
    'small outlined (text-none) is Secondary, 32 high',
    '[data-test="extra-buttons"] .v-btn:nth-child(5)',
    (c) => ({
      height: '32px',
      backgroundColor: c.surface,
      borderTopColor: c.outline,
    }),
  ),

  // ── Fields inside a card ─────────────────────────────────────────────────
  extra('hint line', '[data-test="extra-hint"] .v-messages', (c) => ({
    fontSize: '12px',
    lineHeight: '16px',
    opacity: '1',
    color: c.tertiary,
  })),
  extra('error line is error-text', '[data-test="extra-error"] .v-messages', (c) => ({
    color: c.errorText,
    opacity: '1',
  })),
  extra(
    'error field border is error',
    '[data-test="extra-error"] .v-field__outline__start',
    (c) => ({
      borderTopColor: c.error,
    }),
  ),
  extra('error field label is error-text', '[data-test="extra-error"] .v-field-label', (c) => ({
    color: c.errorText,
  })),
  extra('autocomplete box', '[data-test="extra-autocomplete"] .v-field', (c) => ({
    borderRadius: '8px',
    backgroundColor: c.canvas,
  })),
  extra(
    'combobox box (filled variant drawn as the same box)',
    '[data-test="extra-combobox"] .v-field',
    (c) => ({
      borderRadius: '8px',
      borderTopColor: c.outline,
      borderTopWidth: '1px',
    }),
  ),
]
