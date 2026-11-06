/**
 * AH-119 walk — marks where the search terms fall in a result snippet.
 *
 * The snippet is plain text from the server (never markup). This splits it into runs the template
 * renders as TEXT NODES (a match inside a styled span), so nothing is ever parsed as HTML: whatever
 * the message contained, `<b>` or `<img onerror=…>`, shows up as the characters it is.
 *
 * Terms follow the server's rule (`MessageSearch::terms`): split on anything that is not a letter
 * or a digit, under 2 characters dropped, at most 8 terms of at most 40 characters. A match is
 * case-insensitive, and the match is the term as typed plus the rest of the word, because the
 * server searches word PREFIXES ("bri" finds "brief").
 */

export interface SnippetRun {
  text: string
  match: boolean
}

const MAX_TERMS = 8
const MAX_TERM_LENGTH = 40

export function searchTerms(query: string): string[] {
  const terms: string[] = []
  for (const part of query.toLowerCase().split(/[^\p{L}\p{N}]+/u)) {
    if (part.length >= 2 && !terms.includes(part.slice(0, MAX_TERM_LENGTH))) {
      terms.push(part.slice(0, MAX_TERM_LENGTH))
    }
    if (terms.length === MAX_TERMS) {
      break
    }
  }
  return terms
}

function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

export function highlightSnippet(snippet: string, query: string): SnippetRun[] {
  const terms = searchTerms(query)
  if (snippet === '' || terms.length === 0) {
    return [{ text: snippet, match: false }]
  }

  // Longest first, so "brief" wins over "bri" at the same position.
  const alternation = [...terms]
    .sort((a, b) => b.length - a.length)
    .map(escapeRegExp)
    .join('|')
  const pattern = new RegExp(`(?<![\\p{L}\\p{N}])(?:${alternation})[\\p{L}\\p{N}]*`, 'giu')

  const runs: SnippetRun[] = []
  let last = 0
  for (const found of snippet.matchAll(pattern)) {
    const at = found.index ?? 0
    if (at > last) {
      runs.push({ text: snippet.slice(last, at), match: false })
    }
    runs.push({ text: found[0], match: true })
    last = at + found[0].length
  }
  if (last < snippet.length) {
    runs.push({ text: snippet.slice(last), match: false })
  }

  return runs.length === 0 ? [{ text: snippet, match: false }] : runs
}
