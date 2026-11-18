<script setup lang="ts">
/**
 * A wave's progress: six segments and a label, drawn from the server's `progress` object (PL-1.3, plan section
 * 1.6). The web holds no copy of the rule: a segment is `done`, `in_progress` or `empty`, and `label_kind` says
 * whether the label is the name of the first strategy step that is not done, "Complete" or "Not started".
 *
 * Without a `progress` prop (a wave from a response that predates it) it draws six empty segments and "Not
 * started", as PL-1.1 did. The base look comes from the `wave-progress` classes in the agency theme; only the
 * two filled looks are added here, so the frozen theme file is untouched.
 */
import type { WaveProgress } from '@catalyst/api-client'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{ progress?: WaveProgress | null }>()

const { t } = useI18n()

const segments = computed(() =>
  props.progress?.segments?.length === 6
    ? props.progress.segments
    : (['empty', 'empty', 'empty', 'empty', 'empty', 'empty'] as const),
)

const label = computed(() => {
  const p = props.progress
  if (p === undefined || p === null) return t('app.waves.table.notStarted')
  if (p.label_kind === 'complete') return t('app.waves.tracker.progress.complete')
  if (p.label_kind === 'step' && p.step_name !== null) return p.step_name
  return t('app.waves.table.notStarted')
})
</script>

<template>
  <span class="wave-progress" data-test="wave-progress" :data-label-kind="progress?.label_kind">
    <span class="wave-progress__bar" aria-hidden="true">
      <span
        v-for="(state, n) in segments"
        :key="n"
        class="wave-progress__segment"
        :class="`wave-progress__segment--${state}`"
        :data-state="state"
      />
    </span>
    <span class="wave-progress__label" data-test="wave-progress-label">{{ label }}</span>
  </span>
</template>

<style scoped>
/* The label drops under the bar when the column is narrow, instead of running out of its card. */
.wave-progress.wave-progress {
  flex-wrap: wrap;
}

.wave-progress .wave-progress__bar .wave-progress__segment--done {
  background-color: rgb(var(--v-theme-success));
}

.wave-progress .wave-progress__bar .wave-progress__segment--in_progress {
  background-color: rgb(var(--v-theme-info));
}
</style>
