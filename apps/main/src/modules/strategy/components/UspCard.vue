<script setup lang="ts">
/**
 * PL-2.1 — one USP card (frame: number chip in the card's colour, title, three-dot menu, EXPLAINER, CREATIVE ANGLE,
 * and COPY SEED DIRECTIONS in a tinted quote block). A field with no text is left out. Every string from the
 * server is drawn with `{{ }}` interpolation, never as HTML.
 *
 * The number is the card's position in the list as it is shown, so it follows a drag at once; the colour belongs
 * to the card. The grip is the drag handle; Move earlier / Move later in the menu are the keyboard route.
 */
import type { WaveUspResource } from '@catalyst/api-client'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { brandColorVar } from '@/modules/agency/composables/brandColor'
import { MENU_PROPS } from '../constants'

const props = defineProps<{
  usp: WaveUspResource
  /** 1-based position as shown. */
  index: number
  total: number
  /** Admin or manager. Staff cannot delete a USP that personas use. */
  canDeleteInUse: boolean
  busy: boolean
}>()

const emit = defineEmits<{
  edit: [usp: WaveUspResource]
  remove: [usp: WaveUspResource]
  earlier: [usp: WaveUspResource]
  later: [usp: WaveUspResource]
}>()

const { t } = useI18n()

const menuOpen = ref(false)
const a = computed(() => props.usp.attributes)
const number = computed(() => String(props.index).padStart(2, '0'))
const deleteBlocked = computed(() => a.value.angle_count > 0 && !props.canDeleteInUse)

function pick(action: 'edit' | 'remove' | 'earlier' | 'later'): void {
  menuOpen.value = false
  if (action === 'edit') emit('edit', props.usp)
  else if (action === 'remove') emit('remove', props.usp)
  else if (action === 'earlier') emit('earlier', props.usp)
  else emit('later', props.usp)
}
</script>

<template>
  <article
    class="usp-card"
    :style="{ '--usp-color': brandColorVar(a.color_key) }"
    data-test="usp-card"
    :data-usp-id="usp.id"
  >
    <header class="usp-card__head">
      <span class="usp-grip" aria-hidden="true" data-test="usp-grip">
        <v-icon icon="mdi-drag" size="20" />
      </span>
      <span class="usp-card__num" data-test="usp-number">{{ number }}</span>
      <h3 class="usp-card__title" data-test="usp-title">{{ a.title }}</h3>
      <v-menu v-model="menuOpen" v-bind="MENU_PROPS" location="bottom end">
        <template #activator="{ props: act }">
          <v-btn
            v-bind="act"
            icon="mdi-dots-vertical"
            variant="text"
            size="small"
            :aria-label="t('app.strategy.usp.menu', { title: a.title })"
            :disabled="busy"
            data-test="usp-menu-btn"
          />
        </template>
        <v-list density="compact" data-test="usp-menu">
          <v-list-item data-test="usp-menu-edit" @click="pick('edit')">
            <v-list-item-title>{{ t('app.strategy.usp.edit') }}</v-list-item-title>
          </v-list-item>
          <v-list-item
            :disabled="index === 1"
            data-test="usp-menu-earlier"
            @click="pick('earlier')"
          >
            <v-list-item-title>{{ t('app.strategy.usp.moveEarlier') }}</v-list-item-title>
          </v-list-item>
          <v-list-item
            :disabled="index === total"
            data-test="usp-menu-later"
            @click="pick('later')"
          >
            <v-list-item-title>{{ t('app.strategy.usp.moveLater') }}</v-list-item-title>
          </v-list-item>
          <v-list-item
            :disabled="deleteBlocked"
            data-test="usp-menu-delete"
            @click="pick('remove')"
          >
            <v-list-item-title>{{ t('app.strategy.usp.delete') }}</v-list-item-title>
            <v-list-item-subtitle v-if="deleteBlocked" data-test="usp-delete-reason">
              {{ t('app.strategy.usp.deleteStaffReason') }}
            </v-list-item-subtitle>
          </v-list-item>
        </v-list>
      </v-menu>
    </header>

    <section v-if="a.explainer" class="usp-card__block" data-test="usp-explainer">
      <h4 class="usp-card__label">{{ t('app.strategy.usp.explainer') }}</h4>
      <p class="usp-card__text">{{ a.explainer }}</p>
    </section>
    <section v-if="a.creative_angle" class="usp-card__block" data-test="usp-creative-angle">
      <h4 class="usp-card__label">{{ t('app.strategy.usp.creativeAngle') }}</h4>
      <p class="usp-card__text">{{ a.creative_angle }}</p>
    </section>
    <section v-if="a.copy_seed" class="usp-card__block usp-card__quote" data-test="usp-copy-seed">
      <h4 class="usp-card__label">{{ t('app.strategy.usp.copySeed') }}</h4>
      <p class="usp-card__text">{{ a.copy_seed }}</p>
    </section>

    <footer v-if="a.angle_count > 0" class="usp-card__foot" data-test="usp-angle-count">
      {{ t('app.strategy.usp.usedBy', { count: a.angle_count }, a.angle_count) }}
    </footer>
  </article>
</template>

<style scoped>
.usp-card {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 16px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  border-top: 3px solid var(--usp-color);
  border-radius: 12px;
  background: rgb(var(--v-theme-surface));
  min-width: 0;
}
.usp-card__head {
  display: flex;
  align-items: center;
  gap: 8px;
}
.usp-grip {
  display: inline-flex;
  cursor: grab;
  color: rgba(var(--v-theme-on-surface), 0.55);
  touch-action: none;
}
.usp-card__num {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 28px;
  height: 24px;
  padding: 0 6px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 700;
  color: rgb(var(--v-theme-on-surface));
  background: color-mix(in srgb, var(--usp-color) 22%, transparent);
  border: 1px solid var(--usp-color);
}
.usp-card__title {
  flex: 1 1 auto;
  min-width: 0;
  margin: 0;
  font-size: 16px;
  font-weight: 600;
  overflow-wrap: anywhere;
}
.usp-card__label {
  margin: 0 0 4px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), 0.65);
}
.usp-card__text {
  margin: 0;
  font-size: 14px;
  white-space: pre-line;
  overflow-wrap: anywhere;
}
.usp-card__quote {
  padding: 10px 12px;
  border-left: 3px solid var(--usp-color);
  border-radius: 6px;
  background: color-mix(in srgb, var(--usp-color) 12%, transparent);
}
.usp-card__foot {
  font-size: 12px;
  color: rgba(var(--v-theme-on-surface), 0.65);
}
</style>
