<script setup lang="ts">
/**
 * AnimatedWizardChromeMobile — the mobile (smAndDown) wizard presentation.
 *
 * A horizontal variant of {@link AnimatedWizardChrome}: the step rail rides
 * along the TOP instead of the left. Only the ACTIVE step shows its title —
 * in a rectangle locked to the horizontal centre of the rail; every other
 * step shows just its number, completed ones parked left, upcoming ones
 * parked right. Below the rail, the SAME imperative SVG "frame" draws around
 * a full-size, scrollable content panel (the default slot).
 *
 * Advancing plays the desktop "tick" feel, re-staged for the X axis:
 *   1. SNAP   — the incoming step's number slides into the centre slot, the
 *      outgoing one collapses to a number box and parks left; every other
 *      number slides to its new spot (mechanical snap, `TICK_MS`).
 *   2. FRAME  — antenna lines grow DOWN from the active box to the panel's
 *      corners, the rectangle draws, the panel fades in, antennas retract.
 *   3. TYPE   — the active title types in char-by-char (CSS `steps()` width
 *      reveal) and the box grows to fit; the parked numbers track the
 *      growing box so they stay hugged to its edges.
 *
 * This component owns ONLY presentation + motion. It mirrors the desktop
 * chrome's data contract ({@link WizardChromeStep}[] + `activeIndex` +
 * `reducedMotion`) so the layout feeds it the exact same values and just
 * picks the component by breakpoint. The SVG frame routine is intentionally
 * DUPLICATED from the desktop chrome (not refactored into a shared unit) so
 * the delicate, test-pinned desktop animator stays untouched.
 *
 * a11y: the rail is a `<nav>` landmark; the active chip carries
 * `aria-current="step"`; numbers are decorative. Honours
 * `prefers-reduced-motion` (passed as `reducedMotion`) by collapsing every
 * transition to ~0ms, revealing the title instantly, and drawing the frame
 * without the grow animation.
 */

import { nextTick, onBeforeUnmount, onMounted, ref, watch, type ComponentPublicInstance } from 'vue'

import type { WizardChromeStep } from './AnimatedWizardChrome.vue'

type TemplateRefEl = Element | ComponentPublicInstance | null

const props = defineProps<{
  steps: WizardChromeStep[]
  activeIndex: number
  reducedMotion?: boolean
}>()

const emit = defineEmits<{ (e: 'navigate', routeName: string): void }>()

/* ---- motion constants (mirror the desktop chrome) ---- */
const TICK_MS = 320
const E_TICK = 'cubic-bezier(.2, 1.1, .3, 1)'
const TYPE_CHAR_MS = 28
const TYPE_MIN_MS = 220

const FRAME_ANTENNA_MS = 220
const FRAME_RECT_MS = 420
const FRAME_RETRACT_MS = 260
const FRAME_SHOW_DELAY = 360

/* ---- chip geometry (px) ---- */
/** Parked numbers sit at fixed, index-based slots anchored to the rail edges:
 *  completed at the LEFT, upcoming at the RIGHT. `PARK_STRIDE` is the spacing
 *  between adjacent slots; `EDGE_MARGIN` the inset from the rail edge to the
 *  first slot. The active step is locked to the rail's horizontal centre, so
 *  only the two transitioning steps ever move. */
const PARK_STRIDE = 23
const EDGE_MARGIN = 22
const NUM_HALF = 9
const PILL_GAP = 8
const TITLE_FONT_BASE = 18
const FONT_FLOOR = 13

const clamp = (v: number, lo: number, hi: number): number => Math.max(lo, Math.min(hi, v))

/* ---- refs ---- */
const rootEl = ref<HTMLElement | null>(null)
const railEl = ref<HTMLElement | null>(null)
const panelEl = ref<HTMLElement | null>(null)
/** Two frame layers: the antennas draw on a layer BEHIND the panel (so they
 *  vanish behind the opaque content as they retract — "under the content
 *  inside the frame"), while the rectangle border draws on a layer ABOVE the
 *  panel so it still outlines the content. */
const svgUnderEl = ref<SVGSVGElement | null>(null)
const svgOverEl = ref<SVGSVGElement | null>(null)
const chipEls = ref<HTMLElement[]>([])

function setChipRef(el: TemplateRefEl, i: number): void {
  if (el instanceof HTMLElement) chipEls.value[i] = el
}

/** The active chip's title-clip wrapper / inner span. Queried from the
 *  active chip rather than held as a `v-for`-nested template ref (which Vue
 *  would collect as an array). The wrapper's width is animated 0 → full to
 *  produce the typewriter reveal + box growth. */
function activeChip(): HTMLElement | null {
  return chipEls.value[current] ?? null
}
function activeTitleWrap(): HTMLElement | null {
  return chipEls.value[current]?.querySelector<HTMLElement>('.chip__title-wrap') ?? null
}
function activeTitleSpan(): HTMLElement | null {
  return chipEls.value[current]?.querySelector<HTMLElement>('.chip__title') ?? null
}

/* ---- SVG frame lines (createElementNS — see desktop chrome's note) ---- */
const SVGNS = 'http://www.w3.org/2000/svg'
let antennaLines: SVGLineElement[] = []
let rectLines: SVGLineElement[] = []

function buildFrameLines(): void {
  const svgUnder = svgUnderEl.value
  const svgOver = svgOverEl.value
  if (!svgUnder || !svgOver) return
  antennaLines = []
  rectLines = []
  const make = (cls: string, bucket: SVGLineElement[], svg: SVGSVGElement): void => {
    const line = document.createElementNS(SVGNS, 'line')
    line.setAttribute('class', `frame-line ${cls}`)
    line.style.fill = 'none'
    line.style.stroke = 'rgba(var(--v-theme-on-surface), 0.7)'
    line.style.strokeWidth = '1.5'
    bucket.push(line)
    svg.appendChild(line)
  }
  // antennas → behind the panel; rectangle border → above the panel
  for (let i = 0; i < 4; i++) make('frame-antenna', antennaLines, svgUnder)
  for (let i = 0; i < 4; i++) make('frame-rect', rectLines, svgOver)
}

/* ---- phase state ---- */
type Phase = 'init' | 'idle' | 'snap' | 'type'
let phase: Phase = 'init'
let current = props.activeIndex
let animating = false
const timers: number[] = []
let trackRaf = 0

function clearTimers(): void {
  for (const t of timers) window.clearTimeout(t)
  timers.length = 0
  if (trackRaf) {
    window.cancelAnimationFrame(trackRaf)
    trackRaf = 0
  }
}
function after(ms: number, fn: () => void): void {
  timers.push(window.setTimeout(fn, ms))
}
function reduced(): boolean {
  return props.reducedMotion === true
}

/* ---- horizontal layout: fixed, edge-anchored slots ---- */
/** Centre (px from the rail's left edge) of the k-th completed slot. */
function slotCentreLeft(k: number): number {
  return EDGE_MARGIN + k * PARK_STRIDE
}
/** Centre (px from the rail's left edge) of an upcoming step pinned to the
 *  right, where `fromEnd` is its distance (in steps) from the last step. */
function slotCentreRight(fromEnd: number, railW: number): number {
  return railW - EDGE_MARGIN - fromEnd * PARK_STRIDE
}

/** Set each chip's centre offset (`--cx`, px from the rail's centre). The
 *  active chip is locked to centre (0); completed chips keep fixed slots on
 *  the far left and upcoming chips keep fixed slots on the far right, so a
 *  parked number never shifts when a different step is selected. */
function positionChips(): void {
  const railW = railEl.value?.clientWidth ?? 0
  const half = railW / 2
  const n = props.steps.length
  for (let i = 0; i < n; i++) {
    const el = chipEls.value[i]
    if (!el) continue
    let cx = 0
    if (i < current) cx = slotCentreLeft(i) - half
    else if (i > current) cx = slotCentreRight(n - 1 - i, railW) - half
    el.style.setProperty('--cx', `${cx}px`)
    el.style.transition = chipTransition()
  }
}

function chipTransition(): string {
  if (reduced()) return 'transform 1ms'
  if (phase === 'snap') return `transform ${TICK_MS}ms ${E_TICK}`
  // during 'type' we repaint every rAF, so no CSS easing is needed
  return phase === 'type' ? 'transform 0ms' : `transform ${TICK_MS}ms ${E_TICK}`
}

/* ---- title typewriter (CSS steps width reveal) + font auto-fit ---- */
function fitAndMeasureTitle(): number {
  const wrap = activeTitleWrap()
  const span = activeTitleSpan()
  if (!wrap || !span) return 0
  span.style.fontSize = `${TITLE_FONT_BASE}px`
  // The active pill is centred and grows symmetrically, so the room it has is
  // twice the distance from centre to the nearest parked cluster's inner edge.
  const railW = railEl.value?.clientWidth ?? 0
  const half = railW / 2
  const n = props.steps.length
  const leftInner = current > 0 ? slotCentreLeft(current - 1) + NUM_HALF + PILL_GAP : EDGE_MARGIN
  const rightInner =
    current < n - 1
      ? slotCentreRight(n - 1 - (current + 1), railW) - NUM_HALF - PILL_GAP
      : railW - EDGE_MARGIN
  const availHalf = Math.max(40, Math.min(half - leftInner, rightInner - half))
  const maxPill = availHalf * 2
  const numW = activeChip()?.querySelector<HTMLElement>('.chip__num')?.offsetWidth ?? 14
  const avail = Math.max(24, maxPill - numW - PILL_GAP)
  let full = span.scrollWidth
  if (full > avail && full > 0) {
    const scaled = clamp(TITLE_FONT_BASE * (avail / full), FONT_FLOOR, TITLE_FONT_BASE)
    span.style.fontSize = `${scaled}px`
    full = span.scrollWidth
  }
  return full
}

function startTypewriter(): void {
  const wrap = activeTitleWrap()
  if (!wrap) {
    phase = 'idle'
    animating = false
    return
  }
  const full = fitAndMeasureTitle()
  if (reduced() || full === 0) {
    wrap.style.transition = 'none'
    wrap.style.width = `${full}px`
    phase = 'idle'
    animating = false
    positionChips()
    return
  }
  const len = props.steps[current]?.title.length ?? 1
  const dur = Math.max(TYPE_MIN_MS, len * TYPE_CHAR_MS)
  wrap.style.transition = 'none'
  wrap.style.width = '0px'
  void wrap.getBoundingClientRect() // commit the 0 before animating
  wrap.style.transition = `width ${dur}ms steps(${Math.max(1, len)})`
  wrap.style.width = `${full}px`
  // The active box is centred and grows symmetrically, and parked numbers are
  // pinned to fixed edge slots, so nothing else has to move while it types.
  phase = 'type'
  after(dur, () => {
    phase = 'idle'
    animating = false
  })
}

/* ---- SVG frame (anchored at the active box's bottom edge, drawn down) ---- */
let frameVisible = false

function panelRect(): { L: number; R: number; T: number; B: number } | null {
  const root = rootEl.value
  const panel = panelEl.value
  if (!root || !panel) return null
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

function sizeSvg(svg: SVGSVGElement, W: number, H: number): void {
  svg.setAttribute('width', String(W))
  svg.setAttribute('height', String(H))
  svg.setAttribute('viewBox', `0 0 ${W} ${H}`)
}

function showFrame(): void {
  const svgUnder = svgUnderEl.value
  const svgOver = svgOverEl.value
  const root = rootEl.value
  const rect = panelRect()
  if (!svgUnder || !svgOver || !root || !rect) return
  if (current < 0 || current >= props.steps.length) return

  const W = root.clientWidth
  const H = root.clientHeight
  if (W === 0 || H === 0) return
  sizeSvg(svgUnder, W, H)
  sizeSvg(svgOver, W, H)

  // anchor: the active chip's bottom-centre
  const rb = root.getBoundingClientRect()
  const activeChip = chipEls.value[current]
  let aX = W / 2
  let aY = rect.T - 12
  if (activeChip) {
    const cb = activeChip.getBoundingClientRect()
    aX = (cb.left + cb.right) / 2 - rb.left
    aY = cb.bottom - rb.top
  }

  const { L, R, T, B } = rect
  const a = antennaLines
  const s = rectLines
  if (a.length < 4 || s.length < 4) return

  setLine(a[0]!, aX, aY, L, T)
  setLine(a[1]!, aX, aY, R, T)
  setLine(a[2]!, aX, aY, L, B)
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
  svgUnder.style.transition = 'none'
  svgUnder.style.opacity = '1'
  svgOver.style.transition = 'none'
  svgOver.style.opacity = '1'

  if (reduced()) {
    all.forEach((el) => {
      el.style.strokeDashoffset = '0'
    })
    a.forEach((el) => {
      el.style.opacity = '0'
    })
    panelEl.value?.classList.remove('is-hidden')
    frameVisible = true
    return
  }

  void svgUnder.getBoundingClientRect()

  a.forEach((el) => {
    el.style.transition = `stroke-dashoffset ${FRAME_ANTENNA_MS}ms ease-out`
    el.style.strokeDashoffset = '0'
  })
  s.forEach((el) => {
    el.style.transition = `stroke-dashoffset ${FRAME_RECT_MS}ms linear ${FRAME_ANTENNA_MS}ms`
    el.style.strokeDashoffset = '0'
  })
  after(FRAME_ANTENNA_MS + FRAME_RECT_MS + 20, () => {
    panelEl.value?.classList.remove('is-hidden')
    a.forEach((el) => {
      el.style.transition = `stroke-dashoffset ${FRAME_RETRACT_MS}ms ease-in`
      el.style.strokeDashoffset = String(-(Number(el.dataset.len) || 0))
    })
  })
  frameVisible = true
}

function hideFrame(): void {
  const trans = reduced() ? 'none' : 'opacity 160ms ease'
  for (const svg of [svgUnderEl.value, svgOverEl.value]) {
    if (!svg) continue
    svg.style.transition = trans
    svg.style.opacity = '0'
  }
  frameVisible = false
  panelEl.value?.classList.add('is-hidden')
}

/* ---- the active-step change sequence: snap → frame → type ---- */
function advanceTo(target: number): void {
  if (target === current) return
  animating = true
  clearTimers()

  if (reduced()) {
    current = target
    phase = 'idle'
    hideFrame()
    nextTick(() => {
      startTypewriter()
      showFrame()
      animating = false
    })
    return
  }

  // 1) SNAP — collapse the title, slide the incoming number to centre.
  current = target
  phase = 'snap'
  hideFrame()
  nextTick(() => {
    const wrap = activeTitleWrap()
    if (wrap) {
      wrap.style.transition = 'none'
      wrap.style.width = '0px'
    }
    positionChips()
  })

  // 2) FRAME — after the snap settles, draw the frame down to the panel.
  after(FRAME_SHOW_DELAY, () => showFrame())

  // 3) TYPE — once the rectangle is drawn, type the title + grow the box.
  after(FRAME_SHOW_DELAY + FRAME_ANTENNA_MS + FRAME_RECT_MS, () => {
    startTypewriter()
  })
}

function onChipClick(step: WizardChromeStep): void {
  if (animating || !step.clickable || step.routeName === null) return
  emit('navigate', step.routeName)
}

/* ---- lifecycle ---- */
function repaintStatic(): void {
  phase = 'idle'
  positionChips()
}

let resizeRaf = 0
function onResize(): void {
  window.cancelAnimationFrame(resizeRaf)
  resizeRaf = window.requestAnimationFrame(() => {
    if (animating) return
    const full = fitAndMeasureTitle()
    const wrap = activeTitleWrap()
    if (wrap) {
      wrap.style.transition = 'none'
      wrap.style.width = `${full}px`
    }
    positionChips()
    if (frameVisible) showFrame()
  })
}

onMounted(() => {
  current = props.activeIndex
  buildFrameLines()
  nextTick(() => {
    const wrap = activeTitleWrap()
    const full = fitAndMeasureTitle()
    if (wrap) {
      wrap.style.transition = 'none'
      wrap.style.width = `${full}px`
    }
    repaintStatic()
    requestAnimationFrame(() => requestAnimationFrame(() => showFrame()))
    after(FRAME_SHOW_DELAY, () => showFrame())
  })
  window.addEventListener('resize', onResize)
  if (typeof document !== 'undefined' && document.fonts && 'ready' in document.fonts) {
    void document.fonts.ready.then(() => {
      if (animating) return
      fitAndMeasureTitle()
      positionChips()
      if (frameVisible) showFrame()
    })
  }
})

onBeforeUnmount(() => {
  clearTimers()
  window.removeEventListener('resize', onResize)
  window.cancelAnimationFrame(resizeRaf)
})

watch(
  () => props.activeIndex,
  (next) => {
    if (next === current || animating) return
    advanceTo(next)
  },
)

watch(
  () => props.steps.map((s) => s.status).join('|'),
  () => {
    if (!animating) nextTick(() => positionChips())
  },
)
</script>

<template>
  <div ref="rootEl" class="wizm" data-test="onboarding-progress">
    <!-- Antenna layer: BEHIND the panel, so retracting antennas vanish behind
         the opaque content instead of sweeping over it. -->
    <svg
      ref="svgUnderEl"
      class="wizm__frame wizm__frame--under"
      aria-hidden="true"
      style="opacity: 0"
    ></svg>

    <!-- horizontal step rail -->
    <nav
      ref="railEl"
      class="wizm__rail"
      data-test="onboarding-progress-list"
      data-test-mobile="onboarding-mobile-chrome"
      :aria-label="'Onboarding progress'"
    >
      <component
        :is="step.clickable ? 'button' : 'div'"
        v-for="(step, i) in steps"
        :key="step.id"
        :ref="(el: TemplateRefEl) => setChipRef(el, i)"
        class="chip"
        :class="{
          'chip--active': i === activeIndex,
          'chip--clickable': step.clickable,
          'chip--completed': step.status === 'completed',
          'chip--skipped': step.status === 'skipped',
        }"
        :type="step.clickable ? 'button' : undefined"
        :aria-current="i === activeIndex ? 'step' : undefined"
        :data-test="`progress-step-${step.id}`"
        @click="onChipClick(step)"
      >
        <span class="chip__num">{{ i + 1 }}</span>
        <span v-if="i === activeIndex" class="chip__title-wrap">
          <span class="chip__title">{{ step.title }}</span>
        </span>
      </component>
    </nav>

    <!-- full-size, scrollable content panel framed by the SVG -->
    <section ref="panelEl" class="wizm__panel is-hidden" data-test="onboarding-body">
      <div class="wizm__panel-scroll">
        <slot />
      </div>
    </section>

    <!-- Rectangle-border layer: ABOVE the panel, so the frame outlines the
         content. -->
    <svg
      ref="svgOverEl"
      class="wizm__frame wizm__frame--over"
      aria-hidden="true"
      style="opacity: 0"
    ></svg>
  </div>
</template>

<style scoped>
.wizm {
  position: relative;
  width: 100%;
  height: 100%;
  min-height: 0;
}

.wizm__frame {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  pointer-events: none;
  overflow: visible;
}

/* antennas sit behind the panel (z 0 < panel z 1); the rectangle border sits
 * above it (z 3 > panel z 1) so it reads as the content's frame. */
.wizm__frame--under {
  z-index: 0;
}

.wizm__frame--over {
  z-index: 3;
}

.wizm__frame :deep(.frame-line) {
  fill: none;
  stroke: rgba(var(--v-theme-on-surface), 0.65);
  stroke-width: 1.5;
  vector-effect: non-scaling-stroke;
}

/* ---- rail ---- */
.wizm__rail {
  position: absolute;
  left: 0;
  right: 0;
  top: 0;
  height: 64px;
  z-index: 2;
}

.chip {
  /* vivid, unmistakable "go" green for the active step */
  --chip-active: #16a34a;
  position: absolute;
  left: 50%;
  top: 50%;
  transform: translate(calc(-50% + var(--cx, 0px)), -50%);
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 3px;
  /* rectangle outline in the chip's own colour (no fill, no rounded edges) */
  border: 1px solid currentColor;
  border-radius: 0;
  background: transparent;
  color: rgba(var(--v-theme-on-surface), 0.4);
  font: inherit;
  cursor: default;
  white-space: nowrap;
  will-change: transform;
}

.chip--clickable {
  cursor: pointer;
}

.chip--completed {
  color: rgb(var(--v-theme-on-surface));
}

.chip--skipped {
  color: rgba(var(--v-theme-on-surface), 0.5);
}

/* active step: vivid green rectangle + text (border picks up currentColor) */
.chip--active {
  color: var(--chip-active);
  z-index: 1;
}

.chip:focus-visible {
  outline: 2px solid var(--chip-active);
  outline-offset: 3px;
  border-radius: 4px;
}

.chip__num {
  flex: 0 0 auto;
  font-size: 13px;
  font-weight: 600;
  letter-spacing: 0.02em;
  font-variant-numeric: tabular-nums;
}

.chip--active .chip__num {
  font-size: 15px;
  font-weight: 700;
}

/* the typewriter clip: width is animated from 0 → measured full width */
.chip__title-wrap {
  display: inline-block;
  overflow: hidden;
  white-space: nowrap;
  width: 0;
}

.chip__title {
  display: inline-block;
  font-size: 18px;
  font-weight: 600;
  letter-spacing: -0.01em;
  line-height: 1;
  white-space: nowrap;
  color: var(--chip-active);
}

/* ---- panel ---- */
.wizm__panel {
  position: absolute;
  left: 12px;
  right: 12px;
  top: 80px;
  bottom: 12px;
  background: rgb(var(--v-theme-surface));
  border-radius: 2px;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  z-index: 1;
  transition:
    opacity 380ms cubic-bezier(0.16, 1, 0.3, 1),
    transform 380ms cubic-bezier(0.16, 1, 0.3, 1);
}

.wizm__panel.is-hidden {
  opacity: 0;
  transform: translateY(8px);
}

.wizm__panel-scroll {
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
  padding: 20px 18px;
  height: 100%;
}

@media (prefers-reduced-motion: reduce) {
  .chip,
  .wizm__panel {
    transition-duration: 1ms;
  }
}
</style>
