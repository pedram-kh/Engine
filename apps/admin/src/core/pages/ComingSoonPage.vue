<script setup lang="ts">
/**
 * Coming-soon placeholder for payment-touching admin surfaces shipped
 * as discrete swappable blocks this sprint (Sprint 13, D-13).
 *
 * Each payment surface (payments list, disputes panel, dashboard
 * payment/dispute cards, the creator-detail payment section, the
 * payment-event admin alerts) is a self-contained block so Sprint 10
 * lights it up by SWAPPING this placeholder for the real page — not by
 * unpicking payment assumptions woven through other code. The
 * `titleKey` prop lets one component serve every payment surface.
 */
import { useI18n } from 'vue-i18n'

const props = withDefaults(defineProps<{ titleKey?: string }>(), {
  titleKey: 'app.comingSoon.defaultTitle',
})

const { t } = useI18n()
</script>

<template>
  <section
    data-testid="admin-coming-soon"
    class="d-flex flex-column align-center justify-center py-16"
  >
    <v-icon icon="mdi-clock-outline" size="48" color="grey" class="mb-4" />
    <h1 class="text-h5 mb-2">{{ t(props.titleKey) }}</h1>
    <p class="text-body-2 text-medium-emphasis">{{ t('app.comingSoon.body') }}</p>
  </section>
</template>
