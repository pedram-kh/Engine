/**
 * Client-side CSV parser for the bulk-invite UI pre-upload preview.
 *
 * Sprint 3 Chunk 4 sub-step 11. The parser is intentionally a mirror
 * of `BulkInviteCsvParser` (backend) — same delimiter, same row caps,
 * same email-only validation. Frontend validates BEFORE upload so the
 * agency admin sees mis-formatted rows up-front (no second round-trip).
 *
 * Backend is the trust boundary: even if the frontend's parse passes,
 * the backend re-parses and returns its own `meta.errors`. Any
 * divergence between this parser and the backend's is a UX bug, not a
 * security bug.
 *
 * CSV format (matches `BulkInviteCsvParser`):
 *   - Required header column: `email` (case-insensitive).
 *   - Other columns: accepted, surfaced in the preview, NOT validated.
 *   - Row caps: 5 MB / 1000 rows hard / 100 rows soft warning.
 */

const MAX_BYTES = 5 * 1024 * 1024
const MAX_ROWS = 1000
const SOFT_WARNING_ROWS = 100
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

export interface BulkInviteCsvRow {
  rowNumber: number
  email: string
  raw: Record<string, string>
}

export interface BulkInviteCsvRowError {
  rowNumber: number
  code:
    | 'invitation.email_missing'
    | 'invitation.email_invalid'
    | 'csv.row_cap_exceeded'
    | 'csv.byte_cap_exceeded'
    | 'csv.header_missing'
    | 'csv.empty'
  detail: string
}

export interface BulkInviteCsvParseResult {
  rows: BulkInviteCsvRow[]
  errors: BulkInviteCsvRowError[]
  rowCount: number
  exceedsSoftWarning: boolean
  fatal: BulkInviteCsvRowError | null
}

function splitCsvLine(line: string): string[] {
  const cells: string[] = []
  let current = ''
  let inQuotes = false
  for (let i = 0; i < line.length; i++) {
    const ch = line[i]
    if (inQuotes) {
      if (ch === '"') {
        if (line[i + 1] === '"') {
          current += '"'
          i++
        } else {
          inQuotes = false
        }
      } else {
        current += ch
      }
    } else if (ch === '"') {
      inQuotes = true
    } else if (ch === ',') {
      cells.push(current)
      current = ''
    } else {
      current += ch ?? ''
    }
  }
  cells.push(current)
  return cells.map((c) => c.trim())
}

export function parseCsvText(text: string, sizeBytes: number): BulkInviteCsvParseResult {
  if (sizeBytes > MAX_BYTES) {
    return {
      rows: [],
      errors: [],
      rowCount: 0,
      exceedsSoftWarning: false,
      fatal: {
        rowNumber: 0,
        code: 'csv.byte_cap_exceeded',
        detail: 'CSV exceeds the 5 MB hard cap.',
      },
    }
  }

  const lines = text
    .replace(/\r\n/g, '\n')
    .replace(/\r/g, '\n')
    .split('\n')
    .filter((l) => l.trim() !== '')

  if (lines.length === 0) {
    return {
      rows: [],
      errors: [],
      rowCount: 0,
      exceedsSoftWarning: false,
      fatal: { rowNumber: 0, code: 'csv.empty', detail: 'CSV is empty.' },
    }
  }

  const headerCells = splitCsvLine(lines[0] ?? '').map((c) => c.toLowerCase())
  const emailIndex = headerCells.indexOf('email')
  if (emailIndex === -1) {
    return {
      rows: [],
      errors: [],
      rowCount: 0,
      exceedsSoftWarning: false,
      fatal: {
        rowNumber: 1,
        code: 'csv.header_missing',
        detail: 'CSV must include an `email` column.',
      },
    }
  }

  const rows: BulkInviteCsvRow[] = []
  const errors: BulkInviteCsvRowError[] = []
  const dataLines = lines.slice(1)

  if (dataLines.length > MAX_ROWS) {
    return {
      rows: [],
      errors: [],
      rowCount: 0,
      exceedsSoftWarning: false,
      fatal: {
        rowNumber: 0,
        code: 'csv.row_cap_exceeded',
        detail: `CSV exceeds the ${MAX_ROWS}-row hard cap.`,
      },
    }
  }

  dataLines.forEach((line, idx) => {
    const rowNumber = idx + 2
    const cells = splitCsvLine(line)
    const raw: Record<string, string> = {}
    headerCells.forEach((col, i) => {
      raw[col] = cells[i] ?? ''
    })

    const emailCell = (cells[emailIndex] ?? '').trim()
    if (emailCell === '') {
      errors.push({
        rowNumber,
        code: 'invitation.email_missing',
        detail: 'Email cell is empty.',
      })
      return
    }
    const normalised = emailCell.toLowerCase()
    if (!EMAIL_RE.test(normalised)) {
      errors.push({
        rowNumber,
        code: 'invitation.email_invalid',
        detail: `'${emailCell}' is not a valid email address.`,
      })
      return
    }

    rows.push({ rowNumber, email: normalised, raw })
  })

  return {
    rows,
    errors,
    rowCount: rows.length,
    exceedsSoftWarning: rows.length > SOFT_WARNING_ROWS,
    fatal: null,
  }
}

export const BULK_INVITE_CSV_LIMITS = {
  MAX_BYTES,
  MAX_ROWS,
  SOFT_WARNING_ROWS,
}
