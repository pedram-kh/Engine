<script setup lang="ts">
/**
 * PL-2.3 — the references of one concept: images (upload with progress, thumbnails, enlarge, remove) and links (typed
 * title and domain; https only, opened in a new tab with `rel="noopener noreferrer"`). The count and size limits are shown
 * before any byte moves, and a refused file produces a message, not a request.
 *
 * Each change here (an image added or removed, a link added, edited or removed) sends an Approved concept back to
 * Awaiting client; the first one asks (`useConfirmReset`), and Cancel changes nothing.
 */
import type {
  ConceptImage,
  ConceptLink,
  StrategyLimits,
  WaveConceptResource,
} from '@catalyst/api-client'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { strategyApi, type WaveScope } from '../api/strategy.api'
import { formatMegabytes, safeHref } from '../conceptsLogic'
import { useConfirmReset } from '../composables/confirmReset'
import { useConceptImageUpload } from '../composables/useConceptImageUpload'
import { strategyErrorMessage } from '../strategyErrors'
import ConceptImageTile from './ConceptImageTile.vue'
import ConceptImageViewer from './ConceptImageViewer.vue'
import ConceptLinkDialog from './ConceptLinkDialog.vue'

const props = defineProps<{
  concept: WaveConceptResource
  scope: WaveScope
  limits: StrategyLimits
}>()
const emit = defineEmits<{ changed: [] }>()

const { t } = useI18n()
const confirmReset = useConfirmReset()
const a = computed(() => props.concept.attributes)
const picker = ref<HTMLInputElement | null>(null)
const viewing = ref<ConceptImage | null>(null)
const linkOpen = ref(false)
const editingLink = ref<ConceptLink | null>(null)
const removing = ref<string | null>(null)
const error = ref<string | null>(null)
const dragging = ref(false)

const usedBytes = computed(() => a.value.images.reduce((n, i) => n + (i.size_bytes ?? 0), 0))
const imagesFull = computed(() => a.value.images.length >= props.limits.images_per_concept)
const linksFull = computed(() => a.value.links.length >= props.limits.links_per_concept)

const upload = useConceptImageUpload({
  scope: () => props.scope,
  conceptId: () => props.concept.id,
  used: () => ({ count: a.value.images.length, bytes: usedBytes.value }),
  limits: () => ({
    images: props.limits.images_per_concept,
    bytes: props.limits.image_total_bytes,
  }),
  t: (k, n) => t(k, n ?? {}),
  beforeStart: () => confirmReset(props.concept),
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

async function removeImage(image: ConceptImage): Promise<void> {
  error.value = null
  if (!(await confirmReset(props.concept))) return
  removing.value = image.id
  try {
    await strategyApi.removeImage(props.scope, props.concept.id, image.id)
    emit('changed')
  } catch (err) {
    error.value = strategyErrorMessage(err, t)
  } finally {
    removing.value = null
  }
}

async function openLinkDialog(link: ConceptLink | null): Promise<void> {
  error.value = null
  if (!(await confirmReset(props.concept))) return
  editingLink.value = link
  linkOpen.value = true
}
async function removeLink(link: ConceptLink): Promise<void> {
  error.value = null
  if (!(await confirmReset(props.concept))) return
  removing.value = link.id
  try {
    await strategyApi.removeLink(props.scope, props.concept.id, link.id)
    emit('changed')
  } catch (err) {
    error.value = strategyErrorMessage(err, t)
  } finally {
    removing.value = null
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
  () => a.value.images.map((i) => i.has_thumbnail).join(),
  () => {
    if (a.value.images.some((i) => !i.has_thumbnail)) schedule()
    else polls = 0
  },
  { immediate: true },
)
onBeforeUnmount(() => {
  if (timer !== null) clearTimeout(timer)
})
</script>

<template>
  <section class="refs" data-test="concept-references">
    <div class="refs__top">
      <div class="refs__title">
        <v-icon icon="mdi-image-outline" size="14" class="refs__title-icon" aria-hidden="true" />
        <span class="refs__title-text">{{ t('app.strategy.concepts.references.title') }}</span>
        <span class="refs__title-sub">{{ t('app.strategy.concepts.references.subtitle') }}</span>
      </div>
      <div class="refs__actions">
        <button
          type="button"
          class="refs__btn"
          :disabled="imagesFull"
          :aria-label="t('app.strategy.concepts.images.add')"
          data-test="concept-image-add"
          @click="choose"
        >
          <v-icon icon="mdi-tray-arrow-up" size="12" aria-hidden="true" />
          {{ t('app.strategy.concepts.references.image') }}
        </button>
        <button
          type="button"
          class="refs__btn"
          :disabled="linksFull"
          :aria-label="t('app.strategy.concepts.links.add')"
          data-test="concept-link-add"
          @click="openLinkDialog(null)"
        >
          <v-icon icon="mdi-link-variant" size="12" aria-hidden="true" />
          {{ t('app.strategy.concepts.references.link') }}
        </button>
        <input
          ref="picker"
          type="file"
          multiple
          accept="image/jpeg,image/png,image/webp"
          class="refs__picker"
          tabindex="-1"
          data-test="concept-image-input"
          @change="onPicked"
        />
      </div>
    </div>

    <p class="refs__counts">
      <span data-test="concept-image-count">
        {{
          t('app.strategy.concepts.images.count', {
            count: a.images.length,
            max: limits.images_per_concept,
            used: formatMegabytes(usedBytes),
            total: formatMegabytes(limits.image_total_bytes),
          })
        }}
      </span>
      <span aria-hidden="true"> · </span>
      <span data-test="concept-link-count">
        {{ t('app.strategy.concepts.links.label') }} {{ a.links.length }} /
        {{ limits.links_per_concept }}
      </span>
    </p>

    <div v-if="a.images.length > 0" class="refs__tiles">
      <ConceptImageTile
        v-for="img in a.images"
        :key="img.id"
        :image="img"
        :busy="removing === img.id"
        @open="viewing = $event"
        @remove="removeImage"
      />
    </div>
    <p v-if="imagesFull" class="refs__hint" data-test="concept-image-full">
      {{ t('app.strategy.concepts.images.full', { max: limits.images_per_concept }) }}
    </p>
    <ul v-if="upload.items.value.length > 0" class="refs__uploads" data-test="concept-uploads">
      <li
        v-for="u in upload.items.value"
        :key="u.key"
        class="refs__upload"
        data-test="concept-upload"
      >
        <span class="refs__upload-name">{{ u.name }}</span>
        <v-progress-linear
          v-if="u.state === 'uploading'"
          :model-value="u.progress"
          :indeterminate="u.progress === 0"
          height="4"
          rounded
          class="refs__upload-bar"
          :aria-label="t('app.strategy.concepts.upload.progress', { name: u.name })"
        />
        <template v-else>
          <span class="refs__upload-error" data-test="concept-upload-error">{{ u.error }}</span>
          <v-btn
            size="x-small"
            variant="text"
            data-test="concept-upload-retry"
            @click="upload.retry(u.key)"
          >
            {{ t('app.strategy.concepts.upload.retry') }}
          </v-btn>
          <v-btn
            size="x-small"
            variant="text"
            data-test="concept-upload-dismiss"
            @click="upload.dismiss(u.key)"
          >
            {{ t('app.strategy.concepts.upload.dismiss') }}
          </v-btn>
        </template>
      </li>
    </ul>
    <ul
      v-if="upload.notices.value.length > 0"
      class="refs__notices"
      data-test="concept-upload-notices"
    >
      <li v-for="(n, i) in upload.notices.value" :key="i">{{ n }}</li>
    </ul>

    <ul v-if="a.links.length > 0" class="refs__links">
      <li v-for="l in a.links" :key="l.id" class="refs__link" data-test="concept-link">
        <v-icon icon="mdi-link-variant" size="13" class="refs__link-icon" />
        <a
          v-if="safeHref(l.url)"
          :href="safeHref(l.url) ?? undefined"
          target="_blank"
          rel="noopener noreferrer"
          class="refs__link-title"
          data-test="concept-link-anchor"
          >{{ l.title }}</a
        >
        <span v-else class="refs__link-title" data-test="concept-link-text">{{ l.title }}</span>
        <span class="refs__link-domain">{{ l.domain }}</span>
        <v-spacer />
        <v-btn
          icon="mdi-pencil-outline"
          size="x-small"
          variant="text"
          density="comfortable"
          :aria-label="t('app.strategy.concepts.links.edit', { title: l.title })"
          data-test="concept-link-edit"
          @click="openLinkDialog(l)"
        />
        <v-btn
          icon="mdi-close"
          size="x-small"
          variant="text"
          density="comfortable"
          :disabled="removing === l.id"
          :aria-label="t('app.strategy.concepts.links.remove', { title: l.title })"
          data-test="concept-link-remove"
          @click="removeLink(l)"
        />
      </li>
    </ul>

    <button
      v-if="a.images.length === 0 && a.links.length === 0 && upload.items.value.length === 0"
      type="button"
      class="refs__drop"
      :class="{ 'refs__drop--over': dragging }"
      :disabled="imagesFull"
      data-test="concept-drop"
      @click="choose"
      @dragover.prevent="dragging = true"
      @dragleave="dragging = false"
      @drop.prevent="onDropped"
    >
      <v-icon icon="mdi-tray-arrow-up" size="14" aria-hidden="true" />
      {{ t('app.strategy.concepts.references.drop') }}
    </button>

    <p v-if="error" class="refs__error" role="alert" data-test="concept-references-error">
      {{ error }}
    </p>

    <ConceptLinkDialog
      v-model="linkOpen"
      :scope="scope"
      :concept="concept"
      :link="editingLink"
      :limits="limits"
      @saved="emit('changed')"
    />
    <ConceptImageViewer v-if="viewing" :image="viewing" @close="viewing = null" />
  </section>
</template>

<style scoped>
/*
 * Frame 13:4412, the references block of a card: 14 / 18 / 16 padding, a 10px gap, a top border; the title row is a 14px
 * image icon, "References" 13 / 600 and "images & links" 12 tertiary, with two 12px outlined buttons (radius 6, 9 / 4
 * padding, 12 / 500, the brand green) on the right; thumbnails 96 x 64 with the file name under them; links on a
 * surface-bright row (radius 8, 8 / 10 padding); the empty block shows a drop line. The count line is added (the limits
 * must show before any byte moves).
 */
.refs {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 14px 18px 16px;
  border-top: 1px solid rgb(var(--v-theme-border-color));
}
.refs__top {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
.refs__title {
  display: flex;
  align-items: center;
  gap: 6px;
  min-width: 0;
}
.refs__title-icon {
  color: rgb(var(--v-theme-on-surface-variant));
}
.refs__title-text {
  font-size: 13px;
  font-weight: 600;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface));
}
.refs__title-sub {
  font-size: 12px;
  line-height: normal;
  color: rgb(var(--v-theme-text-tertiary));
}
.refs__actions {
  display: flex;
  gap: 6px;
}
.refs__btn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 4px 9px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 6px;
  background: transparent;
  color: rgb(var(--v-theme-primary));
  font: inherit;
  font-size: 12px;
  font-weight: 500;
  line-height: normal;
  cursor: pointer;
}
.refs__btn:disabled {
  cursor: default;
  opacity: 0.5;
}
.refs__counts,
.refs__hint {
  margin: 0;
  font-size: 11.5px;
  line-height: normal;
  color: rgb(var(--v-theme-text-tertiary));
}
.refs__tiles {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}
.refs__picker {
  display: none;
}
.refs__drop {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 15px 10px;
  border: 1px dashed rgb(var(--v-theme-border-color));
  border-radius: 8px;
  background: transparent;
  color: rgb(var(--v-theme-text-tertiary));
  font: inherit;
  font-size: 12px;
  line-height: normal;
  cursor: pointer;
}
.refs__drop:disabled {
  cursor: default;
  opacity: 0.5;
}
.refs__drop--over {
  border-color: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-primary));
}
.refs__uploads,
.refs__notices,
.refs__links {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin: 0;
  padding: 0;
  list-style: none;
}
.refs__upload {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
}
.refs__upload-name {
  max-width: 40%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: rgb(var(--v-theme-on-surface));
}
.refs__upload-bar {
  flex: 1 1 0;
}
.refs__upload-error,
.refs__notices,
.refs__error {
  margin: 0;
  font-size: 12px;
  color: rgb(var(--v-theme-error-text));
  overflow-wrap: anywhere;
}
.refs__link {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
  padding: 8px 10px;
  border-radius: 8px;
  background: rgb(var(--v-theme-surface-bright));
  font-size: 13px;
  font-weight: 500;
  line-height: normal;
}
.refs__link-icon {
  flex: none;
  color: rgb(var(--v-theme-info));
}
.refs__link-title {
  min-width: 0;
  color: rgb(var(--v-theme-on-surface));
  text-decoration: none;
  overflow-wrap: anywhere;
}
a.refs__link-title:hover {
  text-decoration: underline;
}
.refs__link-domain {
  flex: none;
  font-size: 11.5px;
  font-weight: 400;
  color: rgb(var(--v-theme-text-tertiary));
}
</style>
