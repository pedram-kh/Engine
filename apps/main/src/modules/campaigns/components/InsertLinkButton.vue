<script setup lang="ts">
/**
 * "Insert link" editor sugar for the two minimal-rich-text textareas
 * (`OfferFieldsForm`'s offer description, `CampaignForm`'s campaign
 * description — AH-082). Writes markdown into the bound string; never
 * touches the render/sanitize pipeline (`useRichBriefRenderer.ts`,
 * `RichBrief.vue`) — zero diff on both this chunk, by design.
 *
 * One component, not two copies, per the plan-pause: co-located here since
 * both consumers are campaigns-module forms; promote on a third consumer,
 * same discipline as the roster module's star-rating control.
 *
 * ── Selection handling ──────────────────────────────────────────────────
 *
 * `VTextarea` forwards the native `<textarea>`'s `selectionStart` /
 * `selectionEnd` / `setSelectionRange` / `focus` onto its own component
 * instance via Vuetify's `forwardRefs` utility (confirmed against
 * `VTextarea.js`'s `forwardRefs({}, vInputRef, vFieldRef, textareaRef)` —
 * not assumed), so a plain template ref on `<v-textarea ref="...">` in the
 * PARENT is enough; no `$el.querySelector('textarea')` needed. The parent
 * passes that ref in as `textareaRef` (a plain instance, auto-unwrapped by
 * Vue's template compiler — resolved well before the button is clickable).
 *
 * With a selection: the dialog asks only for the URL — the selected text
 * becomes the link's label, wrapping it as `[selection](url)`. With no
 * selection: the dialog also asks for the link's text, inserting
 * `[text](url)` at the cursor. Either way the cursor lands right after the
 * inserted markdown, not re-selecting it — the simplest "insert and keep
 * typing" feel.
 *
 * ── The maxlength guard ─────────────────────────────────────────────────
 *
 * The native `maxlength` attribute only constrains typed/pasted input — it
 * does NOT clamp a programmatic `.value` write (setting v-model directly
 * bypasses it entirely). An insert that would push the field past its cap
 * is therefore computed BEFORE it's applied and rejected with an inline
 * error naming the field, never silently truncated and never left to
 * surface as a server-side 422 surprise on submit.
 *
 * ── The zero-diff purity choice ─────────────────────────────────────────
 *
 * `HTTP_URL_RE` below duplicates `useRichBriefRenderer.ts`'s identical
 * regex literal rather than importing it — so that file's `git diff` this
 * chunk is genuinely empty, not "zero functional diff, one new export."
 * This dialog's check is UX-only; `validateHttpOnlyLink` in the renderer
 * remains the actual enforcement — no security claim rides this component.
 */

import { computed, nextTick, ref } from 'vue'
import { useI18n } from 'vue-i18n'

/**
 * The subset of the native `<textarea>` API this component needs.
 * `VTextarea`'s public TS surface doesn't declare these (they arrive
 * dynamically via `forwardRefs`), so the parent's template ref is cast to
 * this narrower interface at the point it's handed down — see the two
 * consumers for the cast.
 */
export interface TextareaHandle {
  selectionStart: number | null
  selectionEnd: number | null
  focus: () => void
  setSelectionRange: (start: number, end: number) => void
}

const HTTP_URL_RE = /^https?:\/\//i

const props = withDefaults(
  defineProps<{
    modelValue: string
    /** The parent's textarea ref — read for the current selection on open, written to for cursor restore on insert. `null` before the textarea mounts. */
    textareaRef: TextareaHandle | null
    /** When given, an insert that would exceed this length is rejected with an inline error rather than applied. */
    maxlength?: number
    testPrefix?: string
  }>(),
  { maxlength: undefined, testPrefix: 'insert-link' },
)

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const { t } = useI18n()

const dialogOpen = ref(false)
const url = ref('')
const linkText = ref('')
const urlError = ref<string | null>(null)
const textError = ref<string | null>(null)

const pendingSelection = ref<{ start: number; end: number; selectedText: string } | null>(null)

const hasSelection = computed(() => (pendingSelection.value?.selectedText ?? '') !== '')

function openDialog(): void {
  url.value = ''
  linkText.value = ''
  urlError.value = null
  textError.value = null

  const ta = props.textareaRef
  const fallback = props.modelValue.length
  const start = ta?.selectionStart ?? fallback
  const end = ta?.selectionEnd ?? fallback
  pendingSelection.value = { start, end, selectedText: props.modelValue.slice(start, end) }

  dialogOpen.value = true
}

function insertLink(): void {
  urlError.value = null
  textError.value = null

  const trimmedUrl = url.value.trim()
  if (!HTTP_URL_RE.test(trimmedUrl)) {
    urlError.value = t('app.campaigns.insertLink.invalidUrl')
    return
  }

  const sel = pendingSelection.value
  if (sel === null) return

  const label = hasSelection.value ? sel.selectedText : linkText.value.trim()
  if (!hasSelection.value && label === '') {
    textError.value = t('app.campaigns.insertLink.textRequired')
    return
  }

  const markdown = `[${label}](${trimmedUrl})`
  const text = props.modelValue
  const newText = text.slice(0, sel.start) + markdown + text.slice(sel.end)

  if (props.maxlength !== undefined && newText.length > props.maxlength) {
    urlError.value = t('app.campaigns.insertLink.tooLong')
    return
  }

  const cursorPos = sel.start + markdown.length
  emit('update:modelValue', newText)
  dialogOpen.value = false

  void nextTick(() => {
    const ta = props.textareaRef
    ta?.focus()
    ta?.setSelectionRange(cursorPos, cursorPos)
  })
}
</script>

<template>
  <div class="insert-link">
    <v-tooltip location="top" :text="t('app.campaigns.insertLink.buttonLabel')">
      <template #activator="{ props: tooltipProps }">
        <v-btn
          v-bind="tooltipProps"
          icon="mdi-link-variant"
          variant="text"
          size="small"
          :aria-label="t('app.campaigns.insertLink.buttonLabel')"
          :data-test="`${testPrefix}-button`"
          @click="openDialog"
        />
      </template>
    </v-tooltip>

    <v-dialog v-model="dialogOpen" max-width="420" :data-test="`${testPrefix}-dialog`">
      <v-card>
        <v-card-title>{{ t('app.campaigns.insertLink.dialogTitle') }}</v-card-title>
        <v-card-text class="insert-link__form">
          <v-text-field
            v-model="url"
            :label="t('app.campaigns.insertLink.urlLabel')"
            :error-messages="urlError ? [urlError] : []"
            density="comfortable"
            variant="outlined"
            hide-details="auto"
            autofocus
            :data-test="`${testPrefix}-url`"
            @keydown.enter="insertLink"
          />
          <v-text-field
            v-if="!hasSelection"
            v-model="linkText"
            :label="t('app.campaigns.insertLink.textLabel')"
            :error-messages="textError ? [textError] : []"
            density="comfortable"
            variant="outlined"
            hide-details="auto"
            :data-test="`${testPrefix}-text`"
            @keydown.enter="insertLink"
          />
          <v-btn
            color="primary"
            size="large"
            block
            :disabled="url.trim() === ''"
            :data-test="`${testPrefix}-insert`"
            @click="insertLink"
          >
            {{ t('app.campaigns.insertLink.insertButton') }}
          </v-btn>
        </v-card-text>
      </v-card>
    </v-dialog>
  </div>
</template>

<style scoped>
.insert-link {
  display: inline-flex;
}

.insert-link__form {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
</style>
