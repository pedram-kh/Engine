<script setup lang="ts">
/**
 * PL-3.2b — the "Reference" row of a hook and of a line (frame 13:3779): the image icon and label in the 112 px label column, then
 * the thumbnails with an "Add" tile, or, while the block has none, a dashed drop zone. Drag and drop (several files at once) or
 * browse; JPG, PNG, WebP and GIF; progress for each file, a failed file keeps its line with Retry; click an image to enlarge it
 * (Escape closes it and focus returns to that image); remove asks first. The limits (10 per block, 300 MB per script) are checked
 * before a byte moves and say why in plain words; the usage shows only near a limit.
 *
 * Reused from the concept references: `useAuthenticatedImage` (the thumbnail reads), `ConceptImageViewer` (the enlarged view and its
 * download), `StrategyConfirmDialog`, `formatMegabytes`. New: the tile (frame sizes), the upload composable (GIF, script-wide bytes).
 */
import type { ScriptImage } from '@catalyst/api-client'
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { strategyApi, type WaveScope } from '../api/strategy.api'
import { formatMegabytes } from '../conceptsLogic'
import { useScriptImageUpload } from '../composables/useScriptImageUpload'
import { IMAGE_BYTES_NEAR, IMAGES_NEAR, nearLimit } from '../scriptsLogic'
import { strategyErrorMessage } from '../strategyErrors'
import ConceptImageViewer from './ConceptImageViewer.vue'
import ScriptImageTile from './ScriptImageTile.vue'
import StrategyConfirmDialog from './StrategyConfirmDialog.vue'

const props = defineProps<{
  scope: WaveScope
  scriptId: string
  block: 'hook' | 'line'
  blockId: string
  images: ScriptImage[]
  /** The bytes of every image of the script (the 300 MB is per script). */
  scriptBytes: number
  limits: { images: number; bytes: number }
  /** The id prefix of this block's tests and labels. */
  testId: string
}>()
const emit = defineEmits<{
  /** An image was added or removed: the editor reads the script again. */
  changed: []
}>()

const { t } = useI18n()
const picker = ref<HTMLInputElement | null>(null)
const dragging = ref(false)
const viewing = ref<ScriptImage | null>(null)
const removing = ref<ScriptImage | null>(null)
const removeBusy = ref(false)
const removeError = ref<string | null>(null)

const full = computed(() => props.images.length >= props.limits.images)
const showUsage = computed(
  () =>
    nearLimit(props.images.length, props.limits.images, IMAGES_NEAR) ||
    props.scriptBytes >= props.limits.bytes * IMAGE_BYTES_NEAR,
)

const upload = useScriptImageUpload({
  scope: () => props.scope,
  scriptId: () => props.scriptId,
  block: () => props.block,
  blockId: () => props.blockId,
  used: () => ({ blockCount: props.images.length, scriptBytes: props.scriptBytes }),
  limits: () => props.limits,
  t: (k, n) => t(k, n ?? {}),
  onUploaded: () => emit('changed'),
})

function choose(): void {
  upload.clearNotices()
  picker.value?.click()
}
async function onDropped(e: DragEvent): Promise<void> {
  dragging.value = false
  const files = Array.from(e.dataTransfer?.files ?? [])
  upload.clearNotices()
  if (files.length > 0) await upload.add(files)
}
async function onPicked(e: Event): Promise<void> {
  const input = e.target as HTMLInputElement
  const files = Array.from(input.files ?? [])
  input.value = ''
  if (files.length > 0) await upload.add(files)
}

// Enlarge: Escape closes the viewer (Vuetify), and focus goes back to the image that was opened.
function openViewer(image: ScriptImage): void {
  viewing.value = image
}
async function closeViewer(): Promise<void> {
  const id = viewing.value?.id
  viewing.value = null
  await nextTick()
  if (id !== undefined) {
    document.querySelector<HTMLElement>(`[data-image-open="${id}"]`)?.focus()
  }
}

function askRemove(image: ScriptImage): void {
  removeError.value = null
  removing.value = image
}
const removeOpen = computed({
  get: () => removing.value !== null,
  set: (v: boolean) => {
    if (!v) removing.value = null
  },
})
async function doRemove(): Promise<void> {
  const image = removing.value
  if (image === null || removeBusy.value) return
  removeBusy.value = true
  removeError.value = null
  try {
    await strategyApi.deleteScriptImage(props.scope, props.scriptId, image.id)
    removing.value = null
    emit('changed')
  } catch (err) {
    removeError.value = strategyErrorMessage(err, t)
  } finally {
    removeBusy.value = false
  }
}

// A thumbnail is made by a queued job: until it is ready the tile shows its placeholder. Look again a few times.
let polls = 0
let timer: ReturnType<typeof setTimeout> | null = null
function schedule(): void {
  if (timer !== null || polls >= 3) return
  timer = setTimeout(() => {
    timer = null
    polls += 1
    emit('changed')
  }, 3000)
}
watch(
  () => props.images.map((i) => i.has_thumbnail).join(),
  () => {
    if (props.images.some((i) => !i.has_thumbnail)) schedule()
    else polls = 0
  },
  { immediate: true },
)
onBeforeUnmount(() => {
  if (timer !== null) clearTimeout(timer)
})
</script>

<template>
  <div
    class="sref"
    :data-test="`${testId}-references`"
    @dragover.prevent="dragging = !full"
    @dragleave="dragging = false"
    @drop.prevent="onDropped"
  >
    <div class="sref__label">
      <v-icon icon="mdi-image-outline" size="13" aria-hidden="true" />{{
        t('app.strategy.scripts.images.label')
      }}
    </div>
    <div class="sref__field">
      <div v-if="images.length > 0 || upload.items.value.length > 0" class="sref__tiles">
        <ScriptImageTile
          v-for="img in images"
          :key="img.id"
          :image="img"
          :busy="removeBusy && removing?.id === img.id"
          @open="openViewer"
          @remove="askRemove"
        />
        <button
          v-if="!full"
          type="button"
          class="sref__add"
          :class="{ 'sref__add--over': dragging }"
          :data-test="`${testId}-image-add`"
          @click="choose"
        >
          <v-icon icon="mdi-plus" size="16" aria-hidden="true" />
          <span>{{ t('app.strategy.scripts.images.add') }}</span>
        </button>
      </div>
      <button
        v-else
        type="button"
        class="sref__drop"
        :class="{ 'sref__drop--over': dragging }"
        :data-test="`${testId}-image-drop`"
        @click="choose"
      >
        <span class="sref__drop-title">{{ t('app.strategy.scripts.images.drop') }}</span>
        <span class="sref__drop-hint">{{
          t('app.strategy.scripts.images.hint', { max: limits.images })
        }}</span>
      </button>
      <input
        ref="picker"
        type="file"
        multiple
        accept="image/jpeg,image/png,image/webp,image/gif"
        class="sref__picker"
        tabindex="-1"
        :data-test="`${testId}-image-input`"
        @change="onPicked"
      />

      <ul v-if="upload.items.value.length > 0" class="sref__uploads">
        <li
          v-for="u in upload.items.value"
          :key="u.key"
          class="sref__upload"
          :data-test="`${testId}-upload`"
        >
          <span class="sref__upload-name">{{ u.name }}</span>
          <v-progress-linear
            v-if="u.state === 'uploading'"
            :model-value="u.progress"
            :indeterminate="u.progress === 0"
            height="4"
            rounded
            class="sref__upload-bar"
            :aria-label="t('app.strategy.scripts.images.progress', { name: u.name })"
          />
          <template v-else>
            <span class="sref__upload-error" role="alert" :data-test="`${testId}-upload-error`">{{
              u.error
            }}</span>
            <button
              type="button"
              class="sref__link"
              :data-test="`${testId}-upload-retry`"
              @click="upload.retry(u.key)"
            >
              {{ t('app.strategy.scripts.images.retry') }}
            </button>
            <button
              type="button"
              class="sref__link"
              :data-test="`${testId}-upload-dismiss`"
              @click="upload.dismiss(u.key)"
            >
              {{ t('app.strategy.close') }}
            </button>
          </template>
        </li>
      </ul>

      <ul
        v-if="upload.notices.value.length > 0"
        class="sref__notices"
        role="alert"
        :data-test="`${testId}-upload-notices`"
      >
        <li v-for="(n, i) in upload.notices.value" :key="i">{{ n }}</li>
      </ul>

      <p v-if="full" class="sref__usage" :data-test="`${testId}-image-full`">
        {{ t('app.strategy.scripts.images.full', { max: limits.images }) }}
      </p>
      <p v-else-if="showUsage" class="sref__usage" :data-test="`${testId}-image-usage`">
        {{
          t('app.strategy.scripts.images.usage', {
            count: images.length,
            max: limits.images,
            used: formatMegabytes(scriptBytes),
            total: formatMegabytes(limits.bytes),
          })
        }}
      </p>
    </div>

    <ConceptImageViewer v-if="viewing" :image="viewing" @close="closeViewer" />
    <StrategyConfirmDialog
      v-model="removeOpen"
      :title="t('app.strategy.scripts.images.removeTitle')"
      :body="
        t('app.strategy.scripts.images.removeBody', { name: removing?.original_filename ?? '' })
      "
      :confirm-label="t('app.strategy.scripts.images.removeConfirm')"
      :busy="removeBusy"
      :error="removeError"
      :test-id="`${testId}-image-remove-dialog`"
      @confirm="doRemove"
    />
  </div>
</template>

<style scoped>
.sref {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  min-width: 0;
}
.sref__label {
  display: flex;
  align-items: center;
  flex: none;
  gap: 6px;
  width: 112px;
  padding-top: 2px;
  font-size: 12px;
  font-weight: 500;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface-variant));
}
.sref__field {
  display: flex;
  flex: 1 1 0;
  flex-direction: column;
  gap: 8px;
  min-width: 0;
}
.sref__tiles {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 10px;
}
.sref__picker {
  display: none;
}
.sref__add {
  display: flex;
  flex: none;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  width: 68px;
  height: 68px;
  padding: 0;
  border: 1px dashed rgb(var(--v-theme-outline));
  border-radius: 6px;
  background: transparent;
  font: inherit;
  font-size: 11px;
  font-weight: 500;
  line-height: normal;
  color: rgb(var(--v-theme-primary));
  cursor: pointer;
}
.sref__add--over,
.sref__drop--over {
  border-color: rgb(var(--v-theme-primary));
  background: color-mix(in srgb, rgb(var(--v-theme-primary)) 8%, transparent);
}
.sref__drop {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 3px;
  min-height: 58px;
  padding: 14px 12px;
  border: 1px dashed rgb(var(--v-theme-outline));
  border-radius: 8px;
  background: color-mix(in srgb, rgb(var(--v-theme-surface-bright)) 40%, transparent);
  font: inherit;
  cursor: pointer;
}
.sref__drop-title {
  font-size: 13px;
  font-weight: 500;
  line-height: normal;
  color: rgb(var(--v-theme-primary));
}
.sref__drop-hint {
  font-size: 11.5px;
  line-height: normal;
  text-align: center;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
}
.sref__add:focus-visible,
.sref__drop:focus-visible,
.sref__link:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 1px;
}
.sref__uploads,
.sref__notices {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin: 0;
  padding: 0;
  list-style: none;
}
.sref__upload {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface-variant));
}
.sref__upload-name {
  max-width: 220px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.sref__upload-bar {
  flex: 1 1 120px;
}
.sref__upload-error,
.sref__notices {
  font-size: 12px;
  line-height: normal;
  color: rgb(var(--v-theme-error));
}
.sref__link {
  padding: 0;
  border: 0;
  background: none;
  font: inherit;
  font-size: 12px;
  text-decoration: underline;
  color: rgb(var(--v-theme-on-surface));
  cursor: pointer;
}
.sref__usage {
  margin: 0;
  font-size: 11.5px;
  line-height: normal;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
}
@media (max-width: 599px) {
  .sref {
    flex-direction: column;
    align-items: stretch;
    gap: 4px;
  }
  .sref__label {
    width: auto;
  }
}
</style>
