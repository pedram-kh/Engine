<script setup lang="ts">
/**
 * DashboardInvitationCard — the campaign-invitation card (Figma frame 07).
 *
 * AH-100c. Rendered ONLY inside the Capacitor app, on the approved dashboard;
 * the web dashboard keeps its `v-alert` teaser. A count title, a status line,
 * a "New" pill and one full-width action.
 *
 * Data-shape deviation from Figma: the "New" pill means "an invitation is
 * awaiting your response". There is no seen/unseen signal on an assignment,
 * and this batch builds no new API surface, so the pill is keyed on the
 * pending count rather than on first sight.
 */

import { useI18n } from 'vue-i18n'

interface Props {
  /** Assignments awaiting the creator's response. */
  count: number
}

defineProps<Props>()

const { t } = useI18n()
</script>

<template>
  <section class="dash-invites" data-testid="dashboard-assignments-teaser">
    <div class="dash-invites__row">
      <span class="dash-invites__icon" aria-hidden="true">
        <v-icon icon="mdi-clipboard-text-outline" size="20" />
      </span>

      <div class="dash-invites__text">
        <p class="dash-invites__title">
          {{
            count > 0
              ? t('creator.ui.dashboard.native.invitations.title', count)
              : t('creator.ui.dashboard.assignments.title')
          }}
        </p>
        <p class="dash-invites__status">
          {{
            count > 0
              ? t('creator.ui.dashboard.native.invitations.waiting')
              : t('creator.ui.dashboard.assignments.none')
          }}
        </p>
      </div>

      <span v-if="count > 0" class="dash-invites__pill" data-testid="dashboard-invitations-new">
        <span class="dash-invites__dot" aria-hidden="true"></span>
        {{ t('creator.ui.dashboard.native.invitations.new') }}
      </span>
    </div>

    <v-btn
      color="primary"
      variant="flat"
      block
      :to="{ name: 'creator.assignments' }"
      data-testid="dashboard-assignments-cta"
    >
      {{ t('creator.ui.dashboard.assignments.cta') }}
    </v-btn>
  </section>
</template>
