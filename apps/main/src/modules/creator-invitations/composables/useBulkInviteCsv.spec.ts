/**
 * useBulkInviteCsv parser tests — Sprint 3 Chunk 4 sub-step 11.
 *
 * The frontend parser mirrors `BulkInviteCsvParser` (backend) exactly;
 * these tests pin the parity at the row-validation and limit-handling
 * level. The backend re-parses on upload anyway — divergence here is a
 * UX bug rather than a security one.
 */

import { describe, expect, it } from 'vitest'

import { parseCsvText, BULK_INVITE_CSV_LIMITS } from './useBulkInviteCsv'

describe('parseCsvText (bulk-invite)', () => {
  it('parses a happy-path 3-row CSV with only the email column', () => {
    const csv = 'email\nalice@example.com\nbob@example.com\ncarol@example.com\n'
    const result = parseCsvText(csv, csv.length)
    expect(result.fatal).toBeNull()
    expect(result.rowCount).toBe(3)
    expect(result.rows.map((r) => r.email)).toEqual([
      'alice@example.com',
      'bob@example.com',
      'carol@example.com',
    ])
    expect(result.errors).toHaveLength(0)
    expect(result.exceedsSoftWarning).toBe(false)
  })

  it('accepts extra columns and surfaces them in `raw` but does not validate them', () => {
    const csv =
      'email,primary_platform,handle\nalice@example.com,instagram,@alice\nbob@example.com,tiktok,@bob\n'
    const result = parseCsvText(csv, csv.length)
    expect(result.rows).toHaveLength(2)
    expect(result.rows[0]?.raw).toEqual({
      email: 'alice@example.com',
      primary_platform: 'instagram',
      handle: '@alice',
    })
    expect(result.errors).toHaveLength(0)
  })

  it('emits invitation.email_missing for an empty email cell', () => {
    const csv = 'email\nalice@example.com\n\n'
    const result = parseCsvText(csv, csv.length)
    expect(result.rowCount).toBe(1)
    // Empty line is filtered out before splitting; the only test for
    // email_missing comes from a row with explicitly empty email cell
    // (e.g. ",foo"). We verify with a multi-column CSV below.
    const csv2 = 'email,name\n,Alice\nbob@example.com,Bob\n'
    const result2 = parseCsvText(csv2, csv2.length)
    expect(result2.rows).toHaveLength(1)
    expect(result2.errors).toHaveLength(1)
    expect(result2.errors[0]?.code).toBe('invitation.email_missing')
  })

  it('emits invitation.email_invalid for a malformed email', () => {
    const csv = 'email\nnot-an-email\nalice@example.com\n'
    const result = parseCsvText(csv, csv.length)
    expect(result.rows).toHaveLength(1)
    expect(result.errors).toHaveLength(1)
    expect(result.errors[0]).toEqual({
      rowNumber: 2,
      code: 'invitation.email_invalid',
      detail: "'not-an-email' is not a valid email address.",
    })
  })

  it('normalises emails to lowercase before storage', () => {
    const csv = 'email\nAlice@EXAMPLE.com\n'
    const result = parseCsvText(csv, csv.length)
    expect(result.rows[0]?.email).toBe('alice@example.com')
  })

  it('emits csv.header_missing when the email column is absent', () => {
    const csv = 'name,handle\nAlice,@alice\n'
    const result = parseCsvText(csv, csv.length)
    expect(result.fatal).toEqual({
      rowNumber: 1,
      code: 'csv.header_missing',
      detail: 'CSV must include an `email` column.',
    })
    expect(result.rows).toHaveLength(0)
  })

  it('emits csv.empty when the file has no lines', () => {
    const result = parseCsvText('', 0)
    expect(result.fatal?.code).toBe('csv.empty')
  })

  it('emits csv.byte_cap_exceeded when sizeBytes > 5 MB', () => {
    const result = parseCsvText('email\nalice@example.com\n', BULK_INVITE_CSV_LIMITS.MAX_BYTES + 1)
    expect(result.fatal?.code).toBe('csv.byte_cap_exceeded')
  })

  it('emits csv.row_cap_exceeded when data rows > 1000', () => {
    const lines = ['email']
    for (let i = 0; i < BULK_INVITE_CSV_LIMITS.MAX_ROWS + 1; i++) {
      lines.push(`user${i}@example.com`)
    }
    const csv = lines.join('\n') + '\n'
    const result = parseCsvText(csv, csv.length)
    expect(result.fatal?.code).toBe('csv.row_cap_exceeded')
  })

  it('flags exceedsSoftWarning when rows > 100', () => {
    const lines = ['email']
    for (let i = 0; i < 101; i++) {
      lines.push(`user${i}@example.com`)
    }
    const csv = lines.join('\n') + '\n'
    const result = parseCsvText(csv, csv.length)
    expect(result.exceedsSoftWarning).toBe(true)
    expect(result.rowCount).toBe(101)
  })

  it('handles CRLF line endings', () => {
    const csv = 'email\r\nalice@example.com\r\nbob@example.com\r\n'
    const result = parseCsvText(csv, csv.length)
    expect(result.rows).toHaveLength(2)
    expect(result.fatal).toBeNull()
  })

  it('is case-insensitive on the email header', () => {
    const csv = 'EMAIL\nalice@example.com\n'
    const result = parseCsvText(csv, csv.length)
    expect(result.rows).toHaveLength(1)
  })
})
