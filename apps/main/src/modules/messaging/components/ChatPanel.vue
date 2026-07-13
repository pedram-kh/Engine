<script setup lang="ts">
import { ApiError, extractFieldErrors, uploadToPresignedUrl } from '@catalyst/api-client'
import type {
  MessageResource,
  SendMessageAttachment,
  SendMessagePayload,
} from '@catalyst/api-client'
import { computed, nextTick, ref, toRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDisplay } from 'vuetify'

import type { ChatTransport } from '../api/messaging.api'
import { useMessageThread } from '../composables/useMessageThread'

const props = defineProps<{
  transport: ChatTransport | null
  /** The counterparty label shown in the panel header (e.g. the creator/agency name). */
  title?: string
}>()

const { t, te, locale } = useI18n()
const { smAndDown } = useDisplay()

// Enter-to-send is desktop-only; on mobile the keyboard return inserts a newline.
const isMobile = computed(() => smAndDown.value)

const transportRef = toRef(props, 'transport')
const {
  messages,
  hasMore,
  loading,
  loadingOlder,
  sending,
  loadError,
  humanSendBlocked,
  loadOlder,
  sendMessage,
} = useMessageThread(transportRef)

const timeFormatter = computed(
  () => new Intl.DateTimeFormat(locale.value, { hour: '2-digit', minute: '2-digit' }),
)

function formatTime(iso: string): string {
  const date = new Date(iso)
  return Number.isNaN(date.getTime()) ? '' : timeFormatter.value.format(date)
}

// Stick-to-bottom: jump to the newest message on first load + own send, and on
// incoming messages only when already near the bottom (mirrors the relationship
// thread; the shared composable is untouched).
const feedEl = ref<HTMLElement | null>(null)
const NEAR_BOTTOM_PX = 120

function scrollToBottom(): void {
  const el = feedEl.value
  if (el !== null) {
    el.scrollTop = el.scrollHeight
  }
}

function isNearBottom(): boolean {
  const el = feedEl.value
  if (el === null) {
    return true
  }
  return el.scrollHeight - el.scrollTop - el.clientHeight < NEAR_BOTTOM_PX
}

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
    if (tailChanged && (isInitialLoad || lastIsOwn || isNearBottom())) {
      void nextTick(scrollToBottom)
    }
    prevLastId = lastId
  },
  { immediate: true },
)

type ComposeField = 'body' | 'attachments'

const body = ref('')
const selectedFiles = ref<File[]>([])
const uploading = ref(false)
const generalError = ref<string | null>(null)
const fieldErrors = ref<Partial<Record<ComposeField, readonly string[]>>>({})

const fileInput = ref<HTMLInputElement | null>(null)
const attachOpen = ref(false)

function onComposerKeydown(event: KeyboardEvent): void {
  if (isMobile.value) {
    return
  }
  if (event.key !== 'Enter' || event.shiftKey || event.isComposing) {
    return
  }
  event.preventDefault()
  void submit()
}

function openFilePicker(): void {
  fileInput.value?.click()
  attachOpen.value = false
}

const canSend = computed(
  () =>
    !humanSendBlocked.value &&
    !sending.value &&
    !uploading.value &&
    (body.value.trim() !== '' || selectedFiles.value.length > 0),
)

function senderLabel(message: MessageResource): string {
  if (message.attributes.is_own) {
    return t('app.messaging.you')
  }
  return message.attributes.sender?.name ?? t('app.messaging.participant')
}

function systemLine(message: MessageResource): string {
  const key = message.attributes.system_event_key
  const i18nKey = `app.messaging.system.${key ?? ''}`
  if (key !== null && te(i18nKey)) {
    return t(i18nKey)
  }
  return t('app.messaging.systemFallback')
}

function onFilesPicked(event: Event): void {
  const target = event.target as HTMLInputElement
  selectedFiles.value = target.files !== null ? Array.from(target.files) : []
}

function removeFile(index: number): void {
  selectedFiles.value = selectedFiles.value.filter((_, i) => i !== index)
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

  const payload: SendMessagePayload = {}
  const trimmed = body.value.trim()
  if (trimmed !== '') {
    payload.body = trimmed
  }
  if (attachments.length > 0) {
    payload.attachments = attachments
  }

  try {
    await sendMessage(payload)
    body.value = ''
    selectedFiles.value = []
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
  <div class="chat-panel" data-test="chat-panel">
    <div v-if="title" class="chat-panel__header">{{ title }}</div>

    <div ref="feedEl" class="chat-panel__feed" data-test="chat-feed">
      <v-skeleton-loader v-if="loading" type="list-item-three-line@2" />

      <v-alert v-else-if="loadError" type="error" variant="tonal" density="compact">
        {{ t('app.messaging.loadError') }}
      </v-alert>

      <template v-else>
        <div v-if="hasMore" class="chat-panel__load-earlier">
          <v-btn
            size="small"
            variant="text"
            :loading="loadingOlder"
            data-test="chat-load-earlier"
            @click="loadOlder"
          >
            {{ t('app.messaging.loadEarlier') }}
          </v-btn>
        </div>

        <p v-if="messages.length === 0" class="chat-panel__empty" data-test="chat-empty">
          {{ t('app.messaging.empty') }}
        </p>

        <ul class="chat-panel__messages">
          <li
            v-for="message in messages"
            :key="message.id"
            class="chat-message"
            :class="{
              'chat-message--own': message.attributes.is_own,
              'chat-message--system': message.attributes.kind === 'system',
            }"
            :data-test="`chat-message-${message.id}`"
          >
            <div v-if="message.attributes.kind === 'system'" class="chat-message__system">
              {{ systemLine(message) }}
            </div>
            <div v-else class="chat-message__bubble">
              <div class="chat-message__sender">{{ senderLabel(message) }}</div>
              <p v-if="message.attributes.body" class="chat-message__body">
                {{ message.attributes.body }}
              </p>
              <ul
                v-if="message.attributes.attachments.length > 0"
                class="chat-message__attachments"
              >
                <li v-for="(att, i) in message.attributes.attachments" :key="i">
                  <a
                    v-if="att.view_url"
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
              <div class="chat-message__time">{{ formatTime(message.attributes.created_at) }}</div>
            </div>
          </li>
        </ul>
      </template>
    </div>

    <div v-if="humanSendBlocked" class="chat-panel__closed" data-test="chat-closed">
      <v-alert type="error" variant="tonal" density="compact">
        {{ t('app.messaging.closed') }}
      </v-alert>
    </div>

    <form v-else class="chat-panel__compose" data-test="chat-compose" @submit.prevent="submit">
      <ul
        v-if="selectedFiles.length > 0"
        class="chat-panel__pending"
        data-test="chat-pending-files"
      >
        <li v-for="(file, i) in selectedFiles" :key="i">
          <v-icon icon="mdi-paperclip" size="x-small" class="mr-1" />
          {{ file.name }}
          <v-btn icon="mdi-close" size="x-small" variant="text" @click="removeFile(i)" />
        </li>
      </ul>

      <p v-if="fieldErrors.attachments" class="chat-panel__error">
        {{ (fieldErrors.attachments as string[]).join(' ') }}
      </p>
      <p v-if="generalError" class="chat-panel__error" data-test="chat-general-error">
        {{ generalError }}
      </p>

      <div class="chat-panel__compose-row">
        <v-btn
          icon="mdi-plus"
          variant="text"
          density="comfortable"
          class="chat-panel__attach-btn"
          :class="{ 'chat-panel__attach-btn--active': attachOpen }"
          :aria-label="t('app.messaging.attachment')"
          data-test="chat-attach-toggle"
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
          class="chat-panel__input"
          data-test="chat-compose-body"
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
              data-test="chat-send"
              @click="submit"
            />
          </template>
        </v-textarea>
      </div>

      <div v-if="attachOpen" class="chat-panel__attach-menu" data-test="chat-attach-menu">
        <v-btn
          icon="mdi-paperclip"
          variant="tonal"
          size="small"
          :aria-label="t('app.messaging.attachment')"
          data-test="chat-attach-file"
          @click="openFilePicker"
        />
      </div>

      <input
        ref="fileInput"
        type="file"
        multiple
        class="chat-panel__file-input"
        data-test="chat-file-input"
        @change="onFilesPicked"
      />
    </form>
  </div>
</template>

<style scoped>
.chat-panel {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.chat-panel__header {
  font-weight: 600;
}

.chat-panel__feed {
  max-height: 420px;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.chat-panel__messages {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.chat-message {
  display: flex;
  justify-content: flex-start;
}

.chat-message--own {
  justify-content: flex-end;
}

.chat-message--system {
  justify-content: center;
}

.chat-message__bubble {
  max-width: 75%;
  padding: 6px 10px;
  border-radius: 12px;
  background: rgba(var(--v-theme-on-surface), 0.06);
}

.chat-message--own .chat-message__bubble {
  background: rgba(var(--v-theme-primary), 0.14);
}

.chat-message__system {
  font-size: 0.8rem;
  opacity: 0.7;
  text-align: center;
}

.chat-message__sender {
  font-size: 0.72rem;
  font-weight: 600;
  opacity: 0.8;
  margin-bottom: 2px;
}

.chat-message__body {
  margin: 0;
  white-space: pre-wrap;
  word-break: break-word;
}

.chat-message__attachments {
  list-style: none;
  margin: 4px 0 0;
  padding: 0;
  font-size: 0.85rem;
}

.chat-message__time {
  font-size: 0.68rem;
  opacity: 0.6;
  text-align: right;
  margin-top: 2px;
}

.chat-panel__empty {
  opacity: 0.6;
  text-align: center;
  padding: 16px 0;
}

.chat-panel__load-earlier {
  text-align: center;
}

.chat-panel__pending {
  list-style: none;
  margin: 0;
  padding: 0;
  font-size: 0.85rem;
}

.chat-panel__error {
  color: rgb(var(--v-theme-error));
  font-size: 0.85rem;
  margin: 0;
}

/* WhatsApp-style composer: [+] [ field …………… (send) ] */
.chat-panel__compose-row {
  display: flex;
  align-items: flex-end;
  gap: 4px;
}

.chat-panel__attach-btn {
  margin-bottom: 2px;
  transition: transform 0.15s ease;
}

.chat-panel__attach-btn--active {
  transform: rotate(45deg);
}

.chat-panel__input {
  flex: 1 1 auto;
  min-width: 0;
}

.chat-panel__attach-menu {
  display: flex;
  gap: 8px;
  padding-left: 44px;
}

/* The native file input is triggered programmatically from the "+" menu. */
.chat-panel__file-input {
  display: none;
}
</style>
