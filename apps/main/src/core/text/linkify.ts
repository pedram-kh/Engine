/**
 * AH-119 (S1) — split plain text into text and URL segments so a message body can render
 * clickable links WITHOUT ever building HTML (callers render segments as Vue text nodes).
 *
 * Only `http:` / `https:` URLs and bare `www.` hosts are linked. Every other scheme
 * (`javascript:`, `data:`, `mailto:`, `file:`, `ftp:` …) is left as plain text. The segments'
 * `text` fields always concatenate back to the input exactly.
 */

export type Segment = { type: 'text'; text: string } | { type: 'url'; text: string; href: string }

// A candidate starts at a scheme or `www.` that is not glued to a preceding word character,
// is followed by a letter/digit (a real host start) and runs to the next whitespace or `<` / `>`.
const CANDIDATE = /(?<![\p{L}\p{N}_])(?:https?:\/\/|www\.)[\p{L}\p{N}][^\s<>]*/giu

const TRAILING_PUNCTUATION = new Set(['.', ',', ';', ':', '!', '?', "'", '"'])
const CLOSERS: Record<string, string> = { ')': '(', ']': '[', '}': '{' }

function count(text: string, char: string): number {
  let n = 0
  for (const c of text) {
    if (c === char) n++
  }
  return n
}

/** Trim trailing punctuation and unbalanced closing brackets off a URL candidate. */
function trimTrailing(candidate: string): string {
  let url = candidate
  while (url.length > 0) {
    const last = url[url.length - 1] as string
    if (TRAILING_PUNCTUATION.has(last)) {
      url = url.slice(0, -1)
      continue
    }
    const opener = CLOSERS[last]
    if (opener !== undefined && count(url, last) > count(url, opener)) {
      url = url.slice(0, -1)
      continue
    }
    break
  }
  return url
}

function hasHost(url: string): boolean {
  return /^(?:https?:\/\/|www\.)[\p{L}\p{N}]/iu.test(url)
}

export function linkify(text: string): Segment[] {
  if (text === '') {
    return []
  }

  const segments: Segment[] = []
  let cursor = 0

  const pushText = (value: string): void => {
    if (value === '') return
    const prev = segments[segments.length - 1]
    if (prev !== undefined && prev.type === 'text') {
      prev.text += value
    } else {
      segments.push({ type: 'text', text: value })
    }
  }

  for (const match of text.matchAll(CANDIDATE)) {
    const start = match.index
    const url = trimTrailing(match[0])
    if (!hasHost(url)) {
      continue
    }
    pushText(text.slice(cursor, start))
    segments.push({
      type: 'url',
      text: url,
      href: /^www\./i.test(url) ? `https://${url}` : url,
    })
    cursor = start + url.length
  }
  pushText(text.slice(cursor))

  return segments
}
