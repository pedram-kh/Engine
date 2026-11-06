import { describe, expect, it } from 'vitest'

import { linkify } from './linkify'

const urls = (text: string) => linkify(text).filter((s) => s.type === 'url')
const join = (text: string) =>
  linkify(text)
    .map((s) => s.text)
    .join('')

describe('linkify', () => {
  it('returns [] for an empty string', () => {
    expect(linkify('')).toEqual([])
  })

  it('returns a single text segment when there is no URL', () => {
    expect(linkify('hello there')).toEqual([{ type: 'text', text: 'hello there' }])
  })

  it('links http and https URLs', () => {
    expect(linkify('see https://example.com/a?b=1 now')).toEqual([
      { type: 'text', text: 'see ' },
      { type: 'url', text: 'https://example.com/a?b=1', href: 'https://example.com/a?b=1' },
      { type: 'text', text: ' now' },
    ])
    expect(urls('http://example.com')[0]).toMatchObject({ href: 'http://example.com' })
  })

  it('accepts a bare www. form with an https href', () => {
    expect(linkify('go www.example.com/x')).toEqual([
      { type: 'text', text: 'go ' },
      { type: 'url', text: 'www.example.com/x', href: 'https://www.example.com/x' },
    ])
  })

  it.each([
    'javascript:alert(1)',
    'data:text/html;base64,PHNjcmlwdD4=',
    'mailto:a@b.com',
    'file:///etc/passwd',
    'ftp://example.com/x',
    'vbscript:msgbox(1)',
  ])('keeps %s as plain text', (input) => {
    expect(urls(input)).toEqual([])
    expect(linkify(input)).toEqual([{ type: 'text', text: input }])
  })

  it('keeps markup as text and never links it', () => {
    const input = '<script>alert(1)</script>'
    expect(linkify(input)).toEqual([{ type: 'text', text: input }])
  })

  it('does not link a scheme glued to a preceding word', () => {
    expect(urls('xhttps://example.com')).toEqual([])
  })

  it('does not link a bare scheme or a scheme with no host', () => {
    expect(urls('https:// and http://. and www.')).toEqual([])
  })

  it.each([
    ['https://example.com.', 'https://example.com', '.'],
    ['https://example.com, ok', 'https://example.com', ', ok'],
    ['https://example.com;', 'https://example.com', ';'],
    ['https://example.com:', 'https://example.com', ':'],
    ['https://example.com!', 'https://example.com', '!'],
    ['https://example.com?', 'https://example.com', '?'],
    ["https://example.com'", 'https://example.com', "'"],
    ['https://example.com"', 'https://example.com', '"'],
    ['https://example.com.)', 'https://example.com', '.)'],
    ['(https://example.com)', 'https://example.com', ')'],
    ['[https://example.com]', 'https://example.com', ']'],
    ['{https://example.com}', 'https://example.com', '}'],
  ])('trims trailing punctuation: %s', (input, url, rest) => {
    const found = urls(input)
    expect(found).toHaveLength(1)
    expect(found[0]?.text).toBe(url)
    expect(input.endsWith(rest)).toBe(true)
    expect(join(input)).toBe(input)
  })

  it('keeps balanced parentheses inside the URL', () => {
    expect(urls('https://en.wikipedia.org/wiki/Foo_(bar)')[0]?.text).toBe(
      'https://en.wikipedia.org/wiki/Foo_(bar)',
    )
    expect(urls('(see https://en.wikipedia.org/wiki/Foo_(bar)).')[0]?.text).toBe(
      'https://en.wikipedia.org/wiki/Foo_(bar)',
    )
  })

  it('links adjacent URLs separated by whitespace', () => {
    const found = urls('https://a.com https://b.com\nwww.c.com')
    expect(found.map((u) => u.text)).toEqual(['https://a.com', 'https://b.com', 'www.c.com'])
  })

  it('allows unicode hosts', () => {
    expect(urls('https://bücher.example/straße')[0]?.text).toBe('https://bücher.example/straße')
    expect(urls('https://пример.рф')[0]?.href).toBe('https://пример.рф')
  })

  it('keeps a very long URL as one segment', () => {
    const long = `https://example.com/${'a'.repeat(2500)}`
    const segments = linkify(`x ${long} y`)
    expect(segments).toHaveLength(3)
    expect(segments[1]).toMatchObject({ type: 'url', text: long })
  })

  it('round-trips every input exactly (concatenated text === input)', () => {
    const inputs = [
      '',
      ' ',
      'plain',
      'https://a.com',
      'a https://a.com b',
      'https://a.com.https://b.com',
      '((https://a.com/(x))))',
      'www.a.com, www.b.com; (www.c.com)!',
      'javascript:alert(1) https://ok.com data:xx',
      '<img src=x onerror=alert(1)> https://x.io/<b>',
      'line1\nhttps://a.com\r\nline3\ttab',
      'emoji 😀 https://a.com/😀 😀',
      'https://' + 'é'.repeat(50) + '.com/' + '('.repeat(5),
      'HTTPS://UPPER.COM and WWW.UPPER.COM',
      'http://a.com\u00a0http://b.com',
      '"https://a.com" \'https://b.com\'',
      '...https://a.com...',
      'x'.repeat(5000) + ' https://a.com',
    ]
    for (const input of inputs) {
      expect(join(input)).toBe(input)
    }
  })
})
