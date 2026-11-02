<script setup lang="ts">
/**
 * AH-114 (S3.3) — the agency creator card (Figma Discover 17:468): a 190px cover, then a
 * 16px-padded body of name and connection pill, location, language and category chips, on a
 * surface card with the default border and radius 12.
 *
 * Everything is passed in, already localised, so the card has no data of its own and draws
 * only what it is given: no location line without a country or age, no language line without
 * a language, no chip row without categories. The frame's video count, heart and verified
 * badge have no data behind them and are not drawn.
 *
 * The cover is the creator's picture when there is one, otherwise a gradient from a theme
 * status colour (picked by hashing the id, as the brand mark does) with the initial large and
 * faint at the bottom left. The connection is the shared status pill (`kind="discovery"`); a
 * creator with no connection shows the page's own "Not connected" word instead.
 *
 * The whole card opens the profile: a click, or Enter / Space with the keyboard.
 */

import type { DiscoveryConnectionState } from '@catalyst/api-client'
import { computed } from 'vue'

import { brandMarkTone } from '../status/agencyBrandTone'
import AgencyStatusPill from './AgencyStatusPill.vue'

/** Categories drawn before the rest collapse into a "+N" chip. */
const MAX_CATEGORIES = 2

const props = defineProps<{
  id: string
  name: string
  avatarUrl: string | null
  avatarAlt: string
  initial: string
  /** Pre-localised country, or null. */
  country: string | null
  age: number | null
  /** Pre-localised language, or null. */
  language: string | null
  /** Pre-localised category labels. */
  categories: string[]
  connection: DiscoveryConnectionState
  /** The word shown when there is no connection. */
  notConnectedLabel: string
}>()

const emit = defineEmits<{ open: [] }>()

const tone = computed(() => brandMarkTone(props.id))
const shownCategories = computed(() => props.categories.slice(0, MAX_CATEGORIES))
const moreCount = computed(() => Math.max(0, props.categories.length - MAX_CATEGORIES))
const hasLocation = computed(() => props.country !== null || props.age !== null)
</script>

<template>
  <article
    class="agency-creator-card"
    role="link"
    tabindex="0"
    :aria-label="name"
    :data-test="`discover-card-${id}`"
    @click="emit('open')"
    @keydown.enter.self.prevent="emit('open')"
    @keydown.space.self.prevent="emit('open')"
  >
    <div class="agency-creator-card__cover" :class="`agency-creator-card__cover--${tone}`">
      <img
        v-if="avatarUrl"
        class="agency-creator-card__img"
        :src="avatarUrl"
        :alt="avatarAlt"
        loading="lazy"
      />
      <span v-else class="agency-creator-card__initial" aria-hidden="true">{{ initial }}</span>
    </div>

    <div class="agency-creator-card__body">
      <div class="agency-creator-card__namerow">
        <span class="agency-creator-card__name" :data-test="`discover-name-${id}`">{{ name }}</span>
        <AgencyStatusPill
          v-if="connection !== 'none'"
          kind="discovery"
          :status="connection"
          :data-test="`discover-connection-${connection}-${id}`"
        />
        <span
          v-else
          class="agency-creator-card__not-connected"
          :data-test="`discover-notconnected-${id}`"
          >{{ notConnectedLabel }}</span
        >
      </div>

      <div v-if="hasLocation" class="agency-creator-card__line">
        <v-icon icon="mdi-map-marker-outline" size="13" aria-hidden="true" />
        <span>
          <template v-if="country !== null">{{ country }}</template>
          <template v-if="country !== null && age !== null">{{ '  ·  ' }}</template>
          <span v-if="age !== null" :data-test="`discover-age-${id}`">{{ age }}</span>
        </span>
      </div>

      <div v-if="language" class="agency-creator-card__line">
        <v-icon icon="mdi-translate" size="13" aria-hidden="true" />
        <span>{{ language }}</span>
      </div>

      <div v-if="categories.length > 0" class="agency-creator-card__chips">
        <span v-for="cat in shownCategories" :key="cat" class="agency-creator-card__chip">{{
          cat
        }}</span>
        <span
          v-if="moreCount > 0"
          class="agency-creator-card__chip"
          :data-test="`discover-cats-more-${id}`"
          >+{{ moreCount }}</span
        >
      </div>
    </div>
  </article>
</template>

<style scoped>
.agency-creator-card {
  display: flex;
  flex-direction: column;
  min-width: 0;
  overflow: hidden;
  border: 1px solid rgb(var(--v-theme-border-color));
  border-radius: var(--radius-2xl);
  background-color: rgb(var(--v-theme-surface));
  cursor: pointer;
}

.agency-creator-card:hover {
  border-color: rgb(var(--v-theme-outline));
}

.agency-creator-card:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 2px;
}

.agency-creator-card__cover {
  --cover: var(--v-theme-info);

  position: relative;
  flex: none;
  height: 190px;
  overflow: hidden;
  background-image: linear-gradient(147deg, rgb(var(--cover)) 14%, rgba(var(--cover), 0.35) 86%);
}

.agency-creator-card__cover--success {
  --cover: var(--v-theme-success);
}

.agency-creator-card__cover--warning {
  --cover: var(--v-theme-warning);
}

.agency-creator-card__cover--ai {
  --cover: var(--v-theme-status-ai);
}

.agency-creator-card__img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.agency-creator-card__initial {
  position: absolute;
  top: 110px;
  left: 20px;
  font-size: 28px;
  font-weight: 500;
  line-height: 36px;
  opacity: 0.35;
  color: rgb(var(--v-theme-on-surface));
}

.agency-creator-card__body {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 16px;
}

.agency-creator-card__namerow {
  display: flex;
  min-height: 24px;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  min-width: 0;
}

.agency-creator-card__name {
  min-width: 0;
  overflow: hidden;
  font-size: 14px;
  font-weight: 500;
  line-height: 20px;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: rgb(var(--v-theme-on-surface));
}

.agency-creator-card__not-connected {
  flex: none;
  font-size: 12px;
  line-height: 16px;
  color: rgb(var(--v-theme-text-tertiary));
}

.agency-creator-card__line {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  line-height: 16px;
  white-space: pre;
  color: rgb(var(--v-theme-on-surface-variant));
}

.agency-creator-card__chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.agency-creator-card__chip {
  padding: 3px 8px;
  border-radius: 999px;
  background-color: rgb(var(--v-theme-surface-bright));
  font-size: 12px;
  font-weight: 500;
  line-height: 16px;
  white-space: nowrap;
  color: rgb(var(--v-theme-on-surface-variant));
}
</style>
