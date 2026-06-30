<script setup lang="ts">
/**
 * AH-010b — the WhatsApp-shaped relationship thread (1:1 connected
 * agency↔creator DM). Surface-agnostic: a parent (the agency or creator thread
 * page) supplies a pre-bound {@link RelationshipChatTransport} + the
 * counterparty header, and this view drives the feed via the generic
 * {@link useMessageThread} engine (the same 15s poll / merge / paging used by
 * campaign messaging — D7 reuse).
 *
 * Bubbles: own right, theirs left. Incoming bubbles carry the per-message sender
 * name (Q4 — the creator sees which agency member wrote each line). Own bubbles
 * carry the two-state read tick driven by `read_by_counterparty` (D10 — read
 * STATE comes from the server, never a client guess). Attachments are files
 * (signed view URL) or links (external http/https), per D4.
 */

import { ApiError, extractFieldErrors, uploadToPresignedUrl } from '@catalyst/api-client'
import type {
  RelationshipMessageResource,
  RelationshipThreadMeta,
  SendMessageAttachment,
  SendRelationshipLink,
  SendRelationshipMessagePayload,
} from '@catalyst/api-client'
import { computed, nextTick, ref, toRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { RouteLocationRaw } from 'vue-router'
import { useDisplay } from 'vuetify'

import type { RelationshipChatTransport } from '../api/relationshipMessaging.api'
import { useMessageThread } from '../composables/useMessageThread'

const props = defineProps<{
  transport: RelationshipChatTransport | null
  /** The counterparty label shown in the thread header. */
  title: string
  avatarText?: string
  avatarUrl?: string | null
  /**
   * When set, a back chevron renders before the avatar and navigates here. The
   * parent supplies it only when a back affordance is wanted (mobile single-pane);
   * on desktop two-pane it's omitted, so the chevron is hidden.
   */
  backTo?: RouteLocationRaw | null
}>()

const { t, locale } = useI18n()
const { smAndDown } = useDisplay()

// Enter-to-send is a DESKTOP affordance. On mobile the on-screen keyboard's
// return key must insert a newline (the native textarea behaviour) — sending is
// the explicit send icon — so Enter is only intercepted on larger screens.
const isMobile = computed(() => smAndDown.value)

const transportRef = toRef(props, 'transport')
const { messages, hasMore, loading, loadingOlder, sending, loadError, loadOlder, sendMessage } =
  useMessageThread<
    RelationshipMessageResource,
    RelationshipThreadMeta,
    SendRelationshipMessagePayload
  >(transportRef)

// Stick-to-bottom: the feed is the scroll container. We jump to the newest
// message on the first load and whenever messages arrive — but only when the
// viewer is already near the bottom (or it's their own send), so an incoming
// poll never yanks them down while they're reading older history.
const feedEl = ref<HTMLElement | null>(null)
const NEAR_BOTTOM_PX = 120

function isNearBottom(): boolean {
  const el = feedEl.value
  if (el === null) {
    return true
  }
  return el.scrollHeight - el.scrollTop - el.clientHeight < NEAR_BOTTOM_PX
}

function scrollToBottom(): void {
  const el = feedEl.value
  if (el !== null) {
    el.scrollTop = el.scrollHeight
  }
}

// Keyed on the *last* message id so a "load earlier" prepend (tail unchanged)
// never scrolls — only a genuinely-new tail message (send / incoming) can.
let prevLastId: string | null = null
watch(
  messages,
  (list) => {
    const len = list.length
    if (len === 0) {
      prevLastId = null
      return
    }
    const last = list[len - 1]
    const lastId = last?.id ?? null
    const tailChanged = lastId !== prevLastId
    const isInitialLoad = prevLastId === null
    const lastIsOwn = last?.attributes.is_own === true
    // isNearBottom() reads the pre-update DOM (the watcher runs before re-render).
    if (tailChanged && (isInitialLoad || lastIsOwn || isNearBottom())) {
      void nextTick(scrollToBottom)
    }
    prevLastId = lastId
  },
  { immediate: true },
)

type ComposeField = 'body' | 'attachments' | 'links'

const body = ref('')
const selectedFiles = ref<File[]>([])
const links = ref<SendRelationshipLink[]>([])
const linkUrl = ref('')
const linkName = ref('')
const linkError = ref<string | null>(null)
const uploading = ref(false)

// The "+" attach menu (file / link) below the composer, and the link dialog.
const attachOpen = ref(false)
const linkDialogOpen = ref(false)
const generalError = ref<string | null>(null)
const fieldErrors = ref<Partial<Record<ComposeField, readonly string[]>>>({})

const fileInput = ref<HTMLInputElement | null>(null)

const timeFormatter = computed(
  () => new Intl.DateTimeFormat(locale.value, { hour: '2-digit', minute: '2-digit' }),
)

const canSend = computed(
  () =>
    !sending.value &&
    !uploading.value &&
    (body.value.trim() !== '' || selectedFiles.value.length > 0 || links.value.length > 0),
)

function onComposerKeydown(event: KeyboardEvent): void {
  // Mobile: never intercept — let the soft-keyboard return insert a newline.
  if (isMobile.value) {
    return
  }
  // Desktop: Enter sends, Shift+Enter inserts a newline; ignore IME composition.
  if (event.key !== 'Enter' || event.shiftKey || event.isComposing) {
    return
  }
  event.preventDefault()
  void submit()
}

function formatTime(iso: string): string {
  const date = new Date(iso)
  return Number.isNaN(date.getTime()) ? '' : timeFormatter.value.format(date)
}

function senderLabel(message: RelationshipMessageResource): string {
  return message.attributes.sender?.name ?? t('app.messaging.participant')
}

/**
 * The per-message sender label disambiguates WHICH agency member wrote each line
 * (an agency has many members — the Q4 intent). It is only meaningful on
 * agency-authored bubbles: a creator is a single person, so labelling their
 * incoming messages with their raw account name is noise (and confusingly
 * differs from the thread's display-name title). So: show the label only on
 * incoming AGENCY-member bubbles.
 */
function showSenderLabel(message: RelationshipMessageResource): boolean {
  return !message.attributes.is_own && message.attributes.sender_role === 'agency_user'
}

function onFilesPicked(event: Event): void {
  const target = event.target as HTMLInputElement
  selectedFiles.value = target.files !== null ? Array.from(target.files) : []
}

/** The "+" menu actions: open the OS file picker, or the link dialog. */
function openFilePicker(): void {
  fileInput.value?.click()
  attachOpen.value = false
}

function openLinkDialog(): void {
  linkError.value = null
  linkDialogOpen.value = true
}

function removeFile(index: number): void {
  selectedFiles.value = selectedFiles.value.filter((_, i) => i !== index)
}

function addLink(): void {
  linkError.value = null
  const url = linkUrl.value.trim()
  if (!/^https?:\/\//i.test(url)) {
    linkError.value = t('app.messaging.relationship.linkInvalid')
    return
  }
  const name = linkName.value.trim()
  links.value = [...links.value, name === '' ? { url } : { url, name }]
  linkUrl.value = ''
  linkName.value = ''
  linkDialogOpen.value = false
  attachOpen.value = false
}

function removeLink(index: number): void {
  links.value = links.value.filter((_, i) => i !== index)
}

async function uploadAttachments(): Promise<SendMessageAttachment[]> {
  const client = props.transport
  if (client === null || selectedFiles.value.length === 0) {
    return []
  }
  const uploaded: SendMessageAttachment[] = []
  for (const file of selectedFiles.value) {
    const init = await client.attachmentInit({ mime_type: file.type, size_bytes: file.size })
    await uploadToPresignedUrl(init.data.upload_url, file, { contentType: file.type })
    uploaded.push({
      upload_id: init.data.upload_id,
      mime_type: file.type,
      name: file.name,
      size_bytes: file.size,
    })
  }
  return uploaded
}

async function submit(): Promise<void> {
  if (!canSend.value) {
    return
  }
  generalError.value = null
  fieldErrors.value = {}

  let attachments: SendMessageAttachment[] = []
  if (selectedFiles.value.length > 0) {
    uploading.value = true
    try {
      attachments = await uploadAttachments()
    } catch {
      generalError.value = t('app.messaging.attachmentUploadError')
      return
    } finally {
      uploading.value = false
    }
  }

  const payload: SendRelationshipMessagePayload = {}
  const trimmed = body.value.trim()
  if (trimmed !== '') {
    payload.body = trimmed
  }
  if (attachments.length > 0) {
    payload.attachments = attachments
  }
  if (links.value.length > 0) {
    payload.links = [...links.value]
  }

  try {
    await sendMessage(payload)
    body.value = ''
    selectedFiles.value = []
    links.value = []
    if (fileInput.value !== null) {
      fileInput.value.value = ''
    }
  } catch (err) {
    if (err instanceof ApiError) {
      fieldErrors.value = extractFieldErrors<ComposeField>(err)
      if (Object.keys(fieldErrors.value).length === 0) {
        generalError.value = err.message
      }
    } else {
      generalError.value = t('app.messaging.sendError')
    }
  }
}
</script>

<template>
  <div class="rel-thread" data-test="relationship-thread">
    <header class="rel-thread__header">
      <v-btn
        v-if="backTo"
        :to="backTo"
        icon="mdi-chevron-left"
        variant="text"
        density="comfortable"
        class="rel-thread__back"
        :aria-label="t('app.messaging.relationship.back')"
        data-test="relationship-thread-back"
      />
      <v-avatar size="40" color="primary" class="rel-thread__avatar">
        <v-img v-if="avatarUrl" :src="avatarUrl" :alt="title" />
        <span v-else class="text-body-2 font-weight-bold text-white">
          {{ (avatarText ?? title ?? '?')[0]?.toUpperCase() }}
        </span>
      </v-avatar>
      <span class="rel-thread__title" data-test="relationship-thread-title">{{ title }}</span>
    </header>

    <div ref="feedEl" class="rel-thread__feed" data-test="relationship-thread-feed">
      <v-skeleton-loader v-if="loading" type="list-item-three-line@3" />

      <v-alert v-else-if="loadError" type="error" variant="tonal" density="compact">
        {{ t('app.messaging.loadError') }}
      </v-alert>

      <template v-else>
        <div v-if="hasMore" class="rel-thread__load-earlier">
          <v-btn
            size="small"
            variant="text"
            :loading="loadingOlder"
            data-test="relationship-load-earlier"
            @click="loadOlder"
          >
            {{ t('app.messaging.loadEarlier') }}
          </v-btn>
        </div>

        <p
          v-if="messages.length === 0"
          class="rel-thread__empty"
          data-test="relationship-thread-empty"
        >
          {{ t('app.messaging.relationship.threadEmpty') }}
        </p>

        <ul class="rel-thread__messages">
          <li
            v-for="message in messages"
            :key="message.id"
            class="rel-bubble-row"
            :class="{ 'rel-bubble-row--own': message.attributes.is_own }"
            :data-test="`relationship-message-${message.id}`"
          >
            <div class="rel-bubble">
              <div
                v-if="showSenderLabel(message)"
                class="rel-bubble__sender"
                data-test="relationship-message-sender"
              >
                {{ senderLabel(message) }}
              </div>

              <p v-if="message.attributes.body" class="rel-bubble__body">
                {{ message.attributes.body }}
              </p>

              <ul v-if="message.attributes.attachments.length > 0" class="rel-bubble__attachments">
                <li
                  v-for="(att, i) in message.attributes.attachments"
                  :key="i"
                  :data-test="`relationship-attachment-${att.kind}`"
                >
                  <a
                    v-if="att.kind === 'link' && att.url"
                    :href="att.url"
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    <v-icon icon="mdi-link-variant" size="x-small" class="mr-1" />
                    {{ att.name ?? att.url }}
                  </a>
                  <a
                    v-else-if="att.kind === 'file' && att.view_url"
                    :href="att.view_url"
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    <v-icon icon="mdi-paperclip" size="x-small" class="mr-1" />
                    {{ att.name ?? t('app.messaging.attachment') }}
                  </a>
                  <span v-else>
                    <v-icon icon="mdi-paperclip" size="x-small" class="mr-1" />
                    {{ att.name ?? t('app.messaging.attachment') }}
                  </span>
                </li>
              </ul>

              <div class="rel-bubble__meta">
                <span class="rel-bubble__time">{{
                  formatTime(message.attributes.created_at)
                }}</span>
                <v-icon
                  v-if="message.attributes.is_own"
                  :icon="message.attributes.read_by_counterparty ? 'mdi-check-all' : 'mdi-check'"
                  size="x-small"
                  :class="[
                    'rel-bubble__tick',
                    { 'rel-bubble__tick--read': message.attributes.read_by_counterparty },
                  ]"
                  :aria-label="
                    message.attributes.read_by_counterparty
                      ? t('app.messaging.relationship.read')
                      : t('app.messaging.relationship.sent')
                  "
                  :data-test="`relationship-tick-${message.attributes.read_by_counterparty ? 'read' : 'sent'}`"
                />
              </div>
            </div>
          </li>
        </ul>
      </template>
    </div>

    <form class="rel-thread__compose" data-test="relationship-compose" @submit.prevent="submit">
      <ul
        v-if="selectedFiles.length > 0"
        class="rel-thread__pending"
        data-test="relationship-pending-files"
      >
        <li v-for="(file, i) in selectedFiles" :key="i">
          <v-icon icon="mdi-paperclip" size="x-small" class="mr-1" />
          {{ file.name }}
          <v-btn icon="mdi-close" size="x-small" variant="text" @click="removeFile(i)" />
        </li>
      </ul>

      <ul
        v-if="links.length > 0"
        class="rel-thread__pending"
        data-test="relationship-pending-links"
      >
        <li v-for="(link, i) in links" :key="i">
          <v-icon icon="mdi-link-variant" size="x-small" class="mr-1" />
          {{ link.name ?? link.url }}
          <v-btn icon="mdi-close" size="x-small" variant="text" @click="removeLink(i)" />
        </li>
      </ul>

      <p v-if="fieldErrors.attachments" class="rel-thread__error">
        {{ (fieldErrors.attachments as string[]).join(' ') }}
      </p>
      <p v-if="fieldErrors.links" class="rel-thread__error">
        {{ (fieldErrors.links as string[]).join(' ') }}
      </p>
      <p v-if="generalError" class="rel-thread__error" data-test="relationship-general-error">
        {{ generalError }}
      </p>

      <div class="rel-thread__compose-row">
        <v-btn
          icon="mdi-plus"
          variant="text"
          density="comfortable"
          class="rel-thread__attach-btn"
          :class="{ 'rel-thread__attach-btn--active': attachOpen }"
          :aria-label="t('app.messaging.attachment')"
          data-test="relationship-attach-toggle"
          @click="attachOpen = !attachOpen"
        />

        <v-textarea
          v-model="body"
          :placeholder="t('app.messaging.composePlaceholder')"
          :error-messages="fieldErrors.body as string[]"
          rows="1"
          auto-grow
          max-rows="5"
          density="compact"
          variant="outlined"
          hide-details="auto"
          class="rel-thread__input"
          data-test="relationship-compose-body"
          @keydown="onComposerKeydown"
        >
          <template #append-inner>
            <v-btn
              icon="mdi-send"
              variant="text"
              size="small"
              color="primary"
              :loading="sending || uploading"
              :disabled="!canSend"
              :aria-label="t('app.messaging.send')"
              data-test="relationship-send"
              @click="submit"
            />
          </template>
        </v-textarea>
      </div>

      <div v-if="attachOpen" class="rel-thread__attach-menu" data-test="relationship-attach-menu">
        <v-btn
          icon="mdi-paperclip"
          variant="tonal"
          size="small"
          :aria-label="t('app.messaging.attachment')"
          data-test="relationship-attach-file"
          @click="openFilePicker"
        />
        <v-btn
          icon="mdi-link-variant"
          variant="tonal"
          size="small"
          :aria-label="t('app.messaging.relationship.addLink')"
          data-test="relationship-attach-link"
          @click="openLinkDialog"
        />
      </div>

      <input
        ref="fileInput"
        type="file"
        multiple
        class="rel-thread__file-input"
        data-test="relationship-file-input"
        @change="onFilesPicked"
      />

      <v-dialog v-model="linkDialogOpen" max-width="420" data-test="relationship-link-dialog">
        <v-card>
          <v-card-text class="rel-thread__link-form">
            <v-text-field
              v-model="linkUrl"
              :label="t('app.messaging.relationship.linkUrl')"
              :error-messages="linkError ? [linkError] : []"
              density="comfortable"
              variant="outlined"
              hide-details="auto"
              data-test="relationship-link-url"
            />
            <v-text-field
              v-model="linkName"
              :label="t('app.messaging.relationship.linkName')"
              density="comfortable"
              variant="outlined"
              hide-details
              data-test="relationship-link-name"
            />
            <v-btn
              color="primary"
              size="large"
              block
              :disabled="linkUrl.trim() === ''"
              data-test="relationship-link-add"
              @click="addLink"
            >
              {{ t('app.messaging.relationship.addLink') }}
            </v-btn>
          </v-card-text>
        </v-card>
      </v-dialog>
    </form>
  </div>
</template>

<style scoped>
.rel-thread {
  display: flex;
  flex-direction: column;
  /* dvh (dynamic viewport) tracks the *visible* area as the mobile URL bar /
     keyboard show & hide, so the pinned header + composer never get pushed
     off-screen (the bug that forced a scroll to reveal them). */
  height: calc(100dvh - 180px);
  min-height: 420px;
}

/* Mobile single-pane: fit between the top app-bar and bottom nav so only the
   feed scrolls. Smaller offset matches the tighter mobile page padding, and
   min-height is released so short viewports can't overflow. */
@media (max-width: 959px) {
  .rel-thread {
    height: calc(100dvh - 140px);
    min-height: 0;
  }
}

.rel-thread__header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding-bottom: 8px;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}

.rel-thread__back {
  margin-right: -4px;
}

.rel-thread__title {
  font-weight: 600;
}

.rel-thread__feed {
  flex: 1 1 auto;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 12px 4px;
}

.rel-thread__messages {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.rel-bubble-row {
  display: flex;
  justify-content: flex-start;
}

.rel-bubble-row--own {
  justify-content: flex-end;
}

.rel-bubble {
  max-width: 75%;
  padding: 6px 10px;
  border-radius: 12px;
  background: rgba(var(--v-theme-on-surface), 0.06);
}

.rel-bubble-row--own .rel-bubble {
  background: rgba(var(--v-theme-primary), 0.14);
}

.rel-bubble__sender {
  font-size: 0.72rem;
  font-weight: 600;
  opacity: 0.8;
  margin-bottom: 2px;
}

.rel-bubble__body {
  margin: 0;
  white-space: pre-wrap;
  word-break: break-word;
}

.rel-bubble__attachments {
  list-style: none;
  margin: 4px 0 0;
  padding: 0;
  font-size: 0.85rem;
}

.rel-bubble__meta {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 3px;
  margin-top: 2px;
}

.rel-bubble__time {
  font-size: 0.68rem;
  opacity: 0.6;
}

.rel-bubble__tick {
  opacity: 0.55;
}

.rel-bubble__tick--read {
  color: rgb(var(--v-theme-primary));
  opacity: 1;
}

.rel-thread__empty {
  opacity: 0.6;
  text-align: center;
  padding: 24px 0;
}

.rel-thread__load-earlier {
  text-align: center;
}

.rel-thread__compose {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding-top: 8px;
  border-top: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}

.rel-thread__pending {
  list-style: none;
  margin: 0;
  padding: 0;
  font-size: 0.85rem;
}

.rel-thread__error {
  color: rgb(var(--v-theme-error));
  font-size: 0.85rem;
  margin: 0;
}

/* WhatsApp-style composer: [+] [ field …………… (send) ] */
.rel-thread__compose-row {
  display: flex;
  align-items: flex-end;
  gap: 4px;
}

.rel-thread__attach-btn {
  margin-bottom: 2px;
  transition: transform 0.15s ease;
}

.rel-thread__attach-btn--active {
  transform: rotate(45deg);
}

.rel-thread__input {
  flex: 1 1 auto;
  min-width: 0;
}

/* The file / link icon menu the "+" reveals, aligned under the field. */
.rel-thread__attach-menu {
  display: flex;
  gap: 8px;
  padding-left: 44px;
}

.rel-thread__link-form {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

/* The native file input is triggered programmatically from the "+" menu. */
.rel-thread__file-input {
  display: none;
}
</style>
