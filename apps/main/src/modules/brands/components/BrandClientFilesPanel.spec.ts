/** PL-1.1 W5 — BrandClientFilesPanel and BrandFileUploadDialog. */
import { ApiError } from '@catalyst/api-client'
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api/brandFiles.api', () => ({
  brandFilesApi: {
    list: vi.fn(),
    initUpload: vi.fn(),
    completeUpload: vi.fn(),
    show: vi.fn(),
    delete: vi.fn(),
  },
}))

// Keep ApiError real; stub only the vendor PUT.
vi.mock('@catalyst/api-client', async (importActual) => {
  const actual = await importActual<typeof import('@catalyst/api-client')>()
  return { ...actual, uploadToPresignedUrl: vi.fn().mockResolvedValue(undefined) }
})

import { uploadToPresignedUrl } from '@catalyst/api-client'

import { AGENCY_ULID, BRAND_ULID, makeFile, mountWithApp } from '@/modules/waves/waveTestKit'
import { brandFilesApi } from '../api/brandFiles.api'
import BrandClientFilesPanel from './BrandClientFilesPanel.vue'
import BrandFileUploadDialog from './BrandFileUploadDialog.vue'

const body = (sel: string): HTMLElement | null => document.body.querySelector(sel)
const FILE_ID = '01FILEULIDXXXXXXXXXXXXXXXX'
const props = (canWrite: boolean) => ({ agencyId: AGENCY_ULID, brandId: BRAND_ULID, canWrite })
const route = `/brands/${BRAND_ULID}`

function list(files = [makeFile()]) {
  vi.mocked(brandFilesApi.list).mockResolvedValue({
    data: files,
    meta: { total: files.length, page: 1, per_page: 100, last_page: 1 },
  })
}

function file(name: string, type: string, bytes = 10): File {
  const f = new File(['x'], name, { type })
  Object.defineProperty(f, 'size', { value: bytes })
  return f
}

beforeEach(() => {
  vi.clearAllMocks()
  vi.mocked(brandFilesApi.initUpload).mockResolvedValue({
    data: {
      upload_url: 'https://s3.example.test/put',
      upload_id: 'up-1',
      storage_path: 'k',
      expires_at: 'x',
      max_bytes: 1,
    },
  })
  vi.mocked(brandFilesApi.completeUpload).mockResolvedValue({
    data: makeFile('01NEWFILEULIDXXXXXXXXXXXX', { original_filename: 'new.pdf' }),
  })
})

// A failed test must not leave its teleported dialog behind for the next one.
afterEach(() => {
  document.body.innerHTML = ''
})

describe('BrandClientFilesPanel (PL-1.1 W5)', () => {
  it('lists files with name, size, type, uploader and date', async () => {
    list()
    const w = await mountWithApp(BrandClientFilesPanel, props(true), { route })
    await flushPromises()
    const row = w.get(`[data-test="brand-file-row-${FILE_ID}"]`)
    expect(row.text()).toContain('brief.pdf')
    expect(row.text()).toContain('2.0 KB')
    expect(row.text()).toContain('Brief')
    expect(row.text()).toContain('Ada Admin')
    w.unmount()
  })

  it('shows loading, empty and error states', async () => {
    vi.mocked(brandFilesApi.list).mockRejectedValue(new Error('x'))
    const failed = await mountWithApp(BrandClientFilesPanel, props(true), { route })
    await flushPromises()
    expect(failed.get('[data-test="brand-files-error"]').text()).toBe(
      'Failed to load client files.',
    )
    failed.unmount()

    list([])
    const empty = await mountWithApp(BrandClientFilesPanel, props(true), { route })
    expect(empty.find('[data-test="brand-files-loading"]').exists()).toBe(true)
    await flushPromises()
    expect(empty.get('[data-test="brand-files-empty"]').text()).toBe('No client files yet.')
    empty.unmount()
  })

  it('download opens the signed URL', async () => {
    list()
    const open = vi.spyOn(window, 'open').mockReturnValue(null)
    const w = await mountWithApp(BrandClientFilesPanel, props(true), { route })
    await flushPromises()
    await w.get(`[data-test="brand-file-menu-${FILE_ID}"]`).trigger('click')
    await flushPromises()
    ;(body(`[data-test="brand-file-download-${FILE_ID}"]`) as HTMLElement).click()
    expect(open).toHaveBeenCalledWith(
      'https://files.example.test/brief.pdf?sig=1',
      '_blank',
      'noopener',
    )
    w.unmount()
    open.mockRestore()
  })

  it('delete asks for confirmation, then calls the API and drops the row', async () => {
    list()
    vi.mocked(brandFilesApi.delete).mockResolvedValue(undefined)
    const w = await mountWithApp(BrandClientFilesPanel, props(true), { route })
    await flushPromises()
    await w.get(`[data-test="brand-file-menu-${FILE_ID}"]`).trigger('click')
    await flushPromises()
    ;(body(`[data-test="brand-file-delete-${FILE_ID}"]`) as HTMLElement).click()
    await flushPromises()
    expect(brandFilesApi.delete).not.toHaveBeenCalled()
    expect(body('[data-test="brand-file-delete-dialog"]') ?? body('.v-dialog')).not.toBeNull()
    ;(body('[data-test="brand-file-delete-confirm"]') as HTMLElement).click()
    await flushPromises()
    expect(brandFilesApi.delete).toHaveBeenCalledWith(AGENCY_ULID, BRAND_ULID, FILE_ID)
    expect(w.find(`[data-test="brand-file-row-${FILE_ID}"]`).exists()).toBe(false)
    w.unmount()
  })

  it('staff see the list and Download, but no Upload, no drop zone and no Delete', async () => {
    list()
    const w = await mountWithApp(BrandClientFilesPanel, props(false), {
      route,
      role: 'agency_staff',
    })
    await flushPromises()
    expect(w.find('[data-test="brand-files-upload-btn"]').exists()).toBe(false)
    expect(w.find('[data-test="brand-files-dropzone"]').exists()).toBe(false)
    expect(w.find(`[data-test="brand-file-row-${FILE_ID}"]`).exists()).toBe(true)
    await w.get(`[data-test="brand-file-menu-${FILE_ID}"]`).trigger('click')
    await flushPromises()
    expect(body(`[data-test="brand-file-download-${FILE_ID}"]`)).not.toBeNull()
    expect(body(`[data-test="brand-file-delete-${FILE_ID}"]`)).toBeNull()
    w.unmount()
  })
})

describe('BrandFileUploadDialog (PL-1.1 W5)', () => {
  type Dialog = { uploadFiles: (f: File[], t?: string) => Promise<void> }
  const mountDialog = () =>
    mountWithApp(BrandFileUploadDialog, {
      modelValue: true,
      agencyId: AGENCY_ULID,
      brandId: BRAND_ULID,
    })

  it('runs init, the PUT, then complete, and emits uploaded', async () => {
    const w = await mountDialog()
    await (w.vm as unknown as Dialog).uploadFiles(
      [file('new.pdf', 'application/pdf', 5)],
      'research',
    )
    expect(brandFilesApi.initUpload).toHaveBeenCalledWith(AGENCY_ULID, BRAND_ULID, {
      mime_type: 'application/pdf',
      declared_bytes: 5,
      file_type: 'research',
    })
    expect(uploadToPresignedUrl).toHaveBeenCalledTimes(1)
    expect(brandFilesApi.completeUpload).toHaveBeenCalledWith(AGENCY_ULID, BRAND_ULID, {
      upload_id: 'up-1',
      original_filename: 'new.pdf',
      file_type: 'research',
    })
    expect(w.emitted('uploaded')).toHaveLength(1)
    w.unmount()
  })

  it('refuses a file over 100 MB and a wrong type before any bytes move or any call is made', async () => {
    const w = await mountDialog()
    await (w.vm as unknown as Dialog).uploadFiles([
      file('huge.pdf', 'application/pdf', 100 * 1024 * 1024 + 1),
      file('clip.mp4', 'video/mp4', 10),
    ])
    expect(brandFilesApi.initUpload).not.toHaveBeenCalled()
    expect(uploadToPresignedUrl).not.toHaveBeenCalled()
    expect(body('[data-test="brand-file-outcome-failed"]')?.textContent).toBeTruthy()
    expect(document.body.textContent).toContain('huge.pdf is larger than 100 MB.')
    expect(document.body.textContent).toContain('clip.mp4 is not a supported file type.')
    w.unmount()
  })

  it('a per-file failure does not stop the next file', async () => {
    vi.mocked(brandFilesApi.initUpload)
      .mockRejectedValueOnce(
        new ApiError({ status: 500, code: 'x', message: 'x', details: [], raw: null } as never),
      )
      .mockResolvedValueOnce({
        data: {
          upload_url: 'https://s3.example.test/put',
          upload_id: 'up-2',
          storage_path: 'k',
          expires_at: 'x',
          max_bytes: 1,
        },
      })
    const w = await mountDialog()
    await (w.vm as unknown as Dialog).uploadFiles([
      file('a.pdf', 'application/pdf'),
      file('b.pdf', 'application/pdf'),
    ])
    expect(document.body.textContent).toContain('a.pdf could not be uploaded. Try again.')
    expect(document.body.textContent).toContain('Uploaded b.pdf.')
    expect(w.emitted('uploaded')).toHaveLength(1)
    w.unmount()
  })
})
