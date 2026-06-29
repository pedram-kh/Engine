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
import { computed, ref, toRef } from 'vue'
import { useI18n } from 'vue-i18n'

import type { RelationshipChatTransport } from '../api/relationshipMessaging.api'
import { useMessageThread } from '../composables/useMessageThread'

const props = defineProps<{
  transport: RelationshipChatTransport | null
  /** The counterparty label shown in the thread header. */
  title: string
  avatarText?: string
  avatarUrl?: string | null
}>()

const { t, locale } = useI18n()

const transportRef = toRef(props, 'transport')
const { messages, hasMore, loading, loadingOlder, sending, loadError, loadOlder, sendMessage } =
  useMessageThread<
    RelationshipMessageResource,
    RelationshipThreadMeta,
    SendRelationshipMessagePayload
  >(transportRef)

type ComposeField = 'body' | 'attachments' | 'links'

const body = ref('')
const selectedFiles = ref<File[]>([])
const links = ref<SendRelationshipLink[]>([])
const linkUrl = ref('')
const linkName = ref('')
const linkError = ref<string | null>(null)
const uploading = ref(false)
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

function formatTime(iso: string): string {
  const date = new Date(iso)
  return Number.isNaN(date.getTime()) ? '' : timeFormatter.value.format(date)
}

function senderLabel(message: RelationshipMessageResource): string {
  return message.attributes.sender?.name ?? t('app.messaging.participant')
}

function onFilesPicked(event: Event): void {
  const target = event.target as HTMLInputElement
  selectedFiles.value = target.files !== null ? Array.from(target.files) : []
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
      <v-avatar size="40" color="primary" class="rel-thread__avatar">
        <v-img v-if="avatarUrl" :src="avatarUrl" :alt="title" />
        <span v-else class="text-body-2 font-weight-bold text-white">
          {{ (avatarText ?? title ?? '?')[0]?.toUpperCase() }}
        </span>
      </v-avatar>
      <span class="rel-thread__title" data-test="relationship-thread-title">{{ title }}</span>
    </header>

    <div class="rel-thread__feed" data-test="relationship-thread-feed">
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
                v-if="!message.attributes.is_own"
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
      <v-textarea
        v-model="body"
        :label="t('app.messaging.composePlaceholder')"
        :error-messages="fieldErrors.body as string[]"
        rows="1"
        auto-grow
        max-rows="5"
        density="compact"
        variant="outlined"
        hide-details="auto"
        data-test="relationship-compose-body"
      />

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

      <div class="rel-thread__link-adder">
        <v-text-field
          v-model="linkUrl"
          :label="t('app.messaging.relationship.linkUrl')"
          :error-messages="linkError ? [linkError] : []"
          density="compact"
          variant="outlined"
          hide-details="auto"
          data-test="relationship-link-url"
        />
        <v-text-field
          v-model="linkName"
          :label="t('app.messaging.relationship.linkName')"
          density="compact"
          variant="outlined"
          hide-details
          data-test="relationship-link-name"
        />
        <v-btn
          variant="tonal"
          size="small"
          :disabled="linkUrl.trim() === ''"
          data-test="relationship-link-add"
          @click="addLink"
        >
          {{ t('app.messaging.relationship.addLink') }}
        </v-btn>
      </div>

      <p v-if="fieldErrors.attachments" class="rel-thread__error">
        {{ (fieldErrors.attachments as string[]).join(' ') }}
      </p>
      <p v-if="fieldErrors.links" class="rel-thread__error">
        {{ (fieldErrors.links as string[]).join(' ') }}
      </p>
      <p v-if="generalError" class="rel-thread__error" data-test="relationship-general-error">
        {{ generalError }}
      </p>

      <div class="rel-thread__actions">
        <input
          ref="fileInput"
          type="file"
          multiple
          class="rel-thread__file-input"
          data-test="relationship-file-input"
          @change="onFilesPicked"
        />
        <v-spacer />
        <v-btn
          type="submit"
          color="primary"
          :loading="sending || uploading"
          :disabled="!canSend"
          data-test="relationship-send"
        >
          {{ t('app.messaging.send') }}
        </v-btn>
      </div>
    </form>
  </div>
</template>

<style scoped>
.rel-thread {
  display: flex;
  flex-direction: column;
  height: calc(100vh - 180px);
  min-height: 420px;
}

.rel-thread__header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding-bottom: 12px;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08);
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
  padding-top: 12px;
  border-top: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}

.rel-thread__pending {
  list-style: none;
  margin: 0;
  padding: 0;
  font-size: 0.85rem;
}

.rel-thread__link-adder {
  display: flex;
  gap: 8px;
  align-items: flex-start;
}

.rel-thread__error {
  color: rgb(var(--v-theme-error));
  font-size: 0.85rem;
  margin: 0;
}

.rel-thread__actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.rel-thread__file-input {
  font-size: 0.8rem;
}
</style>
