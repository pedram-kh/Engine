import { describe, expect, it } from 'vitest'

import { renderBio } from './useBioRenderer'

describe('renderBio', () => {
  it('renders bold + italic markdown to safe HTML', () => {
    const html = renderBio('**Hi** *there*')
    expect(html).toContain('<strong>Hi</strong>')
    expect(html).toContain('<em>there</em>')
  })

  it('renders links with safe rel + target attributes', () => {
    const html = renderBio('[Catalyst](https://catalyst-engine.com)')
    expect(html).toContain('href="https://catalyst-engine.com"')
    expect(html).toContain('rel="noopener nofollow"')
    expect(html).toContain('target="_blank"')
  })

  it('escapes raw HTML in the input', () => {
    const html = renderBio('Hello <script>alert(1)</script>')
    expect(html).not.toContain('<script>')
  })

  it('blocks javascript: URLs — never produces an anchor for them', () => {
    // markdown-it's `validateLink` rejects unsafe schemes outright;
    // the raw text remains in the paragraph but no <a> tag is
    // generated. DOMPurify is the second line of defence if a
    // future config flip ever allowed the scheme through.
    const html = renderBio('[bad](javascript:alert(1))')
    expect(html).not.toMatch(/<a\b/)
    expect(html).not.toMatch(/href="javascript:/)
  })

  it('keeps a simple list intact', () => {
    const html = renderBio('- one\n- two')
    expect(html).toContain('<ul>')
    expect(html).toContain('<li>one</li>')
    expect(html).toContain('<li>two</li>')
  })

  it('handles empty input safely', () => {
    expect(renderBio('')).toBe('')
  })

  it('strips disallowed tags like <img>', () => {
    // Markdown image syntax produces an <img> tag, which is NOT on
    // the allowlist for bios (Q-wizard-1 (a) — bios are text-only
    // markdown, not images).
    const html = renderBio('![alt](https://example.com/img.png)')
    expect(html).not.toContain('<img')
  })
})
