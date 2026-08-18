/**
 * `renderRichBrief` — sanitized rich-text rendering for agency-authored brief
 * fields shown in a creator's browser (AH-081).
 *
 * A tighter sibling of `renderBio`
 * (`apps/main/src/modules/onboarding/composables/useBioRenderer.ts`), not a
 * clone: bios are the creator's own content, previewed mostly to themselves;
 * briefs (`campaign_assignments.offer_description`, `campaigns.description`)
 * are agency-authored and rendered in the CREATOR's browser — a genuine
 * cross-party trust boundary, most acutely on the public job-board detail
 * page. The allowlist is narrower and the defences are stricter as a result:
 *
 *   - Tags: exactly `p, br, strong, em, a` — no lists, no code, no images,
 *     no headers. Briefs are prose, not documents.
 *   - `breaks: true` (renderBio uses `false`) — a single newline becomes
 *     `<br>`, matching the "line breaks" ask; renderBio's paragraph-only
 *     behaviour was a documented mismatch for this use case.
 *   - Links: markdown-it's built-in `validateLink` blocklists a few unsafe
 *     schemes but otherwise allows anything (mailto:, tel:, protocol-relative
 *     //, relative paths). A brief's links are never any of those, so
 *     `validateLink` here is a strict ALLOWLIST of `http://`/`https://` —
 *     everything else is rejected before markdown-it emits an `<a>` at all.
 *   - DOMPurify additionally pins `ALLOWED_URI_REGEXP` to the same http(s)
 *     pattern, so a config regression in the first layer (e.g. a future
 *     `md.validateLink` override) still can't produce a non-http(s) href —
 *     the two-independent-defences shape `useBioRenderer.ts` documents.
 *   - Every link is forced to `target="_blank" rel="noopener noreferrer"`,
 *     the house-wide external-link posture (e.g. `DraftReviewPanel.vue`,
 *     `ChatPanel.vue`) rather than renderBio's older `rel="noopener nofollow"`.
 *
 * `html: false` on markdown-it escapes any raw HTML in the input at the
 * markdown layer, same as renderBio — belt-and-braces with DOMPurify’s own
 * tag stripping (#break-revert: widen `ALLOWED_TAGS` to include `script` and
 * confirm the injected-script spec still reds — the sanitizer is the layer
 * actually holding the line, not the allowlist array).
 */

import DOMPurify from 'dompurify'
import MarkdownIt from 'markdown-it'

const HTTP_URL_RE = /^https?:\/\//i

function validateHttpOnlyLink(url: string): boolean {
  return HTTP_URL_RE.test(url.trim())
}

const md = new MarkdownIt({
  html: false,
  linkify: true,
  breaks: true,
  typographer: false,
})
md.validateLink = validateHttpOnlyLink

// Exported (not just module-private) so useRichBriefRenderer.spec.ts's
// break-revert case sanitizes raw HTML directly against this SAME array
// reference — a future edit that widens it to include `script` fails that
// spec immediately, rather than the spec silently testing a stale copy.
export const ALLOWED_TAGS = ['p', 'br', 'strong', 'em', 'a']
const ALLOWED_ATTR = ['href', 'rel', 'target']
const ALLOWED_URI_REGEXP = HTTP_URL_RE

function postProcessLinks(html: string): string {
  return html.replace(/<a\s+([^>]*?)>/g, (_match, attrs: string): string => {
    const cleaned = attrs.replace(/\s*(target|rel)="[^"]*"/g, '').trim()
    return `<a ${cleaned} target="_blank" rel="noopener noreferrer">`
  })
}

export function renderRichBrief(markdown: string): string {
  const rendered = md.render(markdown ?? '')
  const sanitized = DOMPurify.sanitize(rendered, {
    ALLOWED_TAGS,
    ALLOWED_ATTR,
    ALLOWED_URI_REGEXP,
  })
  return postProcessLinks(sanitized)
}
