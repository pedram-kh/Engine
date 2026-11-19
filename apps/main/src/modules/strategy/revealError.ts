/**
 * PL-2.1 — a dialog body scrolls while its actions stay put, so a field error under a field that is scrolled out of
 * view would be invisible when Save is pressed. After a failed validation or a server answer with field errors the
 * dialog calls this: the first field in error is scrolled to the middle of the body and focused.
 */
export function revealFirstError(root: Element | null | undefined): void {
  const field = root?.querySelector<HTMLElement>('.v-input--error')
  if (!field) return
  if (typeof field.scrollIntoView === 'function') field.scrollIntoView({ block: 'center' })
  field
    .querySelector<HTMLElement>('input:not([aria-hidden]), textarea:not([aria-hidden])')
    ?.focus({ preventScroll: true })
}
