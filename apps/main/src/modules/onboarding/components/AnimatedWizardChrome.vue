<script setup lang="ts">
/**
 * AnimatedWizardChrome — the "Option A (hybrid)" wizard presentation.
 *
 * Left: an animated title stack. The active step locks at the vertical
 *   centre of the chrome; completed/skipped steps shrink and park at the
 *   top; upcoming steps fade below. Advancing plays a mechanical "tick"
 *   (sharp snap) followed by a slow "drift"; walking back reverses the
 *   order (drift, then tick). The motion language is ported from the
 *   `Wizard/` prototype.
 *
 * Right: an SVG "frame" draws around a FULL-SIZE, scrollable content
 *   panel that holds the routed step page (the default slot). Antenna
 *   lines grow from the active row out to the panel corners, the
 *   rectangle draws from those corners, then the antennas retract.
 *
 * This component owns ONLY presentation + motion. It is data-driven via
 * props and emits `navigate` when a parked (completed/skipped) row is
 * clicked. All colours flow through Vuetify theme CSS variables
 * (`rgb(var(--v-theme-*))`) per the no-hard-coded-colors invariant.
 *
 * a11y: the rail is a `<nav>` landmark; the active row carries
 * `aria-current="step"`; status icons are `aria-hidden` with a visible
 * text label. Honours `prefers-reduced-motion` by collapsing every
 * transition to ~0ms and drawing the frame without the grow animation.
 */

import { nextTick, onBeforeUnmount, onMounted, ref, watch, type ComponentPublicInstance } from 'vue'

type TemplateRefEl = Element | ComponentPublicInstance | null

export interface WizardChromeStep {
  id: string
  title: string
  /** completed | skipped | not-started | active (active wins) */
  status: 'completed' | 'skipped' | 'not-started' | 'active'
  /** router target for click-to-revisit; null = not navigable */
  routeName: string | null
  /** completed/skipped rows above the active one are clickable */
  clickable: boolean
  /** the "Step N of M" caption */
  positionLabel: string
}

const props = defineProps<{
  steps: WizardChromeStep[]
  activeIndex: number
  reducedMotion?: boolean
}>()

const emit = defineEmits<{ (e: 'navigate', routeName: string): void }>()

/* ---- motion constants (mirror the prototype, §7) ---- */
const TICK_MS = 300
const DRIFT_MS = 900
const PHASE2_DELAY = 280
const E_TICK = 'cubic-bezier(.2, 1.1, .3, 1)'
const E_DRIFT = 'cubic-bezier(.12, .8, .2, 1)'
const E_SMOOTH = 'cubic-bezier(.16, 1, .3, 1)'
const GROW_MS = 440

const FRAME_ANTENNA_MS = 220
const FRAME_RECT_MS = 420
const FRAME_RETRACT_MS = 260
const FRAME_SHOW_DELAY = 520
const FRAME_ANCHOR_GAP = 16
/** the rectangle is drawn flush with the panel edges so the form content
 *  fills the framed area. The frame sits on the z-stack above the panel, so
 *  the stroke reads as the panel's border rather than being hidden behind it. */
const FRAME_OUTSET = 0

const clamp = (v: number, lo: number, hi: number): number => Math.max(lo, Math.min(hi, v))

/* ---- refs ---- */
const rootEl = ref<HTMLElement | null>(null)
const panelEl = ref<HTMLElement | null>(null)
const svgEl = ref<SVGSVGElement | null>(null)
const rowEls = ref<HTMLElement[]>([])

function setRowRef(el: TemplateRefEl, i: number): void {
  if (el instanceof HTMLElement) rowEls.value[i] = el
}

/** The frame's line elements are built imperatively with
 *  createElementNS so they are guaranteed to live in the SVG namespace
 *  (authoring <line> in the Vue template can land them in the HTML
 *  namespace, where setAttribute('x1'…) never produces a drawn line).
 *  This mirrors the working prototype. */
const SVGNS = 'http://www.w3.org/2000/svg'
let antennaLines: SVGLineElement[] = []
let rectLines: SVGLineElement[] = []

function buildFrameLines(): void {
  const svg = svgEl.value
  if (!svg) return
  antennaLines = []
  rectLines = []
  const make = (cls: string, bucket: SVGLineElement[]): void => {
    const line = document.createElementNS(SVGNS, 'line')
    line.setAttribute('class', `frame-line ${cls}`)
    // stroke set inline (CSS var, theme-driven) so it does not depend on
    // scoped-CSS reaching these imperatively-created elements.
    line.style.fill = 'none'
    line.style.stroke = 'rgba(var(--v-theme-on-surface), 0.7)'
    line.style.strokeWidth = '1.5'
    bucket.push(line)
    svg.appendChild(line)
  }
  for (let i = 0; i < 4; i++) make('frame-antenna', antennaLines)
  for (let i = 0; i < 4; i++) make('frame-rect', rectLines)
}

/* ---- layout math (§8) ---- */
const layout = { H: 0, CENTER: 0, TOP_Y: 0, COMP_GAP: 0, UP_GAP: 0, ROWH: 60 }

function computeLayout(): void {
  const H = rootEl.value?.clientHeight ?? window.innerHeight
  layout.H = H
  layout.CENTER = H / 2
  layout.TOP_Y = Math.max(56, H * 0.1)
  // Spacing divisors are DERIVED from the rendered step count (AH-003) so
  // the rail packs correctly for any number of visible steps instead of
  // assuming the old fixed 9-row layout. COMP_GAP spreads the completed
  // rows parked above centre (at most n-1 of them); UP_GAP spreads the
  // upcoming rows below centre (at most n-1). The clamps keep both gaps
  // within the same visual band the 9-step layout used.
  const n = Math.max(props.steps.length, 2)
  layout.COMP_GAP = clamp((layout.CENTER - 60 - layout.TOP_Y) / (n - 2 || 1), 24, 44)
  layout.UP_GAP = clamp((H - 70 - layout.CENTER) / (n - 1), 36, 58)
}

/* ---- phase state ---- */
type Phase = 'init' | 'idle' | 'tick' | 'drift' | 'backdrift'
let phase: Phase = 'init'
let leaving = -1
let returning = -1
let current = props.activeIndex
let animating = false
const timers: number[] = []

function clearTimers(): void {
  for (const t of timers) window.clearTimeout(t)
  timers.length = 0
}
function after(ms: number, fn: () => void): void {
  timers.push(window.setTimeout(fn, ms))
}

function reduced(): boolean {
  return props.reducedMotion === true
}

function targetY(i: number): number {
  const { CENTER, TOP_Y, COMP_GAP, UP_GAP } = layout
  if (phase === 'tick' && i === leaving) return CENTER - UP_GAP
  if (phase === 'backdrift' && i === returning) return CENTER - UP_GAP
  if (i === current) return CENTER
  if (i < current) return TOP_Y + i * COMP_GAP
  return CENTER + (i - current) * UP_GAP
}

function scaleOf(i: number): number {
  if (i === current) return 1
  if (i < current) return 0.42
  const dist = i - current
  return clamp(0.52 - 0.02 * (dist - 1), 0.42, 0.52)
}

function opacityOf(i: number): number {
  if (i === current) return 1
  if (i < current) {
    const age = current - 1 - i
    return Math.max(0.22, 0.46 - 0.045 * age)
  }
  const dist = i - current
  return Math.max(0.14, 0.5 - 0.06 * dist)
}

function rowTransition(i: number): string {
  if (reduced()) return 'transform 1ms, opacity 1ms'
  const opacity = `opacity ${GROW_MS}ms ${E_SMOOTH}`
  let pos: string
  switch (phase) {
    case 'tick':
      pos = `transform ${TICK_MS}ms ${E_TICK}`
      break
    case 'drift':
      pos = i === leaving ? `transform ${DRIFT_MS}ms ${E_DRIFT}` : 'transform 0ms'
      break
    case 'backdrift':
      pos = i === returning ? `transform ${DRIFT_MS}ms ${E_DRIFT}` : 'transform 0ms'
      break
    default:
      pos = 'transform 0ms'
  }
  return phase === 'init' ? `${pos}, opacity 0ms` : `${pos}, ${opacity}`
}

/* ---- imperative paint (transform/opacity per row) ---- */
function paint(): void {
  const n = props.steps.length
  for (let i = 0; i < n; i++) {
    const el = rowEls.value[i]
    if (!el) continue
    el.style.transition = rowTransition(i)
    el.style.setProperty('--y', targetY(i) + 'px')
    el.style.opacity = String(opacityOf(i))
    const tw = el.querySelector<HTMLElement>('.title-wrap')
    if (tw) tw.style.transform = `scale(${scaleOf(i)})`
  }
}

/* ---- SVG frame ---- */
let frameVisible = false

function panelRect(): { L: number; R: number; T: number; B: number } | null {
  const root = rootEl.value
  const panel = panelEl.value
  if (!root || !panel) return null
  // offsetLeft/Top/Width/Height are relative to the positioned root and —
  // unlike getBoundingClientRect — are NOT affected by the panel's own
  // transform. While the panel is `.is-hidden` it carries translateY(8px);
  // measuring that with getBoundingClientRect drew the frame 8px low, so the
  // form ended up poking outside the rectangle until a resize recomputed it.
  const L = panel.offsetLeft
  const T = panel.offsetTop
  return { L, R: L + panel.offsetWidth, T, B: T + panel.offsetHeight }
}

function setLine(el: SVGLineElement, x1: number, y1: number, x2: number, y2: number): void {
  el.setAttribute('x1', String(x1))
  el.setAttribute('y1', String(y1))
  el.setAttribute('x2', String(x2))
  el.setAttribute('y2', String(y2))
  const len = Math.hypot(x2 - x1, y2 - y1) || 1
  el.dataset.len = String(len)
  el.style.strokeDasharray = String(len)
}

function showFrame(): void {
  const svg = svgEl.value
  const root = rootEl.value
  const rect = panelRect()
  if (!svg || !root || !rect) return
  if (current < 0 || current >= props.steps.length) return

  const W = root.clientWidth
  const H = root.clientHeight
  // explicit pixel size + matching viewBox so the SVG never collapses to
  // a default/zero box regardless of how its CSS height resolves.
  svg.setAttribute('width', String(W))
  svg.setAttribute('height', String(H))
  svg.setAttribute('viewBox', `0 0 ${W} ${H}`)

  // anchor: the active row's number, on the right edge of the rail column
  const activeRow = rowEls.value[current]
  const rb = root.getBoundingClientRect()
  let aX = rect.L - 80
  let aY = rect.T + (rect.B - rect.T) / 2
  if (activeRow) {
    const num = activeRow.querySelector<HTMLElement>('.num') ?? activeRow
    const nb = num.getBoundingClientRect()
    aX = nb.right - rb.left + FRAME_ANCHOR_GAP
    aY = (nb.top + nb.bottom) / 2 - rb.top
  }

  // the frame rectangle sits OUTSIDE the panel by FRAME_OUTSET on each side
  const L = rect.L - FRAME_OUTSET
  const R = rect.R + FRAME_OUTSET
  const T = rect.T - FRAME_OUTSET
  const B = rect.B + FRAME_OUTSET

  const a = antennaLines
  const s = rectLines
  if (a.length < 4 || s.length < 4) return

  setLine(a[0]!, aX, aY, L, T)
  setLine(a[1]!, aX, aY, L, B)
  setLine(a[2]!, aX, aY, R, T)
  setLine(a[3]!, aX, aY, R, B)

  setLine(s[0]!, L, T, R, T)
  setLine(s[1]!, R, T, R, B)
  setLine(s[2]!, R, B, L, B)
  setLine(s[3]!, L, B, L, T)

  const all = [...a, ...s]
  all.forEach((el) => {
    el.style.transition = 'none'
    el.style.strokeDashoffset = el.dataset.len ?? '0'
    el.style.opacity = '1'
  })
  svg.style.transition = 'none'
  svg.style.opacity = '1'

  if (reduced()) {
    all.forEach((el) => {
      el.style.strokeDashoffset = '0'
    })
    a.forEach((el) => {
      el.style.opacity = '0'
    })
    // no draw animation under reduced motion — show the form immediately
    panelEl.value?.classList.remove('is-hidden')
    frameVisible = true
    return
  }

  void svg.getBoundingClientRect() // commit reset before animating

  a.forEach((el) => {
    el.style.transition = `stroke-dashoffset ${FRAME_ANTENNA_MS}ms ease-out`
    el.style.strokeDashoffset = '0'
  })
  s.forEach((el) => {
    el.style.transition = `stroke-dashoffset ${FRAME_RECT_MS}ms linear ${FRAME_ANTENNA_MS}ms`
    el.style.strokeDashoffset = '0'
  })
  after(FRAME_ANTENNA_MS + FRAME_RECT_MS + 20, () => {
    // rectangle is fully drawn — now fade the form in and retract the antennas
    panelEl.value?.classList.remove('is-hidden')
    a.forEach((el) => {
      el.style.transition = `stroke-dashoffset ${FRAME_RETRACT_MS}ms ease-in`
      el.style.strokeDashoffset = String(-(Number(el.dataset.len) || 0))
    })
  })
  frameVisible = true
}

function hideFrame(): void {
  const svg = svgEl.value
  if (!svg) return
  svg.style.transition = reduced() ? 'none' : 'opacity 180ms ease'
  svg.style.opacity = '0'
  frameVisible = false
  panelEl.value?.classList.add('is-hidden')
}

function scheduleFrame(delay: number): void {
  after(delay, showFrame)
}

/* ---- transitions on active-step change ---- */
/** Scroll the framed content back to its top so each step opens at its
 *  beginning rather than inheriting the previous step's scroll position. */
function resetPanelScroll(): void {
  const scroll = panelEl.value?.querySelector<HTMLElement>('.wizchrome__panel-scroll')
  if (scroll) scroll.scrollTop = 0
}

function advanceTo(target: number): void {
  if (target === current) return
  animating = true
  nextTick(resetPanelScroll)
  if (reduced()) {
    current = target
    phase = 'idle'
    paint()
    hideFrame()
    nextTick(() => showFrame())
    animating = false
    return
  }
  if (target > current) {
    // forward: tick (snap up) then drift (leaving row glides to parked)
    leaving = current
    current = target
    hideFrame()
    scheduleFrame(FRAME_SHOW_DELAY)
    phase = 'tick'
    paint()
    after(PHASE2_DELAY, () => {
      phase = 'drift'
      paint()
      after(DRIFT_MS, () => {
        phase = 'idle'
        leaving = -1
        animating = false
        paint()
      })
    })
  } else {
    // back: drift (returning row glides down) then tick (snap to centre)
    returning = target
    phase = 'backdrift'
    hideFrame()
    paint()
    after(DRIFT_MS, () => {
      current = target
      returning = -1
      leaving = -1
      phase = 'tick'
      paint()
      scheduleFrame(FRAME_SHOW_DELAY)
      after(TICK_MS, () => {
        phase = 'idle'
        animating = false
        paint()
      })
    })
  }
}

function onRowClick(step: WizardChromeStep): void {
  if (animating || !step.clickable || step.routeName === null) return
  emit('navigate', step.routeName)
}

/* ---- lifecycle ---- */
let resizeRaf = 0
function onResize(): void {
  window.cancelAnimationFrame(resizeRaf)
  resizeRaf = window.requestAnimationFrame(() => {
    computeLayout()
    const prev = phase
    phase = 'init'
    paint()
    phase = prev === 'init' ? 'idle' : prev
    if (frameVisible && !animating) showFrame()
  })
}

onMounted(() => {
  current = props.activeIndex
  buildFrameLines()
  computeLayout()
  paint()
  nextTick(() => {
    phase = 'idle'
    paint()
    // draw once layout has settled (two frames), with the timer as backup
    requestAnimationFrame(() => requestAnimationFrame(() => showFrame()))
    scheduleFrame(220)
  })
  window.addEventListener('resize', onResize)
  if (document.fonts && 'ready' in document.fonts) {
    void document.fonts.ready.then(() => {
      computeLayout()
      const prev = phase
      phase = 'init'
      paint()
      phase = prev
      if (frameVisible) showFrame()
    })
  }
})

onBeforeUnmount(() => {
  clearTimers()
  window.removeEventListener('resize', onResize)
})

watch(
  () => props.activeIndex,
  (next) => {
    if (next === current || animating) return
    clearTimers()
    advanceTo(next)
  },
)

watch(
  () => props.steps.map((s) => s.status).join('|'),
  () => {
    // statuses can change after a backend re-bootstrap; repaint icons/labels
    nextTick(paint)
  },
)
</script>

<template>
  <div ref="rootEl" class="wizchrome" data-test="onboarding-progress">
    <!-- SVG frame overlay (sits behind the rail + panel) -->
    <svg ref="svgEl" class="wizchrome__frame" aria-hidden="true" style="opacity: 0"></svg>

    <!-- animated title rail -->
    <nav
      class="wizchrome__rail"
      :aria-label="'Onboarding progress'"
      data-test="onboarding-progress-list"
    >
      <div
        v-for="(step, i) in steps"
        :key="step.id"
        :ref="(el) => setRowRef(el, i)"
        class="row"
        :class="{ active: i === activeIndex, clickable: step.clickable }"
        :data-test="`progress-step-${step.id}`"
      >
        <component
          :is="step.clickable ? 'button' : 'div'"
          class="row-inner"
          :type="step.clickable ? 'button' : undefined"
          :aria-current="i === activeIndex ? 'step' : undefined"
          @click="onRowClick(step)"
        >
          <span class="icon" aria-hidden="true">
            <v-icon
              v-if="step.status === 'completed'"
              icon="mdi-check-circle"
              color="success"
              size="18"
            />
            <v-icon
              v-else-if="step.status === 'skipped'"
              icon="mdi-minus-circle-outline"
              color="on-surface-variant"
              size="18"
            />
            <v-icon
              v-else-if="i !== activeIndex"
              icon="mdi-circle-outline"
              color="on-surface-variant"
              size="18"
            />
          </span>
          <span class="title-wrap">
            <span class="title">{{ step.title }}</span>
          </span>
          <span class="num">{{ String(i + 1).padStart(2, '0') }}</span>
        </component>
      </div>
    </nav>

    <!-- full-size, scrollable content panel framed by the SVG -->
    <section ref="panelEl" class="wizchrome__panel is-hidden" data-test="onboarding-body">
      <div class="wizchrome__panel-scroll">
        <slot />
      </div>
    </section>
  </div>
</template>

<style scoped>
.wizchrome {
  position: relative;
  width: 100%;
  height: 100%;
  min-height: 0;
}

.wizchrome__frame {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  pointer-events: none;
  overflow: visible;
  z-index: 3;
}

/* lines are created imperatively (createElementNS) so they carry no
 * scope attribute — :deep() lets the scoped stylesheet reach them. */
.wizchrome__frame :deep(.frame-line) {
  fill: none;
  stroke: rgba(var(--v-theme-on-surface), 0.65);
  stroke-width: 1.5;
  vector-effect: non-scaling-stroke;
}

/* ---- rail ---- */
.wizchrome__rail {
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  width: 40%;
  max-width: 460px;
  z-index: 2;
}

.row {
  position: absolute;
  left: 0;
  width: 100%;
  height: 60px;
  --y: -300px;
  transform: translateY(calc(var(--y) - 30px));
  display: flex;
  align-items: center;
  padding: 0 0 0 48px;
  will-change: transform, opacity;
  pointer-events: none;
}

.row-inner {
  display: flex;
  align-items: center;
  width: 100%;
  gap: 14px;
  pointer-events: auto;
  background: transparent;
  border: none;
  padding: 0;
  margin: 0;
  text-align: left;
  font: inherit;
  color: inherit;
  cursor: default;
}

.row.clickable .row-inner {
  cursor: pointer;
}

.row-inner:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 4px;
  border-radius: 6px;
}

.icon {
  flex: 0 0 auto;
  width: 18px;
  height: 18px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.title-wrap {
  flex: 0 1 auto;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
  transform-origin: left center;
  color: rgba(var(--v-theme-on-surface), 0.5);
  transition:
    transform 440ms cubic-bezier(0.16, 1, 0.3, 1),
    color 440ms cubic-bezier(0.16, 1, 0.3, 1);
}

.row.active .title-wrap {
  color: rgb(var(--v-theme-on-surface));
}

.row.clickable:hover .title-wrap {
  color: rgb(var(--v-theme-on-surface));
}

.title {
  font-size: 26px;
  font-weight: 500;
  letter-spacing: -0.02em;
  line-height: 1.05;
  white-space: nowrap;
  color: inherit;
}

.num {
  flex: 0 0 auto;
  margin-left: auto;
  padding-right: 24px;
  font-size: 12px;
  font-weight: 500;
  letter-spacing: 0.12em;
  font-variant-numeric: tabular-nums;
  color: rgba(var(--v-theme-on-surface), 0.72);
  text-align: right;
  transition:
    color 440ms cubic-bezier(0.16, 1, 0.3, 1),
    font-size 440ms cubic-bezier(0.16, 1, 0.3, 1);
}

.row.active .num {
  color: rgba(var(--v-theme-on-surface), 0.92);
  font-size: 18px;
}

/* ---- panel ---- */
.wizchrome__panel {
  position: absolute;
  left: 40%;
  right: 56px;
  top: 12%;
  bottom: 12%;
  background: rgb(var(--v-theme-surface));
  border-radius: 2px;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  z-index: 1;
  transition:
    opacity 420ms cubic-bezier(0.16, 1, 0.3, 1),
    transform 420ms cubic-bezier(0.16, 1, 0.3, 1);
}

.wizchrome__panel.is-hidden {
  opacity: 0;
  transform: translateY(8px);
}

.wizchrome__panel-scroll {
  overflow-y: auto;
  padding: 32px 40px;
  height: 100%;
}

@media (prefers-reduced-motion: reduce) {
  .title-wrap,
  .num,
  .wizchrome__panel {
    transition-duration: 1ms;
  }
}
</style>
