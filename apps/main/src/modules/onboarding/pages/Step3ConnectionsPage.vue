<script setup lang="ts">
/**
 * Step3ConnectionsPage — the merged Social-accounts + Portfolio wizard
 * step (ad-hoc AH-003 D2). Hosts two DISTINCT sub-sections (social and
 * portfolio are kept separate, never folded into each other) under one
 * step header, with a single "Continue" that advances only once BOTH
 * sub-sections are satisfied (≥1 connected social account AND ≥1
 * portfolio item — the same gates the two former steps each enforced).
 *
 * The "next" target is DERIVED from the visible UX step list so the
 * reversible-hide stays a one-line flip: re-introducing kyc/tax/payout
 * automatically reroutes "Continue" to the next visible step.
 */

import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import ConnectionsSocialSection from '../components/ConnectionsSocialSection.vue'
import ConnectionsPortfolioSection from '../components/ConnectionsPortfolioSection.vue'
import { useOnboardingStore } from '../stores/useOnboardingStore'
import { VISIBLE_UX_STEPS } from '../composables/useWizardSteps'

const { t } = useI18n()
const router = useRouter()
const store = useOnboardingStore()

const socialCount = computed(() => store.creator?.attributes.social_accounts?.length ?? 0)
const portfolioCount = computed(() => store.creator?.attributes.portfolio?.length ?? 0)

const canAdvance = computed(() => socialCount.value > 0 && portfolioCount.value > 0)

/** Route name of the visible step immediately after "connections". */
const nextRouteName = computed<string | null>(() => {
  const idx = VISIBLE_UX_STEPS.findIndex((step) => step.id === 'connections')
  return idx === -1 ? null : (VISIBLE_UX_STEPS[idx + 1]?.routeName ?? null)
})

async function advance(): Promise<void> {
  if (!canAdvance.value || nextRouteName.value === null) return
  await router.push({ name: nextRouteName.value })
}
</script>

<template>
  <section class="connections-step" data-testid="step-connections">
    <header class="connections-step__header">
      <h2 class="text-h5">{{ t('creator.ui.wizard.steps.connections.title') }}</h2>
      <p class="text-body-2 text-medium-emphasis">
        {{ t('creator.ui.wizard.steps.connections.description') }}
      </p>
    </header>

    <ConnectionsSocialSection />

    <v-divider class="connections-step__divider" />

    <ConnectionsPortfolioSection />

    <div class="connections-step__actions">
      <v-btn
        color="primary"
        :disabled="!canAdvance"
        :loading="store.isLoadingPortfolio"
        data-testid="connections-advance"
        @click="advance"
      >
        {{ t('creator.ui.wizard.actions.save_and_continue') }}
      </v-btn>
    </div>
  </section>
</template>

<style scoped>
.connections-step {
  display: flex;
  flex-direction: column;
  gap: 28px;
  max-width: 840px;
}

.connections-step__divider {
  margin: 4px 0;
}

.connections-step__actions {
  display: flex;
  justify-content: flex-end;
}
</style>
