<script setup lang="ts">
/**
 * PL-3.2a — a plain text box that is always as tall as its text (the spoken hook, the visual hook, the overlay and the brief's angle).
 * No inner scroll bar, ever: the height is set from `scrollHeight` on mount, on every change of the value (typing, a conflict
 * reload, "Use theirs"), when the box is shown again (a collapsed hook is opened: it has no size while hidden) and when its width
 * changes (a narrow screen, the notes sheet). The wrapper takes the page's attributes (`data-test`); the textarea takes `id`.
 */
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'

const props = defineProps<{
  modelValue: string
  id?: string
  placeholder?: string
  ariaLabel?: string
  error?: boolean
}>()
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const el = ref<HTMLTextAreaElement | null>(null)
let observer: ResizeObserver | undefined
let lastWidth = -1

function fit(): void {
  const box = el.value
  if (box === null) return
  box.style.height = 'auto'
  // A hidden box (collapsed hook) measures 0: leave it at one line until it is shown, the observer then fits it.
  if (box.scrollHeight > 0) box.style.height = `${box.scrollHeight}px`
}

function onInput(e: Event): void {
  emit('update:modelValue', (e.target as HTMLTextAreaElement).value)
  fit()
}

watch(
  () => props.modelValue,
  async () => {
    await nextTick()
    fit()
  },
)

onMounted(() => {
  fit()
  if (typeof ResizeObserver !== 'undefined' && el.value !== null) {
    observer = new ResizeObserver((entries) => {
      const width = Math.round(entries[0]?.contentRect.width ?? 0)
      if (width === lastWidth) return
      lastWidth = width
      fit()
    })
    observer.observe(el.value)
  }
  if (typeof document !== 'undefined' && document.fonts !== undefined)
    void document.fonts.ready.then(fit)
})
onBeforeUnmount(() => observer?.disconnect())
</script>

<template>
  <div class="agrow">
    <textarea
      :id="id"
      ref="el"
      class="agrow__box"
      rows="1"
      :value="modelValue"
      :placeholder="placeholder"
      :aria-label="ariaLabel"
      :aria-invalid="error ? 'true' : undefined"
      spellcheck="true"
      @input="onInput"
    />
  </div>
</template>

<style scoped>
.agrow {
  display: block;
  min-width: 0;
}
.agrow__box {
  display: block;
  box-sizing: border-box;
  width: 100%;
  margin: 0;
  padding: 0;
  border: 0;
  outline: none;
  overflow: hidden;
  resize: none;
  background: transparent;
  font: inherit;
  font-size: 13px;
  line-height: 19px;
  color: rgb(var(--v-theme-on-surface));
}
.agrow__box::placeholder {
  color: rgb(var(--v-theme-on-surface-variant));
  opacity: 0.7;
}
.agrow__box:focus-visible {
  border-radius: 3px;
  box-shadow: 0 0 0 3px rgba(var(--v-theme-primary), 0.35);
}
</style>
