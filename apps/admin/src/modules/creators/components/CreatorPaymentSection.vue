<script setup lang="ts">
/**
 * Creator-detail PAYMENT section (Sprint 13, D-13 — coming-soon block).
 *
 * This is the discrete, swappable payment block the kickoff calls for: it
 * is its OWN component, mounted as one section on the creator-detail page,
 * NOT woven through the page's other sections. S10 lights up payments by
 * replacing this component's body with the live payment-history table —
 * the surrounding page never changes, no payment assumptions leak into the
 * assignment/audit history reads.
 */

import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <section class="admin-creator-detail__section" data-testid="admin-creator-detail-payments">
    <h2 class="text-h6">{{ t('admin.creators.detail.payments_heading') }}</h2>
    <v-alert
      type="info"
      variant="tonal"
      density="comfortable"
      data-testid="admin-creator-detail-payments-coming-soon"
    >
      {{ t('admin.creators.detail.payments_coming_soon') }}
    </v-alert>
  </section>
</template>
