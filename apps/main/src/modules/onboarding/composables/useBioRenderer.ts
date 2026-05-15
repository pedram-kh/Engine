/**
 * Client-side bio renderer (Sprint 3 Chunk 3 sub-step 4,
 * Q-wizard-1 (a)).
 *
 * The bio field accepts a small subset of Markdown — bold, italic,
 * links — and is rendered client-side for the live-preview UI.
 * The output is sanitized through DOMPurify before being inserted
 * into the DOM via `v-html`, defending against any future copy/
 * paste injection vector even though the bio is the creator's own
 * content displayed only to themselves and to brand reviewers.
 *
 * Why DOMPurify over markdown-it-sanitizer (Refinement 4):
 *   - DOMPurify is the modern, actively-maintained sanitizer
 *     standard.
 *   - markdown-it-sanitizer is unmaintained (last release 2019).
 *
 * The renderer pins `html: false` on markdown-it so raw HTML in
 * the input is escaped at the markdown-it layer too, giving us
 * two independent defences (#40 break-revert: flip `html: true`
 * and confirm a script-tag XSS spec fails — the sanitizer still
 * catches it).
 */

import DOMPurify from 'dompurify'
import MarkdownIt from 'markdown-it'

const md = new MarkdownIt({
  html: false,
  linkify: true,
  breaks: false,
  typographer: false,
})

// Allowlist of tags the bio can include. Locked to a small, safe
// set; expanding requires a follow-up review.
const ALLOWED_TAGS = ['p', 'br', 'strong', 'em', 'a', 'ul', 'ol', 'li', 'code']
const ALLOWED_ATTR = ['href', 'rel', 'target']

function postProcessLinks(html: string): string {
  // Force `target="_blank"` and `rel="noopener nofollow"` on
  // every link so a creator who pastes an external URL into their
  // own bio cannot fingerprint another tab's referrer and cannot
  // inject `javascript:` URLs (DOMPurify already blocks the
  // latter, but the rel hint defends against social-engineered
  // landing pages).
  return html.replace(/<a\s+([^>]*?)>/g, (_match, attrs: string): string => {
    const cleaned = attrs.replace(/\s*(target|rel)="[^"]*"/g, '').trim()
    return `<a ${cleaned} target="_blank" rel="noopener nofollow">`
  })
}

export function renderBio(markdown: string): string {
  const rendered = md.render(markdown ?? '')
  const sanitized = DOMPurify.sanitize(rendered, {
    ALLOWED_TAGS,
    ALLOWED_ATTR,
  })
  return postProcessLinks(sanitized)
}
