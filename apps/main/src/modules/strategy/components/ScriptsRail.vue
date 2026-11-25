<script setup lang="ts">
/**
 * PL-3.2a — the left rail of the scripts step (frame 13:3108, node 13:3293, 240 px): "Scripts · {market}", one card per creator of
 * the market (slot chip in the persona's colour, the name), a row per script with its status dot, "Add script (n/3)", the footnote,
 * and the entry to the archived scripts. Only the Draft status exists until PL-3.3. Under 1100 px the cards sit in one row above the
 * editor and scroll sideways. Names are text interpolations only.
 */
import type { ScriptRail } from '@catalyst/api-client'
import { useI18n } from 'vue-i18n'

import { creatorLabel } from '@/core/creators/creatorLabel'
import { brandColorVar } from '@/modules/agency/composables/brandColor'

defineProps<{
  rail: ScriptRail
  marketName: string
  selectedId: string | null
  archivedCount: number
  busySlot: string | null
  max: number
}>()
const emit = defineEmits<{ select: [id: string]; add: [slot: string]; archived: [] }>()
const { t } = useI18n()
</script>

<template>
  <aside
    class="srail"
    :aria-label="t('app.strategy.scripts.rail.title', { market: marketName })"
    data-test="scripts-rail"
  >
    <div class="srail__scroll">
      <div class="srail__head">
        <h2 class="srail__title">
          {{ t('app.strategy.scripts.rail.title', { market: marketName }) }}
        </h2>
        <span class="srail__count" data-test="scripts-rail-count">{{
          rail.creators.reduce((n, c) => n + c.scripts.length, 0)
        }}</span>
      </div>

      <div
        v-for="c in rail.creators"
        :key="c.id"
        class="srail__card"
        :data-test="`rail-creator-${c.id}`"
      >
        <div class="srail__who">
          <span
            class="srail__chip"
            :style="{
              '--chip': c.persona
                ? brandColorVar(c.persona.color_key)
                : 'rgb(var(--v-theme-on-surface-variant))',
            }"
            >{{ c.slot_code }}</span
          >
          <span class="srail__name">{{
            creatorLabel(c.creator, t, t('app.strategy.creators.card.unnamed'))
          }}</span>
        </div>
        <button
          v-for="s in c.scripts"
          :key="s.id"
          type="button"
          class="srail__script"
          :class="{ 'srail__script--on': s.id === selectedId }"
          :aria-current="s.id === selectedId ? 'true' : undefined"
          :data-test="`rail-script-${s.id}`"
          @click="emit('select', s.id)"
        >
          <span class="srail__script-name">{{
            t('app.strategy.scripts.rail.script', { n: s.number })
          }}</span>
          <span class="srail__status"
            ><span class="srail__dot" aria-hidden="true" />{{
              t('app.strategy.scripts.rail.draft')
            }}</span
          >
        </button>
        <button
          type="button"
          class="srail__add"
          :disabled="!c.can_add_script || busySlot === c.id"
          :title="!c.can_add_script ? t('app.strategy.scripts.rail.limitHint') : undefined"
          :data-test="`rail-add-${c.id}`"
          @click="emit('add', c.id)"
        >
          <v-icon icon="mdi-plus" size="12" aria-hidden="true" />
          {{
            c.can_add_script
              ? t('app.strategy.scripts.rail.add', { n: c.scripts.length, max })
              : t('app.strategy.scripts.rail.limit', { max })
          }}
        </button>
      </div>

      <p class="srail__foot" data-test="scripts-rail-foot">
        {{ t('app.strategy.scripts.rail.footnote', { max }) }}
      </p>
    </div>
    <v-btn
      v-if="archivedCount > 0"
      variant="outlined"
      size="small"
      class="srail__archived"
      prepend-icon="mdi-archive-outline"
      data-test="scripts-archived-open"
      @click="emit('archived')"
    >
      {{ t('app.strategy.scripts.rail.archived', { count: archivedCount }) }}
    </v-btn>
  </aside>
</template>

<style scoped>
.srail,
.srail__scroll {
  display: flex;
  flex-direction: column;
  gap: 10px;
  min-width: 0;
}
.srail__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.srail__title {
  margin: 0;
  font-size: 15px;
  font-weight: 600;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface));
}
.srail__count {
  font-size: 12px;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
}
.srail__card {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 10px;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 10px;
  background: rgb(var(--v-theme-surface));
}
.srail__who {
  display: flex;
  align-items: center;
  gap: 8px;
  padding-bottom: 4px;
  min-width: 0;
}
.srail__chip {
  flex: none;
  padding: 1px 5px;
  border: 1px solid color-mix(in srgb, var(--chip) 70%, transparent);
  border-radius: 4px;
  font-size: 10px;
  font-weight: 700;
  line-height: normal;
  color: var(--chip);
}
.srail__name {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 13px;
  font-weight: 600;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface));
}
.srail__script {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  width: 100%;
  padding: 7px 8px;
  border: 1px solid transparent;
  border-radius: 6px;
  background: none;
  font: inherit;
  font-size: 13px;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface-variant));
  cursor: pointer;
  text-align: left;
}
.srail__script:hover {
  background: rgb(var(--v-theme-surface-bright));
}
.srail__script--on {
  border-color: color-mix(in srgb, rgb(var(--v-theme-primary)) 50%, transparent);
  background: color-mix(in srgb, rgb(var(--v-theme-primary)) 10%, transparent);
  font-weight: 500;
  color: rgb(var(--v-theme-on-surface));
}
.srail__status {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 11px;
  font-weight: 400;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
}
.srail__dot {
  width: 7px;
  height: 7px;
  border-radius: 9999px;
  background: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
}
.srail__add {
  display: flex;
  align-items: center;
  gap: 6px;
  width: 100%;
  padding: 5px 8px;
  border: 0;
  border-radius: 6px;
  background: none;
  font: inherit;
  font-size: 12px;
  line-height: normal;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
  cursor: pointer;
  text-align: left;
}
.srail__add:hover:not(:disabled) {
  color: rgb(var(--v-theme-on-surface));
  background: rgb(var(--v-theme-surface-bright));
}
.srail__add:disabled {
  cursor: default;
  opacity: 0.7;
}
.srail__foot {
  margin: 0;
  font-size: 11px;
  line-height: 16px;
  color: rgb(var(--v-theme-text-tertiary, var(--v-theme-on-surface-variant)));
}
/* A secondary action like "Add hook" in the editor: outlined, 13 / 500, 6 x 12, radius 8, the border colour of the cards. */
.srail__archived {
  align-self: flex-start;
  flex: none;
  height: auto !important;
  padding: 6px 12px !important;
  border-color: rgb(var(--v-theme-border-color)) !important;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 500;
  line-height: normal;
  letter-spacing: normal;
  text-transform: none;
}
.srail__archived :deep(.v-btn__prepend) {
  margin-inline: 0 8px;
}
.srail__archived :deep(.v-icon) {
  width: 14px;
  height: 14px;
  font-size: 14px;
}

/* Narrow: one row of creator cards above the editor, sideways scroll inside the row. The "Archived scripts" button is NOT in the
   scrolling row: it sits on its own line under it, so it is reachable without sideways scrolling. */
@media (max-width: 1099px) {
  .srail__scroll {
    flex-direction: row;
    align-items: stretch;
    gap: 10px;
    overflow-x: auto;
    padding-bottom: 4px;
  }
  .srail__head,
  .srail__foot {
    flex: none;
    align-self: center;
  }
  .srail__card {
    flex: 0 0 200px;
  }
}
</style>
