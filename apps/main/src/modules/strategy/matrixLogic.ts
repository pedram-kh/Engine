/**
 * PL-2.2 — the small pure rules of the messaging matrix screen, kept out of the components so they are tested alone.
 */
import type { MatrixCell, MatrixCellChange, MatrixLevel, MatrixUsp } from '@catalyst/api-client'

export const LEVELS: readonly MatrixLevel[] = ['priority', 'support', 'low'] as const

export function cellKey(persona: string, usp: string): string {
  return `${persona}:${usp}`
}

/** The titles of a persona's Priority cells, in the USPs' order. Empty: the header shows "Lead with: —" (Q8). */
export function leadWith(
  persona: string,
  usps: readonly MatrixUsp[],
  cells: ReadonlyMap<string, MatrixCell>,
): string[] {
  return usps
    .filter((u) => cells.get(cellKey(persona, u.id))?.level === 'priority')
    .map((u) => u.title)
}

/** Priority and Support cells feed a persona angle, so they need a message line (Q1). Low does not. */
export function needsMessageLine(level: MatrixLevel | null): boolean {
  return level === 'priority' || level === 'support'
}

/** The text a field sends: trimmed, and `null` when blank. */
export function fieldValue(text: string): string | null {
  const v = text.trim()
  return v === '' ? null : v
}

export interface EditorState {
  level: MatrixLevel | null
  rationale: string
  messageLine: string
}

/**
 * The request for one cell: only the fields that changed. A cell that is not set yet needs its level (and sends the
 * text it has). Returns `null` when nothing changed, so Save just closes.
 */
export function changeFor(
  persona: string,
  usp: string,
  before: MatrixCell | null,
  now: EditorState,
): MatrixCellChange | null {
  if (now.level === null) return null
  const change: MatrixCellChange = { persona, usp }
  const rationale = fieldValue(now.rationale)
  const line = fieldValue(now.messageLine)
  if (before === null || before.level === null) {
    change.level = now.level
    if (rationale !== null) change.rationale = rationale
    if (line !== null) change.message_line = line
    return change
  }
  let changed = false
  if (before.level !== now.level) {
    change.level = now.level
    changed = true
  }
  if (before.rationale !== rationale) {
    change.rationale = rationale
    changed = true
  }
  if (before.message_line !== line) {
    change.message_line = line
    changed = true
  }
  return changed ? change : null
}

/** The cell after the one at (personaIndex, uspIndex) in reading order (down a column, then the next column). */
export function nextCell(
  personaIndex: number,
  uspIndex: number,
  personaCount: number,
  uspCount: number,
): { personaIndex: number; uspIndex: number } | null {
  if (uspIndex + 1 < uspCount) return { personaIndex, uspIndex: uspIndex + 1 }
  if (personaIndex + 1 < personaCount) return { personaIndex: personaIndex + 1, uspIndex: 0 }
  return null
}
