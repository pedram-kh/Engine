<script setup lang="ts">
/**
 * AH-100 — the creator app's wizard header (Figma frame 01, "Onboarding
 * header"): the active step's "Step N of M" overline and title, the
 * completeness caption, and one progress segment per visible step.
 *
 * Fed the same `chromeSteps` / `activeIndex` / completeness label the
 * animated chromes receive, so it carries no wizard logic of its own.
 * Segments up to and including the active step are filled.
 */

import { computed } from 'vue'

import type { WizardChromeStep } from './AnimatedWizardChrome.vue'

const props = defineProps<{
  steps: WizardChromeStep[]
  activeIndex: number
  completenessLabel: string
}>()

const active = computed(() => props.steps[props.activeIndex] ?? null)
</script>

<template>
  <header class="native-wizard-header" data-test="native-wizard-header">
    <div class="native-wizard-header__row">
      <div class="native-wizard-header__titles">
        <p class="native-wizard-header__overline" data-test="native-wizard-header-position">
          {{ active?.positionLabel ?? '' }}
        </p>
        <p class="native-wizard-header__title" data-test="native-wizard-header-title">
          {{ active?.title ?? '' }}
        </p>
      </div>
      <p class="native-wizard-header__completeness" data-test="native-wizard-header-completeness">
        {{ completenessLabel }}
      </p>
    </div>
    <div class="native-wizard-header__segments" aria-hidden="true">
      <span
        v-for="(step, index) in steps"
        :key="step.id"
        class="native-wizard-header__segment"
        :class="{ 'native-wizard-header__segment--filled': index <= activeIndex }"
        data-test="native-wizard-header-segment"
      />
    </div>
  </header>
</template>

<style scoped>
.native-wizard-header {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 0 16px 16px;
  background: var(--native-bg-topbar);
  border-bottom: 1px solid var(--native-border-default);
}

.native-wizard-header__row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.native-wizard-header__titles {
  flex: 1 0 0;
  min-width: 0;
}

.native-wizard-header__overline {
  margin: 0;
  font: var(--native-type-overline);
  letter-spacing: var(--native-type-overline-tracking);
  text-transform: uppercase;
  color: var(--native-text-tertiary);
}

.native-wizard-header__title {
  margin: 0;
  font: var(--native-type-h4);
  color: var(--native-text-primary);
}

.native-wizard-header__completeness {
  margin: 0;
  font: var(--native-type-caption-strong);
  color: var(--native-accent-primary);
  white-space: nowrap;
}

.native-wizard-header__segments {
  display: flex;
  gap: 4px;
}

.native-wizard-header__segment {
  flex: 1 0 0;
  height: 4px;
  border-radius: var(--native-radius-full);
  background: var(--native-bg-surface-raised);
}

.native-wizard-header__segment--filled {
  background: var(--native-accent-primary);
}
</style>
