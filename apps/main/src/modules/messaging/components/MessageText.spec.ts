import { readFileSync } from 'node:fs'
import path from 'node:path'

import { mount } from '@vue/test-utils'
import { h } from 'vue'
import { describe, expect, it } from 'vitest'

import MessageText from './MessageText.vue'

describe('MessageText', () => {
  it('renders a link with rel, target, class and data-test', () => {
    const wrapper = mount(MessageText, { props: { text: 'see https://example.com/x.' } })
    const a = wrapper.get('a')
    expect(a.attributes()).toMatchObject({
      href: 'https://example.com/x',
      rel: 'noopener noreferrer',
      target: '_blank',
      'data-test': 'message-link',
    })
    expect(a.classes()).toContain('message-link')
    const host = mount({
      render: () => h('p', [h(MessageText, { text: 'see https://example.com/x.' })]),
    })
    expect(host.element.textContent).toBe('see https://example.com/x.')
  })

  it('renders a www link with an https href', () => {
    const a = mount(MessageText, { props: { text: 'www.example.com' } }).get('a')
    expect(a.attributes('href')).toBe('https://www.example.com')
  })

  it('does not link javascript: text', () => {
    const wrapper = mount(MessageText, { props: { text: 'javascript:alert(1)' } })
    expect(wrapper.find('a').exists()).toBe(false)
    expect(wrapper.text()).toBe('javascript:alert(1)')
  })

  it('keeps <img onerror> as escaped text, never an element', () => {
    const text = '<img src=x onerror=alert(1)> https://example.com'
    const wrapper = mount(MessageText, { props: { text } })
    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.html()).toContain('&lt;img src=x onerror=alert(1)&gt;')
    expect(wrapper.findAll('a')).toHaveLength(1)
  })

  it('renders a URL-free body as ONE text node with no wrapper element', () => {
    const Host = {
      render: () => h('p', { class: 'body' }, [h(MessageText, { text: 'hello there' })]),
    }
    const wrapper = mount(Host)
    const p = wrapper.get('p').element
    expect(p.outerHTML).toBe('<p class="body">hello there</p>')
    expect(p.childNodes).toHaveLength(1)
    expect(p.firstChild?.nodeType).toBe(Node.TEXT_NODE)
  })

  it('never uses v-html', () => {
    const source = readFileSync(path.join(__dirname, 'MessageText.vue'), 'utf8')
    expect(source).not.toContain('v-html')
    expect(source).not.toContain('innerHTML')
  })
})
