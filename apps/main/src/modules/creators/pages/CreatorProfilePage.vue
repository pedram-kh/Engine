<script setup lang="ts">
/**
 * CreatorProfilePage — the standalone post-onboarding profile-edit surface
 * (AH-009).
 *
 * Reuses the wizard step bodies rather than rebuilding:
 *   - "Profile basics" renders the shared {@link ProfileBasicsForm} (the
 *     extracted step-2 body, incl. the AH-005 contact fieldset), saving via
 *     the existing PATCH /creators/me/wizard/profile path.
 *   - "Socials & portfolio" mounts the two step-3 sub-sections directly
 *     ({@link ConnectionsSocialSection} + {@link ConnectionsPortfolioSection}),
 *     which read the shared store and call the existing social/portfolio write
 *     endpoints (and carry the AH-004 portfolio drawer via PortfolioGallery).
 *
 * Hydration (D8): one `store.bootstrap()` on mount fetches everything (basics +
 * contact + socials + portfolio); the sub-sections read the store reactively,
 * and we hydrate the basics form once the creator is present.
 *
 * Audience (D6): post-submission creators (pending / approved / rejected).
 * Incomplete creators are still in onboarding and edit via the wizard, so we
 * soft-redirect them to the Welcome Back surface here (Q2 — kept in the page,
 * not the route guard, so the `requireAuth`-only route from D5 holds).
 *
 * Completeness floor (lifecycle-aware, host-owned — no service/endpoint
 * change; the wizard's CreatorWizardService is untouched):
 *   - Pending / rejected: their profile is still (re-)judged by an admin, so a
 *     cleared required basics field HARD-BLOCKS the save. The gate is
 *     `floorMet` from the shared form, a 1:1 mirror of the backend
 *     `isProfileComplete` (display_name + country + primary_language + ≥1
 *     category + avatar) — so it also covers the avatar-delete-then-save path
 *     (delete avatar → avatar_path null → floorMet false → save blocked).
 *   - Approved: already past the gate, but `profile_completeness_score` is
 *     surfaced to agencies on discovery (CreatorPublicProfileResource), so a
 *     regressing edit is the creator's call — ALLOWED, but SOFT-WARNED, never
 *     blocked.
 *   - Socials / portfolio (all states): removing the last item is allowed; the
 *     page warns when the store count hits zero (completeness drops). The
 *     sub-sections are mounted as-is (D3) — the page reacts to the count, it
 *     does not gate the removal.
 *
 * The API itself has no application-status guard on these write paths
 * (deferred to tech-debt — AH-009 log); this floor is the page-edge defense.
 */

import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import ProfileBasicsForm from '../../onboarding/components/ProfileBasicsForm.vue'
import ConnectionsSocialSection from '../../onboarding/components/ConnectionsSocialSection.vue'
import ConnectionsPortfolioSection from '../../onboarding/components/ConnectionsPortfolioSection.vue'
import { useOnboardingStore } from '../../onboarding/stores/useOnboardingStore'

const { t } = useI18n()
const router = useRouter()
const store = useOnboardingStore()

const formRef = ref<InstanceType<typeof ProfileBasicsForm> | null>(null)

/**
 * Gate the page render until we've confirmed the creator is past onboarding.
 * Mirrors WelcomeBackPage's `shouldRender` pattern so an incomplete creator
 * never flashes the edit surface before the soft-redirect lands.
 */
const shouldRender = ref(false)

// Readiness mirrored from the shared basics form. `floorMet` is the backend
// `isProfileComplete` mirror; the lifecycle-aware floor keys off it.
const readiness = ref({ hasAvatar: false, hasCategory: false, floorMet: false })
function onReadiness(value: { hasAvatar: boolean; hasCategory: boolean; floorMet: boolean }): void {
  readiness.value = value
}

const isSaving = computed(() => store.isLoadingProfile)
const snackbar = ref(false)

const status = computed(() => store.applicationStatus)
const isApproved = computed(() => status.value === 'approved')
// Pending + rejected: the profile still feeds a (re-)review decision.
const isHardBlockState = computed(() => status.value === 'pending' || status.value === 'rejected')

const floorMet = computed(() => readiness.value.floorMet)

// Hard block (pending/rejected only): can't save with a required basics field
// cleared — incl. the avatar-delete path (floorMet folds avatar presence in).
const saveBlocked = computed(() => isHardBlockState.value && !floorMet.value)

// Soft warn (approved only): allow the save, but flag that it lowers the
// agency-visible completeness signal. Never blocks.
const showApprovedWarning = computed(() => isApproved.value && !floorMet.value)

// Socials / portfolio (all states): warn when the count hits zero, react to
// the store — the sub-sections themselves are unmodified (D3).
const socialCount = computed(() => store.creator?.attributes.social_accounts?.length ?? 0)
const portfolioCount = computed(() => store.creator?.attributes.portfolio?.length ?? 0)
const showSocialWarning = computed(() => socialCount.value === 0)
const showPortfolioWarning = computed(() => portfolioCount.value === 0)

async function onSave(): Promise<void> {
  // Hard floor for pending/rejected — also guards the keyboard-submit path.
  if (saveBlocked.value) return
  const ok = await formRef.value?.save()
  if (ok === true) {
    snackbar.value = true
  }
}

// Re-hydrate the basics form when the creator lands after mount, but only
// while the form is still pristine so we never clobber edits in progress.
watch(
  () => store.creator,
  (creator) => {
    if (creator !== null && formRef.value?.isPristine === true) {
      formRef.value?.hydrate()
    }
  },
)

onMounted(async () => {
  if (!store.isBootstrapped) {
    await store.bootstrap()
  }

  // D6/Q2: incomplete creators edit via the wizard — bounce them to the
  // Welcome Back surface (which re-dispatches to their next step). Done in the
  // page so the route stays `requireAuth`-only (D5).
  if (store.applicationStatus === 'incomplete') {
    await router.replace({ name: 'onboarding.welcome-back' })
    return
  }

  shouldRender.value = true
  // Wait for the form to mount under `v-if`, then hydrate it from the store.
  await nextTick()
  formRef.value?.hydrate()
})
</script>

<template>
  <section v-if="shouldRender" class="creator-profile" data-testid="creator-profile">
    <header class="creator-profile__header">
      <h1 class="text-h4">{{ t('creator.ui.profile.title') }}</h1>
    </header>

    <!-- Section 1: Profile basics (extracted step-2 body). -->
    <section class="creator-profile__section" data-testid="creator-profile-basics">
      <h2 class="text-h6 creator-profile__section-heading">
        {{ t('creator.ui.profile.basics_heading') }}
      </h2>

      <v-form @submit.prevent="onSave">
        <ProfileBasicsForm ref="formRef" @readiness="onReadiness" />

        <v-alert
          v-if="showApprovedWarning"
          type="warning"
          variant="tonal"
          density="comfortable"
          class="creator-profile__warning"
          data-testid="creator-profile-approved-warning"
        >
          {{ t('creator.ui.profile.floor.approved_warning') }}
        </v-alert>

        <div class="creator-profile__actions">
          <p
            v-if="saveBlocked"
            class="creator-profile__hint text-body-2"
            data-testid="creator-profile-incomplete-hint"
          >
            {{ t('creator.ui.profile.floor.incomplete_hint') }}
          </p>
          <v-btn
            type="submit"
            color="primary"
            :loading="isSaving"
            :disabled="saveBlocked"
            data-testid="creator-profile-save"
          >
            {{ t('creator.ui.profile.save') }}
          </v-btn>
        </div>
      </v-form>
    </section>

    <!-- Section 2: Socials & portfolio (step-3 sub-sections, mounted as-is). -->
    <section class="creator-profile__section" data-testid="creator-profile-connections">
      <h2 class="text-h6 creator-profile__section-heading">
        {{ t('creator.ui.profile.connections_heading') }}
      </h2>

      <ConnectionsSocialSection />
      <v-alert
        v-if="showSocialWarning"
        type="warning"
        variant="tonal"
        density="comfortable"
        class="creator-profile__warning"
        data-testid="creator-profile-social-warning"
      >
        {{ t('creator.ui.profile.floor.social_warning') }}
      </v-alert>

      <v-divider class="creator-profile__divider" />

      <ConnectionsPortfolioSection />
      <v-alert
        v-if="showPortfolioWarning"
        type="warning"
        variant="tonal"
        density="comfortable"
        class="creator-profile__warning"
        data-testid="creator-profile-portfolio-warning"
      >
        {{ t('creator.ui.profile.floor.portfolio_warning') }}
      </v-alert>
    </section>

    <v-snackbar
      v-model="snackbar"
      :timeout="3000"
      color="success"
      data-testid="creator-profile-saved"
    >
      {{ t('creator.ui.profile.saved') }}
    </v-snackbar>
  </section>
</template>

<style scoped>
.creator-profile {
  display: flex;
  flex-direction: column;
  gap: 24px;
  max-width: 840px;
}

.creator-profile__header {
  padding-bottom: 16px;
  border-bottom: 2px solid transparent;
  border-image: var(--brand-aurora-gradient) 1;
}

.creator-profile__section {
  display: flex;
  flex-direction: column;
  gap: 20px;
  padding: 24px;
  border: 1px solid rgb(var(--v-theme-outline-variant, var(--v-theme-outline)));
  border-radius: var(--radius-lg, 12px);
  background-color: rgb(var(--v-theme-surface));
}

.creator-profile__section-heading {
  margin-bottom: 4px;
}

.creator-profile__actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 12px;
  margin-top: 16px;
}

.creator-profile__hint {
  color: rgb(var(--v-theme-error));
}

.creator-profile__warning {
  margin-top: 4px;
}

.creator-profile__divider {
  margin: 4px 0;
}
</style>
