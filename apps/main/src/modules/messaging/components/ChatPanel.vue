<script setup lang="ts">
import { ApiError, extractFieldErrors, uploadToPresignedUrl } from '@catalyst/api-client'
import type {
  MessageResource,
  SendMessageAttachment,
  SendMessagePayload,
} from '@catalyst/api-client'
import { computed, ref, toRef } from 'vue'
import { useI18n } from 'vue-i18n'

import type { ChatTransport } from '../api/messaging.api'
import { useMessageThread } from '../composables/useMessageThread'

const props = defineProps<{
  transport: ChatTransport | null
  /** The counterparty label shown in the panel header (e.g. the creator/agency name). */
  title?: string
}>()

const { t, te } = useI18n()

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

type ComposeField = 'body' | 'attachments'

const body = ref('')
const selectedFiles = ref<File[]>([])
const uploading = ref(false)
const generalError = ref<string | null>(null)
const fieldErrors = ref<Partial<Record<ComposeField, readonly string[]>>>({})

const fileInput = ref<HTMLInputElement | null>(null)

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

    <div class="chat-panel__feed" data-test="chat-feed">
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
              <div class="chat-message__meta">
                <span class="chat-message__sender">{{ senderLabel(message) }}</span>
              </div>
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
                    {{ att.name ?? t('app.messaging.attachment') }}
                  </a>
                  <span v-else>{{ att.name ?? t('app.messaging.attachment') }}</span>
                </li>
              </ul>
            </div>
          </li>
        </ul>
      </template>
    </div>

    <div v-if="humanSendBlocked" class="chat-panel__closed" data-test="chat-closed">
      <v-alert type="info" variant="tonal" density="compact">
        {{ t('app.messaging.closed') }}
      </v-alert>
    </div>

    <form v-else class="chat-panel__compose" data-test="chat-compose" @submit.prevent="submit">
      <v-textarea
        v-model="body"
        :label="t('app.messaging.composePlaceholder')"
        :error-messages="fieldErrors.body as string[]"
        rows="2"
        auto-grow
        density="compact"
        variant="outlined"
        hide-details="auto"
        data-test="chat-compose-body"
      />

      <ul
        v-if="selectedFiles.length > 0"
        class="chat-panel__pending"
        data-test="chat-pending-files"
      >
        <li v-for="(file, i) in selectedFiles" :key="i">
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

      <div class="chat-panel__actions">
        <input
          ref="fileInput"
          type="file"
          multiple
          class="chat-panel__file-input"
          data-test="chat-file-input"
          @change="onFilesPicked"
        />
        <v-spacer />
        <v-btn
          type="submit"
          color="primary"
          :loading="sending || uploading"
          :disabled="!canSend"
          data-test="chat-send"
        >
          {{ t('app.messaging.send') }}
        </v-btn>
      </div>
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
  gap: 8px;
}

.chat-message {
  display: flex;
}

.chat-message--own {
  justify-content: flex-end;
}

.chat-message--system {
  justify-content: center;
}

.chat-message__bubble {
  max-width: 75%;
  padding: 8px 12px;
  border-radius: 12px;
  background: rgba(var(--v-theme-on-surface), 0.06);
}

.chat-message--own .chat-message__bubble {
  background: rgba(var(--v-theme-primary), 0.12);
}

.chat-message__system {
  font-size: 0.8rem;
  opacity: 0.7;
  text-align: center;
}

.chat-message__meta {
  font-size: 0.75rem;
  opacity: 0.7;
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

.chat-panel__actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.chat-panel__file-input {
  font-size: 0.8rem;
}
</style>
