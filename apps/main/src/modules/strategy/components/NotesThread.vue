<script setup lang="ts">
/**
 * PL-2.5 — the internal notes of one step (and one market for a per-market step): the list, the composer, edit and
 * delete. Used inside the amber panel (USPs, Personas, Concepts) and the matrix's side sheet. Notes are PLAIN TEXT:
 * every body is printed with `{{ }}` and `white-space: pre-wrap`, never as HTML. The server decides who may edit (the
 * author) and delete (the author, an admin or a manager) and says so on each note; a note whose author has left shows
 * "Former member" and no name. A load that is no longer the latest (the market changed, the panel was closed and
 * reopened) is dropped.
 */
import type { StrategyNoteResource } from '@catalyst/api-client'
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { strategyApi, type NoteStep } from '../api/strategy.api'
import { useStrategyContext } from '../composables/useStrategyContext'
import { isGone, strategyErrorMessage } from '../strategyErrors'

import StrategyConfirmDialog from './StrategyConfirmDialog.vue'

const NOTE_MAX = 2000

const props = defineProps<{ step: NoteStep; market: string | null }>()
const emit = defineEmits<{ changed: [] }>()

const { t, locale } = useI18n()
const { scope } = useStrategyContext()

const notes = ref<StrategyNoteResource[]>([])
const loading = ref(true)
const loadFailed = ref(false)
// The draft lives with the parent when it binds one, so it survives the panel and the expanded view swapping places.
const draft = defineModel<string>('draft', { default: '' })
const listEl = ref<HTMLElement | null>(null)

/** Scroll the list to its newest note (the last one): on open and after an add. */
async function toBottom(): Promise<void> {
  await nextTick()
  if (listEl.value !== null) listEl.value.scrollTop = listEl.value.scrollHeight
}
const adding = ref(false)
const addError = ref<string | null>(null)
const editingId = ref<string | null>(null)
const editDraft = ref('')
const editBusy = ref(false)
const editError = ref<string | null>(null)
const deleting = ref<StrategyNoteResource | null>(null)
const deleteOpen = ref(false)
const deleteBusy = ref(false)
const deleteError = ref<string | null>(null)
let seq = 0
let alive = true

onBeforeUnmount(() => {
  alive = false
})

async function load(): Promise<void> {
  const s = scope.value
  if (s === null) return
  const mine = ++seq
  loading.value = true
  loadFailed.value = false
  notes.value = []
  editingId.value = null
  addError.value = null
  try {
    const r = await strategyApi.listNotes(s, props.step, props.market)
    if (!alive || mine !== seq) return
    notes.value = r.data
  } catch {
    if (!alive || mine !== seq) return
    loadFailed.value = true
  } finally {
    if (alive && mine === seq) {
      loading.value = false
      // The list is drawn only once loading is off: open on the newest note.
      void toBottom()
    }
  }
}

watch(
  () => [props.step, props.market],
  () => void load(),
  { immediate: true },
)

const trimmed = computed(() => draft.value.trim())
const tooLong = computed(() => draft.value.length > NOTE_MAX)
const canAdd = computed(() => trimmed.value.length > 0 && !tooLong.value && !adding.value)

async function add(): Promise<void> {
  const s = scope.value
  if (s === null || !canAdd.value) return
  const mine = seq
  adding.value = true
  addError.value = null
  try {
    const r = await strategyApi.addNote(s, props.step, props.market, draft.value)
    if (!alive || mine !== seq) return
    notes.value = [...notes.value, r.data]
    draft.value = ''
    void toBottom()
    emit('changed')
  } catch (e) {
    if (alive && mine === seq) addError.value = strategyErrorMessage(e, t)
  } finally {
    if (alive) adding.value = false
  }
}

function startEdit(n: StrategyNoteResource): void {
  editingId.value = n.id
  editDraft.value = n.attributes.body
  editError.value = null
}

const editValid = computed(
  () => editDraft.value.trim().length > 0 && editDraft.value.length <= NOTE_MAX && !editBusy.value,
)

async function saveEdit(): Promise<void> {
  const s = scope.value
  const id = editingId.value
  if (s === null || id === null || !editValid.value) return
  const mine = seq
  editBusy.value = true
  editError.value = null
  try {
    const r = await strategyApi.editNote(s, id, editDraft.value)
    if (!alive || mine !== seq) return
    notes.value = notes.value.map((n) => (n.id === id ? r.data : n))
    editingId.value = null
  } catch (e) {
    if (alive && mine === seq) editError.value = strategyErrorMessage(e, t)
  } finally {
    if (alive) editBusy.value = false
  }
}

function askDelete(n: StrategyNoteResource): void {
  deleting.value = n
  deleteError.value = null
  deleteOpen.value = true
}

async function confirmDelete(): Promise<void> {
  const s = scope.value
  const n = deleting.value
  if (s === null || n === null) return
  const mine = seq
  deleteBusy.value = true
  deleteError.value = null
  try {
    await strategyApi.deleteNote(s, n.id)
  } catch (e) {
    // A note that is already gone is the state the person wanted.
    if (!isGone(e)) {
      if (alive) {
        deleteError.value = strategyErrorMessage(e, t)
        deleteBusy.value = false
      }
      return
    }
  }
  if (!alive) return
  deleteBusy.value = false
  deleteOpen.value = false
  if (mine === seq) {
    notes.value = notes.value.filter((x) => x.id !== n.id)
    emit('changed')
  }
}

function when(iso: string): string {
  return new Date(iso).toLocaleString(locale.value, { dateStyle: 'medium', timeStyle: 'short' })
}

function authorName(n: StrategyNoteResource): string {
  return n.attributes.author?.name ?? t('app.strategy.notes.former')
}
</script>

<template>
  <div class="notes-thread" data-test="notes-thread">
    <v-progress-linear v-if="loading" indeterminate height="2" data-test="notes-loading" />
    <p v-else-if="loadFailed" class="notes-thread__msg" data-test="notes-load-failed">
      {{ t('app.strategy.notes.loadFailed') }}
      <a href="#" class="notes-link" data-test="notes-retry" @click.prevent="load">{{
        t('app.strategy.notes.retry')
      }}</a>
    </p>
    <template v-else>
      <div ref="listEl" class="notes-scroll" data-test="notes-scroll">
        <p v-if="notes.length === 0" class="notes-thread__msg" data-test="notes-empty">
          {{ t('app.strategy.notes.empty') }}
        </p>
        <ul v-else class="notes-list">
          <li v-for="n in notes" :key="n.id" class="note" :data-test="`note-${n.id}`">
            <div class="note__meta">
              <span
                class="note__author"
                :class="{ 'note__author--former': n.attributes.author_is_former }"
                >{{ authorName(n) }}</span
              >
              <span class="note__time">{{ when(n.attributes.created_at) }}</span>
              <span v-if="n.attributes.edited" class="note__edited" data-test="note-edited">{{
                t('app.strategy.notes.edited')
              }}</span>
            </div>
            <template v-if="editingId === n.id">
              <v-textarea
                v-model="editDraft"
                variant="outlined"
                density="compact"
                rows="2"
                auto-grow
                max-rows="8"
                hide-details="auto"
                :counter="NOTE_MAX"
                :error-messages="editError ? [editError] : []"
                :aria-label="t('app.strategy.notes.editLabel')"
                data-test="note-edit-input"
              />
              <div class="note__actions note__actions--end">
                <a
                  href="#"
                  class="notes-link"
                  data-test="note-edit-cancel"
                  @click.prevent="editingId = null"
                  >{{ t('app.strategy.cancel') }}</a
                >
                <v-btn
                  size="small"
                  color="primary"
                  variant="flat"
                  :disabled="!editValid"
                  :loading="editBusy"
                  data-test="note-edit-save"
                  @click="saveEdit"
                  >{{ t('app.strategy.save') }}</v-btn
                >
              </div>
            </template>
            <template v-else>
              <p class="note__body" data-test="note-body">{{ n.attributes.body }}</p>
              <div v-if="n.attributes.can_edit || n.attributes.can_delete" class="note__actions">
                <a
                  v-if="n.attributes.can_edit"
                  href="#"
                  class="notes-link"
                  data-test="note-edit"
                  @click.prevent="startEdit(n)"
                  >{{ t('app.strategy.notes.edit') }}</a
                >
                <a
                  v-if="n.attributes.can_delete"
                  href="#"
                  class="notes-link notes-link--muted"
                  data-test="note-delete"
                  @click.prevent="askDelete(n)"
                  >{{ t('app.strategy.notes.delete') }}</a
                >
              </div>
            </template>
          </li>
        </ul>
      </div>

      <div class="notes-composer">
        <v-textarea
          v-model="draft"
          variant="outlined"
          density="compact"
          rows="2"
          auto-grow
          max-rows="8"
          hide-details="auto"
          :placeholder="t('app.strategy.notes.placeholder')"
          :aria-label="t('app.strategy.notes.placeholder')"
          :counter="NOTE_MAX"
          :error-messages="
            addError
              ? [addError]
              : tooLong
                ? [t('app.strategy.notes.tooLong', { max: NOTE_MAX })]
                : []
          "
          data-test="note-input"
        />
        <v-btn
          size="small"
          color="primary"
          variant="flat"
          class="mt-2"
          :disabled="!canAdd"
          :loading="adding"
          data-test="note-add"
          @click="add"
          >{{ t('app.strategy.notes.add') }}</v-btn
        >
      </div>
    </template>

    <StrategyConfirmDialog
      v-model="deleteOpen"
      :title="t('app.strategy.notes.deleteTitle')"
      :body="t('app.strategy.notes.deleteBody')"
      :confirm-label="t('app.strategy.notes.delete')"
      :busy="deleteBusy"
      :error="deleteError"
      test-id="note-delete-dialog"
      @confirm="confirmDelete"
    />
  </div>
</template>

<style scoped>
.notes-thread {
  display: flex;
  flex-direction: column;
  gap: 10px;
  min-width: 0;
  min-height: 0;
}
/* The list is the only part that scrolls; the composer below it stays where it is. The thread fills whatever height
   its container gives it (the panel's cap, the sheet's full height) and never grows past it. */
.notes-scroll {
  flex: 0 1 auto;
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
  padding-inline-end: 4px;
}
.notes-composer {
  flex: none;
}
.notes-thread__msg {
  margin: 0;
  font-size: 13px;
  line-height: 19px;
  color: rgb(var(--v-theme-on-surface-variant));
}
.notes-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
  list-style: none;
  margin: 0;
  padding: 0;
}
.note {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}
.note__meta {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 4px 8px;
  font-size: 11px;
  color: rgb(var(--v-theme-on-surface-variant));
}
.note__author {
  font-size: 12px;
  font-weight: 600;
  color: rgb(var(--v-theme-on-surface));
  overflow-wrap: anywhere;
}
.note__author--former {
  font-weight: 500;
  font-style: italic;
  color: rgb(var(--v-theme-on-surface-variant));
}
.note__edited {
  font-style: italic;
}
/* Plain text: the body keeps its line breaks and wraps long words; it is never read as HTML. */
.note__body {
  margin: 0;
  font-size: 13px;
  line-height: 19px;
  color: rgb(var(--v-theme-on-surface));
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.note__actions {
  display: flex;
  align-items: center;
  justify-content: flex-start;
  gap: 14px;
  font-size: 12px;
  font-weight: 500;
}
.note__actions--end {
  justify-content: flex-end;
}
.notes-link {
  color: rgb(var(--v-theme-primary));
  text-decoration: none;
}
.notes-link--muted {
  color: rgb(var(--v-theme-on-surface-variant));
}
.notes-link:hover {
  text-decoration: underline;
}
.notes-composer {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
}
.notes-composer :deep(.v-textarea) {
  width: 100%;
  font-size: 13px;
}
</style>
