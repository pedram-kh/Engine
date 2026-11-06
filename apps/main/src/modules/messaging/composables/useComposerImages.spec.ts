import { mount } from '@vue/test-utils'
import { defineComponent, h, nextTick, ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'

import { useComposerImages } from './useComposerImages'

function file(name: string, type: string, size = 10): File {
  const f = new File(['x'], name, { type })
  Object.defineProperty(f, 'size', { value: size })
  return f
}

function setup(options: Parameters<typeof useComposerImages>[2] = {}) {
  const onFiles = vi.fn()
  let dragging!: ReturnType<typeof useComposerImages>['dragging']
  const Host = defineComponent({
    setup() {
      const el = ref<HTMLElement | null>(null)
      dragging = useComposerImages(el, onFiles, options).dragging
      return () => h('form', { ref: el }, [h('textarea')])
    },
  })
  const wrapper = mount(Host, { attachTo: document.body })
  return { wrapper, onFiles, dragging: () => dragging.value, form: wrapper.element as HTMLElement }
}

function paste(target: Element, clipboardData: unknown): Event {
  const event = new Event('paste', { bubbles: true, cancelable: true })
  Object.defineProperty(event, 'clipboardData', { value: clipboardData })
  target.dispatchEvent(event)
  return event
}

function drag(target: Element, type: string, files: File[] = [], types = ['Files']): Event {
  const event = new Event(type, { bubbles: true, cancelable: true })
  Object.defineProperty(event, 'dataTransfer', { value: { files, types } })
  target.dispatchEvent(event)
  return event
}

describe('useComposerImages', () => {
  it('accepts a pasted image and prevents the default paste', () => {
    const { wrapper, onFiles, form } = setup()
    const png = file('a.png', 'image/png')
    const event = paste(form.querySelector('textarea')!, { files: [png] })
    expect(onFiles).toHaveBeenCalledWith([png])
    expect(event.defaultPrevented).toBe(true)
    wrapper.unmount()
  })

  it('leaves a plain-text paste untouched', () => {
    const { wrapper, onFiles, form } = setup()
    const onRejected = vi.fn()
    const event = paste(form, { files: [], getData: () => 'hello' })
    expect(onFiles).not.toHaveBeenCalled()
    expect(onRejected).not.toHaveBeenCalled()
    expect(event.defaultPrevented).toBe(false)
    wrapper.unmount()
  })

  it('rejects a pasted pdf with "type" and does not prevent default', () => {
    const onRejected = vi.fn()
    const { wrapper, onFiles, form } = setup({ onRejected })
    const event = paste(form, { files: [file('a.pdf', 'application/pdf')] })
    expect(onRejected).toHaveBeenCalledWith('type')
    expect(onFiles).not.toHaveBeenCalled()
    expect(event.defaultPrevented).toBe(false)
    wrapper.unmount()
  })

  it.each(['image/png', 'image/jpeg', 'image/webp', 'image/gif'])('allows %s', (type) => {
    const { wrapper, onFiles, form } = setup()
    paste(form, { files: [file('i', type)] })
    expect(onFiles).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('rejects svg (not an allowed image type)', () => {
    const onRejected = vi.fn()
    const { wrapper, onFiles, form } = setup({ onRejected })
    paste(form, { files: [file('a.svg', 'image/svg+xml')] })
    expect(onRejected).toHaveBeenCalledWith('type')
    expect(onFiles).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('rejects an oversize image with "size" and hands over only the rest', () => {
    const onRejected = vi.fn()
    const { wrapper, onFiles, form } = setup({ onRejected })
    const small = file('s.png', 'image/png', 1024)
    const big = file('b.png', 'image/png', 25 * 1024 * 1024 + 1)
    paste(form, { files: [big, small] })
    expect(onRejected).toHaveBeenCalledWith('size')
    expect(onFiles).toHaveBeenCalledWith([small])
    wrapper.unmount()
  })

  it('accepts exactly 25 MB and honours a custom maxBytes', () => {
    const { wrapper, onFiles, form } = setup()
    paste(form, { files: [file('e.png', 'image/png', 25 * 1024 * 1024)] })
    expect(onFiles).toHaveBeenCalledTimes(1)
    wrapper.unmount()

    const onRejected = vi.fn()
    const custom = setup({ maxBytes: 5, onRejected })
    paste(custom.form, { files: [file('c.png', 'image/png', 6)] })
    expect(onRejected).toHaveBeenCalledWith('size')
    custom.wrapper.unmount()
  })

  it('accepts dropped images and rejects other files with "type"', () => {
    const onRejected = vi.fn()
    const { wrapper, onFiles, form } = setup({ onRejected })
    const png = file('a.png', 'image/png')
    const event = drag(form, 'drop', [png, file('a.zip', 'application/zip')])
    expect(onFiles).toHaveBeenCalledWith([png])
    expect(onRejected).toHaveBeenCalledWith('type')
    expect(event.defaultPrevented).toBe(true)
    wrapper.unmount()
  })

  it('tracks the dragging state across enter / leave / drop', async () => {
    const { wrapper, dragging, form } = setup()
    expect(dragging()).toBe(false)
    drag(form, 'dragenter')
    expect(dragging()).toBe(true)
    drag(form.querySelector('textarea')!, 'dragenter') // nested element enter
    drag(form.querySelector('textarea')!, 'dragleave')
    expect(dragging()).toBe(true)
    drag(form, 'dragleave')
    expect(dragging()).toBe(false)
    drag(form, 'dragenter')
    drag(form, 'drop', [])
    expect(dragging()).toBe(false)
    await nextTick()
    wrapper.unmount()
  })

  it('ignores drags that carry no files (e.g. selected text)', () => {
    const { wrapper, dragging, form } = setup()
    const event = drag(form, 'dragenter', [], ['text/plain'])
    expect(dragging()).toBe(false)
    expect(event.defaultPrevented).toBe(false)
    wrapper.unmount()
  })

  it('does nothing when clipboardData is missing', () => {
    const { wrapper, onFiles, form } = setup()
    const event = paste(form, undefined)
    expect(onFiles).not.toHaveBeenCalled()
    expect(event.defaultPrevented).toBe(false)
    wrapper.unmount()
  })

  it('removes its listeners on unmount', () => {
    const { wrapper, onFiles, form } = setup()
    wrapper.unmount()
    paste(form, { files: [file('a.png', 'image/png')] })
    drag(form, 'drop', [file('a.png', 'image/png')])
    expect(onFiles).not.toHaveBeenCalled()
  })
})
