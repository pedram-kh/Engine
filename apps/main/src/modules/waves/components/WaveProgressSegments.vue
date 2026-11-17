<script setup lang="ts">
/**
 * PL-1.1 — a wave's progress: six empty segments and "Not started". Nothing can move a wave on in this chunk,
 * so the bar is a placeholder with the final shape (the later chunks fill segments).
 */
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const SEGMENTS = [0, 1, 2, 3, 4, 5]
</script>

<template>
  <span class="wave-progress" data-test="wave-progress">
    <span class="wave-progress__bar" aria-hidden="true">
      <span v-for="n in SEGMENTS" :key="n" class="wave-progress__segment" />
    </span>
    <span class="wave-progress__label">{{ t('app.waves.table.notStarted') }}</span>
  </span>
</template>
