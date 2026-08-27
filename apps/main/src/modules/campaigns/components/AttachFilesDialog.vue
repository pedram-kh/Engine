<script setup lang="ts">
/**
 * Campaign attachments from the Files library, chunk B (AH-092) — the attach
 * picker, `InviteCreatorsDialog`'s multi-select-then-loop shape (D6's ratified
 * scope fence: multi-select UI over a bulk endpoint — v1 ships no bulk-attach
 * route, so a multi-file pick still means N `store()` calls under the hood).
 *
 * The source list is the picker's own thin, unpaginated projection
 * (`?for=select` — `AgencyFileOptionResource`, S3), filtered client-side by a
 * search box: unlike the roster (which can run into the thousands and needs
 * server-side search), an agency's Files library is realistically small
 * enough that one fetch + a local filter never becomes the correctness bug
 * `InviteCreatorsDialog`'s doc comment names for THAT picker's larger source.
 *
 * Already-attached files are shown, not hidden — disabled with a badge — so
 * re-opening the picker after a partial attach doesn't look like the file
 * vanished from the library.
 */

import { formatFileSize } from '@catalyst/api-client'
import type { AgencyFileOptionResource } from '@catalyst/api-client'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { filesApi } from '@/modules/files/api/files.api'
import { campaignsApi } from '../api/campaigns.api'

const props = defineProps<{
  modelValue: boolean
  agencyId: string
  campaignId: string
  /** The file ulids already attached — disables their picker row. */
  attachedFileIds: string[]
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  attached: [message: string]
}>()

const { t } = useI18n()

const files = ref<AgencyFileOptionResource[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const submitting = ref(false)
const search = ref<string | null>('')
const selected = ref<Set<string>>(new Set())
const libraryEmpty = ref(false)

const attachedSet = computed(() => new Set(props.attachedFileIds))

const searchTerm = computed(() => (search.value ?? '').trim().toLowerCase())

const visibleFiles = computed(() => {
  if (searchTerm.value === '') return files.value
  return files.value.filter((f) =>
    f.attributes.original_filename.toLowerCase().includes(searchTerm.value),
  )
})

const canSubmit = computed(() => selected.value.size > 0 && !submitting.value)

function isAttached(file: AgencyFileOptionResource): boolean {
  return attachedSet.value.has(file.id)
}

function toggleSelect(file: AgencyFileOptionResource): void {
  if (isAttached(file)) return
  const next = new Set(selected.value)
  if (next.has(file.id)) next.delete(file.id)
  else next.add(file.id)
  selected.value = next
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  selected.value = new Set()
  search.value = ''
  try {
    const res = await filesApi.listOptions(props.agencyId)
    files.value = res.data
    libraryEmpty.value = res.data.length === 0
  } catch {
    error.value = t('app.campaigns.attachments.picker.loadFailed')
    files.value = []
    libraryEmpty.value = false
  } finally {
    loading.value = false
  }
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) void load()
  },
  { immediate: true },
)

function close(): void {
  emit('update:modelValue', false)
}

async function submit(): Promise<void> {
  if (!canSubmit.value) return
  submitting.value = true
  error.value = null

  const ids = [...selected.value]
  let ok = 0
  for (const id of ids) {
    try {
      await campaignsApi.attachFile(props.agencyId, props.campaignId, { agency_file_id: id })
      ok++
    } catch {
      // One failed attach does not abort the batch — the rest still land,
      // mirroring InviteCreatorsDialog's per-item aggregate loop.
    }
  }

  submitting.value = false

  if (ok === 0) {
    error.value = t('app.campaigns.attachments.errors.attachFailed')
    return
  }

  emit('attached', t('app.campaigns.attachments.picker.attachedSummary', { count: ok }))
  emit('update:modelValue', false)
}
</script>

<template>
  <v-dialog
    :model-value="modelValue"
    max-width="520"
    data-test="attach-files-dialog"
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <v-card>
      <v-card-title class="text-h6 pa-4 d-flex align-center justify-space-between">
        {{ t('app.campaigns.attachments.picker.title') }}
        <v-btn
          icon="mdi-close"
          variant="text"
          size="small"
          data-test="attach-files-close"
          @click="close"
        />
      </v-card-title>

      <v-card-text>
        <v-alert
          v-if="error"
          type="error"
          variant="tonal"
          class="mb-3"
          data-test="attach-files-error"
        >
          {{ error }}
        </v-alert>

        <v-text-field
          v-if="!loading && !libraryEmpty"
          v-model="search"
          density="compact"
          variant="outlined"
          hide-details
          clearable
          prepend-inner-icon="mdi-magnify"
          class="mb-3"
          :label="t('app.campaigns.attachments.picker.search')"
          data-test="attach-files-search"
        />

        <v-skeleton-loader
          v-if="loading"
          type="list-item-two-line@3"
          data-test="attach-files-skeleton"
        />

        <div
          v-else-if="libraryEmpty"
          class="text-body-2 text-medium-emphasis py-4"
          data-test="attach-files-no-library"
        >
          {{ t('app.campaigns.attachments.picker.noFiles') }}
        </div>

        <div
          v-else-if="visibleFiles.length === 0"
          class="text-body-2 text-medium-emphasis py-4"
          data-test="attach-files-no-match"
        >
          {{ t('app.campaigns.attachments.picker.noMatch') }}
        </div>

        <v-list v-else data-test="attach-files-list">
          <v-list-item
            v-for="file in visibleFiles"
            :key="file.id"
            :disabled="isAttached(file)"
            :data-test="`attach-files-row-${file.id}`"
            @click="toggleSelect(file)"
          >
            <template #prepend>
              <v-icon icon="mdi-file-outline" size="20" color="medium-emphasis" class="mr-3" />
            </template>
            <v-list-item-title>{{ file.attributes.original_filename }}</v-list-item-title>
            <v-list-item-subtitle>
              <span v-if="isAttached(file)" :data-test="`attach-files-already-${file.id}`">
                {{ t('app.campaigns.attachments.picker.alreadyAttached') }}
              </span>
              <span v-else>{{ formatFileSize(file.attributes.size_bytes) }}</span>
            </v-list-item-subtitle>
            <template #append>
              <v-checkbox-btn
                :model-value="selected.has(file.id)"
                :disabled="isAttached(file)"
                :data-test="`attach-files-checkbox-${file.id}`"
                @click.stop="toggleSelect(file)"
              />
            </template>
          </v-list-item>
        </v-list>
      </v-card-text>

      <v-card-actions class="px-4 pb-4">
        <v-spacer />
        <v-btn variant="text" data-test="attach-files-cancel" @click="close">
          {{ t('app.campaigns.attachments.picker.cancel') }}
        </v-btn>
        <v-btn
          color="primary"
          variant="flat"
          :disabled="!canSubmit"
          :loading="submitting"
          data-test="attach-files-submit"
          @click="submit"
        >
          {{ t('app.campaigns.attachments.picker.submit', { count: selected.size }) }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
