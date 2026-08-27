/**
 * Unit tests for the Agency Files API wrapper (AH-091, chunk A).
 *
 * Pins the endpoint shapes verbatim against `AgencyFileController` +
 * `agencies/{agency}/files` route table: the `list()` query-builder, the
 * three-call presigned dance's two server round-trips (`initUpload` /
 * `completeUpload`), `show()`, and `delete()` resolving on 204. The HTTP
 * singleton is mocked so no transport runs.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/core/api', () => ({
  http: {
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
}))

import { http } from '@/core/api'
import { filesApi } from './files.api'

const mockHttp = vi.mocked(http)

function lastListUrl(): URLSearchParams {
  const url = mockHttp.get.mock.calls.at(-1)![0] as string
  return new URLSearchParams(url.split('?')[1] ?? '')
}

describe('filesApi.list', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockHttp.get.mockResolvedValue({
      data: [],
      meta: { total: 0, page: 1, per_page: 25, last_page: 1 },
    })
  })

  it('omits the query string entirely with no params', () => {
    void filesApi.list('agency-ulid')
    expect(mockHttp.get).toHaveBeenCalledWith('/agencies/agency-ulid/files')
  })

  it('threads page + per_page onto the query string', () => {
    void filesApi.list('agency-ulid', { page: 2, per_page: 50 })
    const query = lastListUrl()
    expect(query.get('page')).toBe('2')
    expect(query.get('per_page')).toBe('50')
  })
})

describe('filesApi.listOptions (Campaign attachments chunk B, AH-092, S3)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockHttp.get.mockResolvedValue({ data: [] })
  })

  it('GETs the collection with the ?for=select query string', () => {
    void filesApi.listOptions('agency-ulid')
    expect(mockHttp.get).toHaveBeenCalledWith('/agencies/agency-ulid/files?for=select')
  })
})

describe('filesApi upload dance', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockHttp.post.mockResolvedValue({ data: {} })
    mockHttp.get.mockResolvedValue({ data: {} })
    mockHttp.delete.mockResolvedValue(undefined)
  })

  it('POSTs to files/uploads/init with the mime + declared bytes', () => {
    void filesApi.initUpload('agency-ulid', {
      mime_type: 'application/pdf',
      declared_bytes: 1024,
    })
    expect(mockHttp.post).toHaveBeenCalledWith('/agencies/agency-ulid/files/uploads/init', {
      mime_type: 'application/pdf',
      declared_bytes: 1024,
    })
  })

  it('POSTs to files/uploads/complete with the upload_id + original_filename', () => {
    void filesApi.completeUpload('agency-ulid', {
      upload_id: 'agencies/agency-ulid/files/01HZ.pdf',
      original_filename: 'Q3 report.pdf',
    })
    expect(mockHttp.post).toHaveBeenCalledWith('/agencies/agency-ulid/files/uploads/complete', {
      upload_id: 'agencies/agency-ulid/files/01HZ.pdf',
      original_filename: 'Q3 report.pdf',
    })
  })

  it('show() GETs the single-file envelope by id', () => {
    void filesApi.show('agency-ulid', 'file-ulid')
    expect(mockHttp.get).toHaveBeenCalledWith('/agencies/agency-ulid/files/file-ulid')
  })

  it('delete() DELETEs by id and resolves on 204 (no body)', async () => {
    await expect(filesApi.delete('agency-ulid', 'file-ulid')).resolves.toBeUndefined()
    expect(mockHttp.delete).toHaveBeenCalledWith('/agencies/agency-ulid/files/file-ulid')
  })
})
