<script setup lang="ts">
/**
 * AH-114 (S2.3) — the agency fork of `RelationshipThreadView`: the Figma conversation pane
 * (Messages frame 18:71, right side). `RelationshipThreadView.vue` is shared with the creator
 * web and native app and is not edited (plan ruling 9a; spec 5 pins its hash). This copy keeps
 * every behaviour of the original that the agency app uses (the `useMessageThread` engine and
 * its poll, stick-to-bottom scrolling, load-earlier, files and links, the closed-conversation
 * notice, Enter to send) and drops what only the creator side uses: the `isNativePlatform()`
 * branches, the native height rule, and the "Report a problem" link (only the creator thread
 * page ever passed `reportSubject`).
 *
 * Figma pieces built: header (avatar and name), a day separator, own bubbles in the accent
 * tint with a corner cut at the bottom right, theirs in the raised surface with the corner cut
 * at the bottom left, a file card inside a bubble, a meta line (name and time, read ticks on
 * own messages), and a composer with an attach button, the field and a send button.
 *
 * Omitted, no placeholder (plan S2.3): the Online pill, the campaign tag, "View campaign" and
 * the more menu; the frame's data does not exist (no presence, no campaign on a thread) and the
 * menu has no actions. No i18n key is added: day labels and file sizes use `Intl`, the rest
 * reuses the original's keys.
 */

import { ApiError, extractFieldErrors, uploadToPresignedUrl } from '@catalyst/api-client'
import type {
  RelationshipMessageResource,
  RelationshipThreadClosedReason,
  RelationshipThreadMeta,
  SendMessageAttachment,
  SendRelationshipLink,
  SendRelationshipMessagePayload,
} from '@catalyst/api-client'
import { computed, nextTick, ref, toRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { RouteLocationRaw } from 'vue-router'
import { useDisplay } from 'vuetify'

import type { RelationshipChatTransport } from '@/modules/messaging/api/relationshipMessaging.api'
import { useMessageThread } from '@/modules/messaging/composables/useMessageThread'

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
const {
  messages,
  threadMeta,
  hasMore,
  loading,
  loadingOlder,
  sending,
  loadError,
  loadOlder,
  sendMessage,
} = useMessageThread<
  RelationshipMessageResource,
  RelationshipThreadMeta,
  SendRelationshipMessagePayload
>(transportRef)

// Whether the CONVERSATION accepts new messages at all — distinct from the
// `canSend` below, which is form validity (is there anything to send, are we
// mid-flight). A readable thread is not necessarily a writable one: history
// outlives the relation (server D6), so this is driven by the server's
// send-state rather than by "the feed loaded". Default TRUE while `threadMeta`
// is still null on first paint — an open composer is the normal case, and a
// brief optimistic composer beats flashing a "closed" notice on every thread
// open. The server stays authoritative either way.
const threadOpen = computed(() => threadMeta.value?.can_send ?? true)

// Reason → copy. `relation_ended` is the case a real user hits (an admin
// disconnect); the rest are mapped so no state can silently fall through to
// wording that would be wrong for it.
const CLOSED_REASON_KEY: Record<RelationshipThreadClosedReason, string> = {
  relation_ended: 'app.messaging.relationship.closed.relation_ended',
  blacklisted: 'app.messaging.relationship.closed.blacklisted',
  not_connected: 'app.messaging.relationship.closed.not_connected',
  no_relation: 'app.messaging.relationship.closed.not_connected',
  creator_not_approved: 'app.messaging.relationship.closed.creator_not_approved',
  not_a_party: 'app.messaging.relationship.closed.generic',
}

const closedMessage = computed(() => {
  const reason = threadMeta.value?.closed_reason ?? null
  const key =
    reason === null
      ? 'app.messaging.relationship.closed.generic'
      : (CLOSED_REASON_KEY[reason] ?? 'app.messaging.relationship.closed.generic')

  return t(key, { name: props.title })
})

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

// The time only: the day separators above the bubbles carry the date.
const timeFormatter = computed(() => new Intl.DateTimeFormat(locale.value, { timeStyle: 'short' }))

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

// ── Day separators and attachment cards (Intl only, no keys) ─────────────────────────
const dayFormatter = computed(() => new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium' }))
const relativeDay = computed(() => new Intl.RelativeTimeFormat(locale.value, { numeric: 'auto' }))

function startOfDay(date: Date): number {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime()
}

/** "today" / "yesterday" (localised, first letter raised) or a medium date. */
function dayLabel(iso: string, now: Date = new Date()): string {
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) {
    return ''
  }
  const diff = Math.round((startOfDay(date) - startOfDay(now)) / 86_400_000)
  if (diff === 0 || diff === -1) {
    const word = relativeDay.value.format(diff, 'day')
    return word.charAt(0).toLocaleUpperCase(locale.value) + word.slice(1)
  }
  return dayFormatter.value.format(date)
}

type FeedEntry =
  | { kind: 'day'; key: string; label: string }
  | { kind: 'message'; key: string; message: RelationshipMessageResource }

/** The messages in order, with a separator whenever the calendar day changes. */
const feed = computed<FeedEntry[]>(() => {
  const out: FeedEntry[] = []
  let lastDay: number | null = null
  for (const message of messages.value) {
    const date = new Date(message.attributes.created_at)
    const day = Number.isNaN(date.getTime()) ? null : startOfDay(date)
    if (day !== null && day !== lastDay) {
      out.push({
        kind: 'day',
        key: `day-${day}`,
        label: dayLabel(message.attributes.created_at),
      })
      lastDay = day
    }
    out.push({ kind: 'message', key: message.id, message })
  }
  return out
})

const sizeFormatters = computed(() => ({
  kilobyte: new Intl.NumberFormat(locale.value, {
    style: 'unit',
    unit: 'kilobyte',
    maximumFractionDigits: 0,
  }),
  megabyte: new Intl.NumberFormat(locale.value, {
    style: 'unit',
    unit: 'megabyte',
    maximumFractionDigits: 1,
  }),
}))

/** "PDF · 1.2 MB" from what the server sent; either part is left out when unknown. */
function fileMeta(att: { mime_type: string | null; size_bytes: number | null }): string {
  const kind = att.mime_type?.split('/')[1]?.split(/[+;]/)[0]?.toUpperCase() ?? ''
  const size =
    att.size_bytes === null
      ? ''
      : att.size_bytes >= 1_000_000
        ? sizeFormatters.value.megabyte.format(att.size_bytes / 1_000_000)
        : sizeFormatters.value.kilobyte.format(Math.max(1, Math.round(att.size_bytes / 1000)))
  return [kind, size].filter((part) => part !== '').join('  ·  ')
}

function linkHost(url: string | null): string {
  try {
    return url === null ? '' : new URL(url).hostname
  } catch {
    return ''
  }
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
  <div class="agency-thread" data-test="relationship-thread">
    <header class="agency-thread__header">
      <v-btn
        v-if="backTo"
        :to="backTo"
        icon="mdi-chevron-left"
        variant="text"
        density="comfortable"
        class="agency-thread__back"
        :aria-label="t('app.messaging.relationship.back')"
        data-test="relationship-thread-back"
      />
      <v-avatar size="40" color="primary" class="agency-thread__avatar">
        <v-img v-if="avatarUrl" :src="avatarUrl" :alt="title" />
        <span v-else class="agency-thread__initial">
          {{ (avatarText ?? title ?? '?')[0]?.toUpperCase() }}
        </span>
      </v-avatar>
      <span class="agency-thread__title" data-test="relationship-thread-title">{{ title }}</span>
    </header>

    <div ref="feedEl" class="agency-thread__feed" data-test="relationship-thread-feed">
      <v-skeleton-loader v-if="loading" type="list-item-three-line@3" />

      <v-alert v-else-if="loadError" type="error" variant="tonal" density="compact">
        {{ t('app.messaging.loadError') }}
      </v-alert>

      <template v-else>
        <div v-if="hasMore" class="agency-thread__load-earlier">
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
          class="agency-thread__empty"
          data-test="relationship-thread-empty"
        >
          {{ t('app.messaging.relationship.threadEmpty') }}
        </p>

        <ul class="agency-thread__messages">
          <template v-for="entry in feed" :key="entry.key">
            <li
              v-if="entry.kind === 'day'"
              class="agency-thread__day"
              role="separator"
              data-test="agency-thread-day"
            >
              <span class="agency-thread__day-rule" />
              <span class="agency-thread__day-label">{{ entry.label }}</span>
              <span class="agency-thread__day-rule" />
            </li>

            <li
              v-else
              class="agency-bubble-row"
              :class="{ 'agency-bubble-row--own': entry.message.attributes.is_own }"
              :data-test="`relationship-message-${entry.message.id}`"
            >
              <div class="agency-bubble-col">
                <div class="agency-bubble">
                  <div
                    v-if="showSenderLabel(entry.message)"
                    class="agency-bubble__sender"
                    data-test="relationship-message-sender"
                  >
                    {{ senderLabel(entry.message) }}
                  </div>

                  <p v-if="entry.message.attributes.body" class="agency-bubble__body">
                    {{ entry.message.attributes.body }}
                  </p>

                  <ul
                    v-if="entry.message.attributes.attachments.length > 0"
                    class="agency-bubble__attachments"
                  >
                    <li
                      v-for="(att, i) in entry.message.attributes.attachments"
                      :key="i"
                      :data-test="`relationship-attachment-${att.kind}`"
                    >
                      <a
                        v-if="att.kind === 'link' && att.url"
                        class="agency-attach"
                        :href="att.url"
                        target="_blank"
                        rel="noopener noreferrer"
                      >
                        <span class="agency-attach__tile"
                          ><v-icon icon="mdi-link-variant" size="16"
                        /></span>
                        <span class="agency-attach__text">
                          <span class="agency-attach__name">{{ att.name ?? att.url }}</span>
                          <span v-if="linkHost(att.url)" class="agency-attach__meta">{{
                            linkHost(att.url)
                          }}</span>
                        </span>
                        <v-icon
                          icon="mdi-open-in-new"
                          size="16"
                          class="agency-attach__ext"
                          aria-hidden="true"
                        />
                      </a>
                      <a
                        v-else-if="att.kind === 'file' && att.view_url"
                        class="agency-attach"
                        :href="att.view_url"
                        target="_blank"
                        rel="noopener noreferrer"
                      >
                        <span class="agency-attach__tile"
                          ><v-icon icon="mdi-file-outline" size="16"
                        /></span>
                        <span class="agency-attach__text">
                          <span class="agency-attach__name">{{
                            att.name ?? t('app.messaging.attachment')
                          }}</span>
                          <span v-if="fileMeta(att)" class="agency-attach__meta">{{
                            fileMeta(att)
                          }}</span>
                        </span>
                        <v-icon
                          icon="mdi-open-in-new"
                          size="16"
                          class="agency-attach__ext"
                          aria-hidden="true"
                        />
                      </a>
                      <span v-else class="agency-attach">
                        <span class="agency-attach__tile"
                          ><v-icon icon="mdi-paperclip" size="16"
                        /></span>
                        <span class="agency-attach__text">
                          <span class="agency-attach__name">{{
                            att.name ?? t('app.messaging.attachment')
                          }}</span>
                        </span>
                      </span>
                    </li>
                  </ul>
                </div>

                <div class="agency-bubble__meta">
                  <span
                    v-if="entry.message.attributes.is_own && entry.message.attributes.sender?.name"
                    class="agency-bubble__who"
                    >{{ entry.message.attributes.sender?.name }} ·</span
                  >
                  <span class="agency-bubble__time" data-test="relationship-message-time">{{
                    formatTime(entry.message.attributes.created_at)
                  }}</span>
                  <v-icon
                    v-if="entry.message.attributes.is_own"
                    :icon="
                      entry.message.attributes.read_by_counterparty ? 'mdi-check-all' : 'mdi-check'
                    "
                    size="14"
                    :class="[
                      'agency-bubble__tick',
                      {
                        'agency-bubble__tick--read': entry.message.attributes.read_by_counterparty,
                      },
                    ]"
                    :aria-label="
                      entry.message.attributes.read_by_counterparty
                        ? t('app.messaging.relationship.read')
                        : t('app.messaging.relationship.sent')
                    "
                    :data-test="`relationship-tick-${entry.message.attributes.read_by_counterparty ? 'read' : 'sent'}`"
                  />
                </div>
              </div>
            </li>
          </template>
        </ul>
      </template>
    </div>

    <div v-if="!threadOpen" class="agency-thread__closed" data-test="relationship-closed">
      <v-alert type="info" variant="tonal" density="compact">
        {{ closedMessage }}
      </v-alert>
    </div>

    <form
      v-else
      class="agency-thread__compose"
      data-test="relationship-compose"
      @submit.prevent="submit"
    >
      <ul
        v-if="selectedFiles.length > 0"
        class="agency-thread__pending"
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
        class="agency-thread__pending"
        data-test="relationship-pending-links"
      >
        <li v-for="(link, i) in links" :key="i">
          <v-icon icon="mdi-link-variant" size="x-small" class="mr-1" />
          {{ link.name ?? link.url }}
          <v-btn icon="mdi-close" size="x-small" variant="text" @click="removeLink(i)" />
        </li>
      </ul>

      <p v-if="fieldErrors.attachments" class="agency-thread__error">
        {{ (fieldErrors.attachments as string[]).join(' ') }}
      </p>
      <p v-if="fieldErrors.links" class="agency-thread__error">
        {{ (fieldErrors.links as string[]).join(' ') }}
      </p>
      <p v-if="generalError" class="agency-thread__error" data-test="relationship-general-error">
        {{ generalError }}
      </p>

      <div class="agency-thread__compose-row">
        <v-btn
          icon="mdi-paperclip"
          variant="outlined"
          class="agency-thread__square"
          :class="{ 'agency-thread__square--active': attachOpen }"
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
          class="agency-thread__input"
          data-test="relationship-compose-body"
          @keydown="onComposerKeydown"
        />

        <v-btn
          icon="mdi-send"
          variant="flat"
          color="primary"
          class="agency-thread__square"
          :loading="sending || uploading"
          :disabled="!canSend"
          :aria-label="t('app.messaging.send')"
          data-test="relationship-send"
          @click="submit"
        />
      </div>

      <div
        v-if="attachOpen"
        class="agency-thread__attach-menu"
        data-test="relationship-attach-menu"
      >
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
        class="agency-thread__file-input"
        data-test="relationship-file-input"
        @change="onFilesPicked"
      />

      <v-dialog v-model="linkDialogOpen" max-width="420" data-test="relationship-link-dialog">
        <v-card>
          <v-card-text class="agency-thread__link-form">
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
.agency-thread {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
}

/* Header (Figma 18:543): 24 and 16 padding, 12 between, a 1px rule under it. */
.agency-thread__header {
  display: flex;
  align-items: center;
  gap: var(--space-3, 12px);
  padding: var(--space-4, 16px) var(--space-6, 24px);
  border-bottom: 1px solid rgb(var(--v-theme-border-color));
}

.agency-thread__back {
  margin-left: -8px;
}

.agency-thread__initial {
  font-size: var(--catalyst-typography-caption-size);
  line-height: var(--catalyst-typography-caption-line-height);
  font-weight: 500;
  color: rgb(var(--v-theme-on-primary));
}

.agency-thread__title {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: var(--catalyst-typography-heading-4-size);
  line-height: var(--catalyst-typography-heading-4-line-height);
  font-weight: var(--catalyst-typography-heading-4-weight);
  color: rgb(var(--v-theme-on-surface));
}

.agency-thread__feed {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: var(--space-2, 8px);
  padding: var(--space-6, 24px);
}

.agency-thread__messages {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-4, 16px);
}

/* Day separator (Figma 18:564): a rule, a Caption/strong tertiary label, a rule. */
.agency-thread__day {
  display: flex;
  align-items: center;
  gap: var(--space-3, 12px);
}

.agency-thread__day-rule {
  flex: 1 1 0;
  height: 1px;
  background-color: rgb(var(--v-theme-border-color));
}

.agency-thread__day-label {
  font-size: var(--catalyst-typography-caption-size);
  line-height: var(--catalyst-typography-caption-line-height);
  font-weight: 500;
  color: rgb(var(--v-theme-text-tertiary));
  white-space: nowrap;
}

.agency-bubble-row {
  display: flex;
  justify-content: flex-start;
}

.agency-bubble-row--own {
  justify-content: flex-end;
}

.agency-bubble-col {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: var(--space-1, 4px);
  max-width: min(460px, 85%);
}

.agency-bubble-row--own .agency-bubble-col {
  align-items: flex-end;
}

/* Bubble (Figma 18:585 and 18:569): 16 and 12 padding, radius 12 with one 4px corner. */
.agency-bubble {
  display: flex;
  flex-direction: column;
  gap: var(--space-2, 8px);
  padding: var(--space-3, 12px) var(--space-4, 16px);
  border: 1px solid transparent;
  border-radius: var(--radius-2xl, 12px) var(--radius-2xl, 12px) var(--radius-2xl, 12px)
    var(--radius-sm, 4px);
  background-color: rgb(var(--v-theme-surface-bright));
  color: rgb(var(--v-theme-on-surface));
}

.agency-bubble-row--own .agency-bubble {
  border-color: rgb(var(--v-theme-primary));
  border-radius: var(--radius-2xl, 12px) var(--radius-2xl, 12px) var(--radius-sm, 4px)
    var(--radius-2xl, 12px);
  background-color: rgb(var(--v-theme-primary-container));
}

.agency-bubble__sender {
  font-size: var(--catalyst-typography-caption-size);
  line-height: var(--catalyst-typography-caption-line-height);
  font-weight: 500;
  color: rgb(var(--v-theme-on-surface-variant));
}

.agency-bubble__body {
  margin: 0;
  font-size: var(--catalyst-typography-body-size);
  line-height: var(--catalyst-typography-body-line-height);
  white-space: pre-wrap;
  word-break: break-word;
}

.agency-bubble__attachments {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-2, 8px);
}

/* The file card inside a bubble (Figma 18:571). */
.agency-attach {
  display: flex;
  align-items: center;
  gap: var(--space-3, 12px);
  padding: var(--space-3, 12px);
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: var(--radius-lg, 8px);
  background-color: rgb(var(--v-theme-surface));
  color: inherit;
  text-decoration: none;
}

a.agency-attach:hover {
  background-color: rgb(var(--v-theme-surface-bright));
}

a.agency-attach:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 2px;
}

.agency-attach__tile {
  flex: none;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: var(--radius-md, 6px);
  background-color: rgb(var(--v-theme-primary-container));
  color: rgb(var(--v-theme-primary-text));
}

.agency-attach__text {
  flex: 1 1 auto;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.agency-attach__name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: var(--catalyst-typography-body-size);
  line-height: var(--catalyst-typography-body-line-height);
  font-weight: 500;
}

.agency-attach__meta {
  font-size: var(--catalyst-typography-caption-size);
  line-height: var(--catalyst-typography-caption-line-height);
  color: rgb(var(--v-theme-text-tertiary));
  white-space: pre;
}

.agency-attach__ext {
  flex: none;
  color: rgb(var(--v-theme-text-tertiary));
}

/* Meta line under a bubble: name and time, ticks on own messages. */
.agency-bubble__meta {
  display: flex;
  align-items: center;
  gap: var(--space-1, 4px);
  font-size: var(--catalyst-typography-caption-size);
  line-height: var(--catalyst-typography-caption-line-height);
  color: rgb(var(--v-theme-text-tertiary));
}

.agency-bubble__who,
.agency-bubble__time {
  white-space: pre;
}

.agency-bubble__tick {
  color: rgb(var(--v-theme-text-tertiary));
}

.agency-bubble__tick--read {
  color: rgb(var(--v-theme-primary-text));
}

.agency-thread__empty {
  margin: 0;
  padding: 24px 0;
  text-align: center;
  color: rgb(var(--v-theme-on-surface-variant));
}

.agency-thread__load-earlier {
  text-align: center;
}

/* Composer (Figma 18:602): 24 and 16 padding, a 1px rule above, 12 between. */
.agency-thread__compose,
.agency-thread__closed {
  padding: var(--space-4, 16px) var(--space-6, 24px);
  border-top: 1px solid rgb(var(--v-theme-border-color));
}

.agency-thread__compose {
  display: flex;
  flex-direction: column;
  gap: var(--space-2, 8px);
}

.agency-thread__compose-row {
  display: flex;
  align-items: flex-end;
  gap: var(--space-3, 12px);
}

.agency-thread__input {
  flex: 1 1 auto;
  min-width: 0;
}

/* The attach and send buttons are 40px squares (Figma), not the 48px icon-button default. */
.agency-thread__square {
  flex: none;
  width: 40px;
  height: 40px;
  border-radius: var(--radius-lg, 8px);
}

.agency-thread__square--active {
  background-color: rgb(var(--v-theme-surface-bright));
}

.agency-thread__attach-menu {
  display: flex;
  gap: var(--space-2, 8px);
}

.agency-thread__pending {
  list-style: none;
  margin: 0;
  padding: 0;
  font-size: var(--catalyst-typography-body-sm-size);
  line-height: var(--catalyst-typography-body-sm-line-height);
}

.agency-thread__error {
  margin: 0;
  color: rgb(var(--v-theme-error-text));
  font-size: var(--catalyst-typography-body-sm-size);
  line-height: var(--catalyst-typography-body-sm-line-height);
}

.agency-thread__link-form {
  display: flex;
  flex-direction: column;
  gap: var(--space-3, 12px);
}

/* The native file input is opened programmatically from the attach menu. */
.agency-thread__file-input {
  display: none;
}
</style>
