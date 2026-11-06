/**
 * AH-119 walk — `highlightSnippet` splits plain text into runs; it never produces markup.
 *
 * Break-revert: drop the word-start guard `(?<![\p{L}\p{N}])` → "mid-word" matches; return the
 * snippet unsplit when terms exist → the highlight cases fail.
 */

import { describe, expect, it } from 'vitest'

import { highlightSnippet, searchTerms } from './highlightSnippet'

describe('searchTerms', () => {
  it('lower-cases, splits on non letters and digits, drops 1-character terms, caps at 8', () => {
    expect(searchTerms('Brief, a  Q4-plan')).toEqual(['brief', 'q4', 'plan'])
    expect(searchTerms('a b c')).toEqual([])
    expect(searchTerms('one two three four five six seven eight nine ten')).toHaveLength(8)
  })

  it('keeps accented letters and de-duplicates', () => {
    expect(searchTerms('Café café')).toEqual(['café'])
  })
})

describe('highlightSnippet', () => {
  it('marks the matched word, case-insensitively, keeping the snippet text exact', () => {
    const runs = highlightSnippet('…the Brief is attached…', 'brief')
    expect(runs).toEqual([
      { text: '…the ', match: false },
      { text: 'Brief', match: true },
      { text: ' is attached…', match: false },
    ])
    expect(runs.map((r) => r.text).join('')).toBe('…the Brief is attached…')
  })

  it('marks the whole word for a prefix, as the server searches prefixes', () => {
    expect(highlightSnippet('the briefing', 'bri')).toEqual([
      { text: 'the ', match: false },
      { text: 'briefing', match: true },
    ])
  })

  it('does not mark a term in the middle of a word', () => {
    expect(highlightSnippet('debrief', 'brief')).toEqual([{ text: 'debrief', match: false }])
  })

  it('marks several terms and every occurrence', () => {
    const runs = highlightSnippet('shoot the shoot date', 'shoot date')
    expect(runs.filter((r) => r.match).map((r) => r.text)).toEqual(['shoot', 'shoot', 'date'])
  })

  it('returns one plain run for an empty query, a 1-character query, or no match', () => {
    expect(highlightSnippet('hello', '')).toEqual([{ text: 'hello', match: false }])
    expect(highlightSnippet('hello', 'h')).toEqual([{ text: 'hello', match: false }])
    expect(highlightSnippet('hello', 'zzz')).toEqual([{ text: 'hello', match: false }])
  })

  it('treats regex characters in the query as plain text', () => {
    expect(() => highlightSnippet('a (b) c', '(b) .*')).not.toThrow()
    expect(highlightSnippet('x.y', 'x.')).toEqual([{ text: 'x.y', match: false }])
  })

  it('leaves markup in the snippet as characters, never splitting it into elements', () => {
    const runs = highlightSnippet('<b>brief</b><img src=x onerror=alert(1)>', 'brief')
    expect(runs.map((r) => r.text).join('')).toBe('<b>brief</b><img src=x onerror=alert(1)>')
    expect(runs.filter((r) => r.match).map((r) => r.text)).toEqual(['brief'])
  })
})
