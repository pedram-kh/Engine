<script setup lang="ts">
/**
 * PL-2.2 — the matrix table of one market (frame 13:1375): a header row (the corner "USP / PERSONA" and one header
 * per persona) and one row per USP in the USP order. The USP column is pinned: it stays while the persona columns scroll
 * sideways inside the table's own box, so the page never scrolls sideways. A persona column is at least 280 px; the
 * pinned column is 230 px, 140 px under 720 px. While the table is scrolled away from the left a soft shadow marks the
 * pinned edge (no frame draws one).
 */
import type { MatrixCell, MatrixPersona, MatrixUsp } from '@catalyst/api-client'
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { brandColorVar } from '@/modules/agency/composables/brandColor'
import { cellKey, leadWith } from '../matrixLogic'
import MatrixCellView from './MatrixCell.vue'
import MatrixPersonaHeader from './MatrixPersonaHeader.vue'

const props = defineProps<{
  usps: MatrixUsp[]
  personas: MatrixPersona[]
  cells: ReadonlyMap<string, MatrixCell>
  /** `persona:usp` of the cell the editor is open on, or null. */
  activeKey: string | null
}>()

const emit = defineEmits<{
  open: [persona: MatrixPersona, usp: MatrixUsp, el: HTMLElement]
}>()

const { t } = useI18n()
const scrolled = ref(false)

function onScroll(e: Event): void {
  scrolled.value = (e.currentTarget as HTMLElement).scrollLeft > 0
}

function label(usp: MatrixUsp, persona: MatrixPersona): string {
  const level = props.cells.get(cellKey(persona.id, usp.id))?.level ?? null
  const state =
    level === null ? t('app.strategy.matrix.notSet') : t(`app.strategy.matrix.level.${level}`)
  return `${usp.title}, ${persona.code}: ${state}`
}
</script>

<template>
  <div class="mx-table" data-test="matrix-table">
    <div class="mx-scroll" data-test="matrix-scroll" @scroll.passive="onScroll">
      <div
        class="mx-grid"
        :class="{ 'mx-grid--scrolled': scrolled }"
        :style="{ '--mx-cols': personas.length }"
      >
        <div class="mx-corner" data-test="matrix-corner">{{ t('app.strategy.matrix.corner') }}</div>
        <MatrixPersonaHeader
          v-for="p in personas"
          :key="p.id"
          :persona="p"
          :lead="leadWith(p.id, usps, cells)"
        />
        <template v-for="u in usps" :key="u.id">
          <div
            class="mx-usp"
            :style="{ '--usp-color': brandColorVar(u.color_key) }"
            data-test="matrix-usp"
            :data-usp-id="u.id"
          >
            <span class="mx-usp__dot" aria-hidden="true" />
            <span class="mx-usp__name" data-test="matrix-usp-name">{{ u.title }}</span>
          </div>
          <MatrixCellView
            v-for="p in personas"
            :key="`${u.id}:${p.id}`"
            :cell="cells.get(cellKey(p.id, u.id)) ?? null"
            :label="label(u, p)"
            :active="activeKey === cellKey(p.id, u.id)"
            :data-persona-id="p.id"
            :data-usp-id="u.id"
            @open="(el: HTMLElement) => emit('open', p, u, el)"
          />
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
/*
 * Values from frame 13:1375: the table is a box of surface fill with a border and radius 10, clipped; the header row is
 * the raised fill; the corner is 230 wide, padding 18 / 20, 11 / 600 with 0.6 tracking; a USP cell is 230 wide, padding
 * 18 / 20, a dot of 8, gap 8 and the name 14 / 600. The scroller is inside the box so only the table scrolls.
 */
.mx-table {
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 10px;
  background: rgb(var(--v-theme-surface));
  overflow: hidden;
}
.mx-scroll {
  overflow-x: auto;
}
.mx-grid {
  --mx-pin: 230px;
  display: grid;
  grid-template-columns: var(--mx-pin) repeat(var(--mx-cols), minmax(280px, 1fr));
  min-width: calc(var(--mx-pin) + var(--mx-cols) * 280px);
}
@media (max-width: 719px) {
  .mx-grid {
    --mx-pin: 140px;
  }
}
.mx-corner,
.mx-usp {
  position: sticky;
  left: 0;
  z-index: 2;
  border-right: 1px solid rgb(var(--v-theme-border-color));
  transition: box-shadow 0.15s;
}
.mx-grid--scrolled .mx-corner,
.mx-grid--scrolled .mx-usp {
  box-shadow: 6px 0 12px -6px var(--agency-matrix-shadow-soft);
}
.mx-corner {
  z-index: 3;
  display: flex;
  align-items: flex-start;
  padding: 18px 20px;
  background: rgb(var(--v-theme-surface-bright));
  font-size: 11px;
  font-weight: 600;
  line-height: normal;
  letter-spacing: 0.6px;
  text-transform: uppercase;
  color: rgb(var(--v-theme-text-tertiary));
}
.mx-usp {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  padding: 18px 20px;
  border-top: 1px solid rgb(var(--v-theme-border-color));
  background: rgb(var(--v-theme-surface));
}
.mx-usp__dot {
  flex: none;
  width: 8px;
  height: 8px;
  margin-top: 5px;
  border-radius: 9999px;
  background: var(--usp-color);
}
.mx-usp__name {
  min-width: 0;
  font-size: 14px;
  font-weight: 600;
  line-height: 18px;
  color: rgb(var(--v-theme-on-surface));
  overflow-wrap: anywhere;
}
@media (max-width: 719px) {
  .mx-corner,
  .mx-usp {
    padding-left: 12px;
    padding-right: 12px;
  }
}
</style>
