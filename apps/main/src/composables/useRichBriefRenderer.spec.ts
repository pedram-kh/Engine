import DOMPurify from 'dompurify'
import { describe, expect, it } from 'vitest'

import { ALLOWED_TAGS, renderRichBrief } from './useRichBriefRenderer'

describe('renderRichBrief', () => {
  it('renders bold + italic markdown to safe HTML', () => {
    const html = renderRichBrief('**Please** shoot *outdoors*.')
    expect(html).toContain('<strong>Please</strong>')
    expect(html).toContain('<em>outdoors</em>')
  })

  it("renders links with the house rel + target posture (noopener noreferrer, not renderBio's nofollow)", () => {
    const html = renderRichBrief('[our style guide](https://catalyst-engine.com/style)')
    expect(html).toContain('href="https://catalyst-engine.com/style"')
    expect(html).toContain('rel="noopener noreferrer"')
    expect(html).toContain('target="_blank"')
    expect(html).not.toContain('nofollow')
  })

  it('turns a single newline into a <br> (breaks: true — the line-breaks ask)', () => {
    const html = renderRichBrief('Bring your own props.\nWe provide lighting.')
    expect(html).toContain('<br>')
  })

  it('still starts a new paragraph on a blank line', () => {
    const html = renderRichBrief('First paragraph.\n\nSecond paragraph.')
    const matches = html.match(/<p>/g) ?? []
    expect(matches.length).toBe(2)
  })

  it('handles empty input safely', () => {
    expect(renderRichBrief('')).toBe('')
  })

  describe('XSS set (§5.34 — the creator job-board detail trust boundary)', () => {
    it('strips <script> tags (html: false escapes raw input to inert text, same defence as renderBio)', () => {
      const html = renderRichBrief('Please read this: <script>alert(1)</script>')
      expect(html).not.toContain('<script')
    })

    it("break-revert: DOMPurify (not markdown-it's html:false) is the layer actually holding the line — widening ALLOWED_TAGS to include script must red", () => {
      // The test above passes even if ALLOWED_TAGS were emptied out, because
      // markdown-it's `html: false` already escapes raw `<script>` to inert
      // text before DOMPurify ever runs — belt-and-braces, but it means that
      // test alone doesn't prove the allowlist array is load-bearing. This
      // test bypasses markdown-it entirely and sanitizes a REAL <script>
      // element directly against the live exported ALLOWED_TAGS, so a future
      // edit that widens it to include 'script' fails THIS assertion,
      // unmodified — not a hypothetical, an executable one.
      expect(ALLOWED_TAGS).not.toContain('script')
      const sanitized = DOMPurify.sanitize('<script>alert(1)</script>Safe text', {
        ALLOWED_TAGS,
        ALLOWED_ATTR: ['href', 'rel', 'target'],
      })
      expect(sanitized).toContain('Safe text')
      expect(sanitized).not.toContain('<script')
      expect(sanitized).not.toContain('alert(1)')
    })

    it('never emits event-handler attributes on any real <a> tag, even when the input tries raw HTML', () => {
      const html = renderRichBrief('<a href="https://example.com" onclick="alert(1)">click</a>')
      const realAnchorTags = html.match(/<a\s[^>]*>/g) ?? []
      expect(realAnchorTags.length).toBeGreaterThan(0)
      for (const tag of realAnchorTags) {
        expect(tag).not.toMatch(/onclick|onerror/i)
      }
    })

    it('drops non-allowlisted attributes like title, so a title-based injection attempt carries nothing', () => {
      const html = renderRichBrief('[click](https://example.com "onclick=alert(1)")')
      expect(html).not.toMatch(/title=/i)
      expect(html).not.toMatch(/onclick/i)
    })

    it('blocks javascript: hrefs — never produces an anchor for them', () => {
      const html = renderRichBrief('[bad](javascript:alert(1))')
      expect(html).not.toMatch(/<a\b/)
      expect(html).not.toMatch(/href="javascript:/i)
    })

    it('rejects non-http(s) schemes: data:, file:, vbscript:, mailto:, tel:, and protocol-relative //', () => {
      const schemes = [
        '[x](data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==)',
        '[x](file:///etc/passwd)',
        '[x](vbscript:msgbox(1))',
        '[x](mailto:someone@example.com)',
        '[x](tel:+15555555555)',
        '[x](//evil.example.com)',
      ]
      for (const markdown of schemes) {
        const html = renderRichBrief(markdown)
        expect(html).not.toMatch(/<a\b/)
      }
    })

    it('strips disallowed tags entirely — no lists, no code, no images, no headers', () => {
      expect(renderRichBrief('# Heading')).not.toContain('<h1')
      expect(renderRichBrief('- one\n- two')).not.toContain('<ul')
      expect(renderRichBrief('`code`')).not.toContain('<code')
      expect(renderRichBrief('![alt](https://example.com/img.png)')).not.toContain('<img')
    })
  })
})
