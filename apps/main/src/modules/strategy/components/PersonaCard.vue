<script setup lang="ts">
/**
 * PL-2.1 — one persona card (frame: tinted header band, initials avatar, code chip, title, the name,
 * three-dot menu, the body blocks, then the creative angles). A block with no text is left out. The labels of the
 * self-talk and need blocks follow the pronoun: "What she tells herself" / "What he needs" / "What they tell
 * themselves". The colour is derived from the slot on the server. Every string is drawn with interpolation.
 */
import type { StrategyLimits, WavePersonaResource, WaveUspResource } from '@catalyst/api-client'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { brandColorVar } from '@/modules/agency/composables/brandColor'
import type { WaveScope } from '../api/strategy.api'
import { MENU_PROPS } from '../constants'
import AngleBlock from './AngleBlock.vue'

const props = defineProps<{
  persona: WavePersonaResource
  usps: WaveUspResource[]
  scope: WaveScope
  limits: StrategyLimits
  brandId: string
  waveId: string
}>()

const emit = defineEmits<{
  edit: [persona: WavePersonaResource]
  remove: [persona: WavePersonaResource]
  changed: []
}>()

const { t } = useI18n()
const a = computed(() => props.persona.attributes)
const menuOpen = ref(false)

/**
 * Two letters, as the frame draws them ("Zuzana" is "ZU"): the first letters of the first two words, or the first two
 * letters of a single word.
 */
const initials = computed(() => {
  const words = a.value.name.split(/\s+/).filter((w) => w !== '')
  const letters =
    words.length >= 2
      ? words.slice(0, 2).map((w) => Array.from(w)[0] ?? '')
      : Array.from(words[0] ?? '').slice(0, 2)
  return letters.join('').toLocaleUpperCase()
})

/** The line icon and its colour (a theme token) of each block, as the frame draws them. */
const lists = computed(() => [
  {
    key: 'drivers',
    label: t('app.strategy.persona.drivers'),
    items: a.value.drivers,
    icon: 'mdi-heart-outline',
    tone: 'pink',
  },
  {
    key: 'goals',
    label: t('app.strategy.persona.goals'),
    items: a.value.goals,
    icon: 'mdi-target',
    tone: 'error',
  },
  {
    key: 'self-talk',
    label: t(`app.strategy.persona.selfTalk.${a.value.pronoun}`),
    items: a.value.self_talk,
    icon: 'mdi-message-outline',
    tone: 'info',
  },
])

function pick(action: 'edit' | 'remove'): void {
  menuOpen.value = false
  if (action === 'edit') emit('edit', props.persona)
  else emit('remove', props.persona)
}
</script>

<template>
  <article
    class="persona-card"
    :style="{ '--usp-color': brandColorVar(a.color_key) }"
    data-test="persona-card"
    :data-persona-id="persona.id"
  >
    <header class="persona-card__head">
      <span class="persona-card__avatar" aria-hidden="true" data-test="persona-avatar">{{
        initials
      }}</span>
      <div class="persona-card__who">
        <div class="persona-card__line">
          <span class="persona-card__code" data-test="persona-code">{{ a.code }}</span>
          <h3 class="persona-card__title" data-test="persona-title">{{ a.title }}</h3>
        </div>
        <div class="persona-card__name" data-test="persona-name">
          {{ a.name }}
        </div>
      </div>
      <v-menu v-model="menuOpen" v-bind="MENU_PROPS" location="bottom end">
        <template #activator="{ props: act }">
          <v-btn
            v-bind="act"
            variant="text"
            size="small"
            class="persona-card__menu"
            :aria-label="t('app.strategy.persona.menu', { name: a.name })"
            data-test="persona-menu-btn"
          >
            <v-icon icon="mdi-dots-horizontal" size="18" />
          </v-btn>
        </template>
        <v-list density="compact" data-test="persona-menu">
          <v-list-item data-test="persona-menu-edit" @click="pick('edit')">
            <v-list-item-title>{{ t('app.strategy.persona.edit') }}</v-list-item-title>
          </v-list-item>
          <v-list-item data-test="persona-menu-delete" @click="pick('remove')">
            <v-list-item-title>{{ t('app.strategy.persona.delete') }}</v-list-item-title>
          </v-list-item>
        </v-list>
      </v-menu>
    </header>

    <div class="persona-card__body">
      <p v-if="a.summary" class="persona-card__summary" data-test="persona-summary">
        {{ a.summary }}
      </p>
      <section v-if="a.core_job" class="persona-card__block" data-test="persona-core-job">
        <h4 class="persona-card__label">
          <v-icon
            icon="mdi-flash-outline"
            size="15"
            class="persona-card__icon persona-card__icon--warning"
            aria-hidden="true"
          />
          {{ t('app.strategy.persona.coreJob') }}
        </h4>
        <p class="persona-card__text">{{ a.core_job }}</p>
      </section>
      <template v-for="list in lists" :key="list.key">
        <section
          v-if="list.items.length > 0"
          class="persona-card__block"
          :data-test="`persona-${list.key}`"
        >
          <h4 class="persona-card__label">
            <v-icon
              :icon="list.icon"
              size="15"
              :class="['persona-card__icon', `persona-card__icon--${list.tone}`]"
              aria-hidden="true"
            />
            {{ list.label }}
          </h4>
          <ul class="persona-card__list">
            <li v-for="(item, i) in list.items" :key="i">{{ item }}</li>
          </ul>
        </section>
      </template>
      <section v-if="a.need" class="persona-card__block" data-test="persona-need">
        <h4 class="persona-card__label">
          <v-icon
            icon="mdi-check-circle-outline"
            size="15"
            class="persona-card__icon persona-card__icon--success"
            aria-hidden="true"
          />
          {{ t(`app.strategy.persona.need.${a.pronoun}`) }}
        </h4>
        <p class="persona-card__text">{{ a.need }}</p>
      </section>
      <section
        v-if="a.barriers.length > 0"
        class="persona-card__block"
        data-test="persona-barriers"
      >
        <h4 class="persona-card__label">
          <v-icon
            icon="mdi-alert-outline"
            size="15"
            class="persona-card__icon persona-card__icon--warning"
            aria-hidden="true"
          />
          {{ t('app.strategy.persona.barriers') }}
        </h4>
        <ul class="persona-card__list">
          <li v-for="(item, i) in a.barriers" :key="i">{{ item }}</li>
        </ul>
      </section>
      <AngleBlock
        :persona="persona"
        :usps="usps"
        :scope="scope"
        :limits="limits"
        :brand-id="brandId"
        :wave-id="waveId"
        @changed="emit('changed')"
      />
    </div>
  </article>
</template>

<style scoped>
/*
 * Values from frame 13:828 (persona card SK1), through the agency theme tokens: `surface`, `border-color`,
 * `on-surface`, `on-surface-variant` and `text-tertiary` (lifted by the agency theme for contrast). The persona
 * colour is `--usp-color`.
 */
.persona-card {
  display: flex;
  flex-direction: column;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: 10px;
  background: rgb(var(--v-theme-surface));
  overflow: hidden;
  min-width: 0;
}
.persona-card__head {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 16px 18px;
  background: color-mix(in srgb, var(--usp-color) 8%, transparent);
}
.persona-card__avatar {
  flex: 0 0 auto;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  border-radius: 9999px;
  font-size: 13px;
  font-weight: 600;
  line-height: 14px;
  color: rgb(var(--v-theme-on-primary));
  background: var(--usp-color);
}
.persona-card__who {
  flex: 1 1 auto;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.persona-card__line {
  /* One row whatever the title length (frame 13:828: the chip sits beside the title): a long title wraps inside its own
     column, the chip never moves above it. */
  display: flex;
  align-items: flex-start;
  flex-wrap: nowrap;
  gap: 8px;
}
.persona-card__code {
  flex: none;
  padding: 2px 6px;
  border: 1px solid color-mix(in srgb, var(--usp-color) 60%, transparent);
  border-radius: 4px;
  font-size: 11px;
  font-weight: 700;
  line-height: normal;
  color: var(--usp-color);
}
.persona-card__title {
  min-width: 0;
  margin: 0;
  font-size: 16px;
  font-weight: 600;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface));
  overflow-wrap: anywhere;
}
.persona-card__name {
  font-size: 12px;
  line-height: normal;
  color: rgb(var(--v-theme-text-tertiary));
  overflow-wrap: anywhere;
}
.persona-card__menu {
  flex: 0 0 auto;
  color: rgb(var(--v-theme-text-tertiary));
}
.persona-card__body {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 18px;
}
.persona-card__summary {
  margin: 0;
  font-size: 14px;
  line-height: 21px;
  color: rgb(var(--v-theme-on-surface));
  white-space: pre-line;
  overflow-wrap: anywhere;
}
.persona-card__block {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.persona-card__label {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0;
  font-size: 13px;
  font-weight: 600;
  line-height: normal;
  color: rgb(var(--v-theme-on-surface));
}
.persona-card__icon--warning {
  color: rgb(var(--v-theme-warning));
}
.persona-card__icon--success {
  color: rgb(var(--v-theme-success));
}
.persona-card__icon--error {
  color: rgb(var(--v-theme-error));
}
.persona-card__icon--info {
  color: rgb(var(--v-theme-info));
}
.persona-card__icon--pink {
  color: var(--agency-brand-pink);
}
.persona-card__text {
  margin: 0;
  font-size: 13px;
  line-height: 19px;
  color: rgb(var(--v-theme-on-surface-variant));
  white-space: pre-line;
  overflow-wrap: anywhere;
}
.persona-card__list {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin: 0;
  padding: 0;
  list-style: none;
  font-size: 13px;
  line-height: 19px;
  color: rgb(var(--v-theme-on-surface-variant));
  overflow-wrap: anywhere;
}
.persona-card__list li {
  display: flex;
  gap: 8px;
}
.persona-card__list li::before {
  content: '•';
  flex: 0 0 auto;
  color: rgb(var(--v-theme-text-tertiary));
}
</style>
