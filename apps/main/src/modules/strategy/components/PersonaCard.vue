<script setup lang="ts">
/**
 * PL-2.1 — one persona card (frame: tinted header band, initials avatar, code chip, title, "Name · persona name",
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

const initials = computed(() =>
  a.value.name
    .split(/\s+/)
    .filter((w) => w !== '')
    .slice(0, 2)
    .map((w) => Array.from(w)[0] ?? '')
    .join('')
    .toUpperCase(),
)

const lists = computed(() => [
  { key: 'drivers', label: t('app.strategy.persona.drivers'), items: a.value.drivers },
  { key: 'goals', label: t('app.strategy.persona.goals'), items: a.value.goals },
  {
    key: 'self-talk',
    label: t(`app.strategy.persona.selfTalk.${a.value.pronoun}`),
    items: a.value.self_talk,
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
        <div class="d-flex align-center ga-2 flex-wrap">
          <span class="persona-card__code" data-test="persona-code">{{ a.code }}</span>
          <h3 class="persona-card__title" data-test="persona-title">{{ a.title }}</h3>
        </div>
        <div class="persona-card__name" data-test="persona-name">
          {{ t('app.strategy.persona.nameLine', { name: a.name }) }}
        </div>
      </div>
      <v-menu v-model="menuOpen" v-bind="MENU_PROPS" location="bottom end">
        <template #activator="{ props: act }">
          <v-btn
            v-bind="act"
            icon="mdi-dots-vertical"
            variant="text"
            size="small"
            :aria-label="t('app.strategy.persona.menu', { name: a.name })"
            data-test="persona-menu-btn"
          />
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
      <p v-if="a.summary" class="persona-card__text" data-test="persona-summary">{{ a.summary }}</p>
      <section v-if="a.core_job" data-test="persona-core-job">
        <h4 class="persona-card__label">{{ t('app.strategy.persona.coreJob') }}</h4>
        <p class="persona-card__text">{{ a.core_job }}</p>
      </section>
      <template v-for="list in lists" :key="list.key">
        <section v-if="list.items.length > 0" :data-test="`persona-${list.key}`">
          <h4 class="persona-card__label">{{ list.label }}</h4>
          <ul class="persona-card__list">
            <li v-for="(item, i) in list.items" :key="i">{{ item }}</li>
          </ul>
        </section>
      </template>
      <section v-if="a.need" data-test="persona-need">
        <h4 class="persona-card__label">{{ t(`app.strategy.persona.need.${a.pronoun}`) }}</h4>
        <p class="persona-card__text">{{ a.need }}</p>
      </section>
      <section v-if="a.barriers.length > 0" data-test="persona-barriers">
        <h4 class="persona-card__label">{{ t('app.strategy.persona.barriers') }}</h4>
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
.persona-card {
  display: flex;
  flex-direction: column;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  border-radius: 12px;
  background: rgb(var(--v-theme-surface));
  overflow: hidden;
  min-width: 0;
}
.persona-card__head {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 12px 12px 16px;
  background: color-mix(in srgb, var(--usp-color) 16%, transparent);
  border-bottom: 1px solid color-mix(in srgb, var(--usp-color) 40%, transparent);
}
.persona-card__avatar {
  flex: 0 0 auto;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  border-radius: 9999px;
  font-size: 14px;
  font-weight: 700;
  border: 2px solid var(--usp-color);
  background: rgb(var(--v-theme-surface));
}
.persona-card__who {
  flex: 1 1 auto;
  min-width: 0;
}
.persona-card__code {
  padding: 1px 8px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 700;
  border: 1px solid var(--usp-color);
  background: rgb(var(--v-theme-surface));
}
.persona-card__title {
  margin: 0;
  font-size: 16px;
  font-weight: 600;
  overflow-wrap: anywhere;
}
.persona-card__name {
  font-size: 13px;
  color: rgba(var(--v-theme-on-surface), 0.7);
  overflow-wrap: anywhere;
}
.persona-card__body {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 16px;
}
.persona-card__label {
  margin: 0 0 4px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), 0.65);
}
.persona-card__text {
  margin: 0;
  font-size: 14px;
  white-space: pre-line;
  overflow-wrap: anywhere;
}
.persona-card__list {
  margin: 0;
  padding-left: 18px;
  font-size: 14px;
  overflow-wrap: anywhere;
}
</style>
