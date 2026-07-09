<script setup lang="ts">
/**
 * Step2ProfileBasicsPage — wizard Step 2 (Profile basics).
 *
 * AH-009: the form BODY (avatar, display name, bio + preview, country, region,
 * AH-005 contact fieldset, primary language, categories, saved-preview, and
 * the `store.updateProfile` save call) now lives in the shared
 * {@link ProfileBasicsForm} component so the post-onboarding `/creator/profile`
 * page renders the exact same form. This page keeps ONLY the wizard chrome:
 *
 *   - the step header,
 *   - the <v-form> wrapper + the "Save and continue" submit button,
 *   - the full-floor forward-gate (`canContinue`) + the requirements hint,
 *   - the post-save navigation to `onboarding.connections`,
 *   - the wizard-mount hydration (onMounted + the guarded re-hydration watch).
 *
 * The forward-gate mirrors the backend's `isProfileComplete` floor in FULL
 * (D2): the shared form's `floorMet` readiness — display_name, country, region,
 * primary_language, ≥1 category, avatar. Gating on the whole floor (not just
 * avatar+category) means a creator can't "Save and continue" with a floor
 * field missing and only discover it at submit; they'd otherwise be bounced
 * back by next_step=profile. The signal comes from the shared form's
 * `readiness` emit.
 *
 * Step 2 → next advance: after a successful save the page navigates to the
 * merged "connections" step (`onboarding.connections`). The
 * `requireOnboardingAccess` guard already gates the wizard at the group level,
 * so no per-step gate is needed.
 */

import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import ProfileBasicsForm from '../components/ProfileBasicsForm.vue'
import { useOnboardingStore } from '../stores/useOnboardingStore'

const { t } = useI18n()
const router = useRouter()
const store = useOnboardingStore()

const formRef = ref<InstanceType<typeof ProfileBasicsForm> | null>(null)

// Readiness mirrored from the shared form. "Save and continue" gates on the
// FULL floor (`floorMet`, D2) so the step is never silently left incomplete —
// a missing floor field blocks here rather than surfacing only at submit.
const readiness = ref({ hasAvatar: false, hasCategory: false, floorMet: false })
function onReadiness(value: { hasAvatar: boolean; hasCategory: boolean; floorMet: boolean }): void {
  readiness.value = value
}

const isSaving = computed(() => store.isLoadingProfile)
const canContinue = computed(() => readiness.value.floorMet)

async function onSubmit(): Promise<void> {
  // Guard the keyboard-submit path (Enter inside a field) too — the button is
  // disabled, but the form's submit event can still fire.
  if (!canContinue.value) return
  const ok = await formRef.value?.save()
  if (ok === true) {
    await router.push({ name: 'onboarding.connections' })
  }
}

// Re-hydrate when the creator lands after this page has mounted (cold load),
// but only while the form is still pristine so we never clobber edits in
// progress. The `isPristine` + `hydrate()` contract is exposed by the shared
// form; the watch (the wizard-mount hydration) stays here, on the host.
watch(
  () => store.creator,
  (creator) => {
    if (creator !== null && formRef.value?.isPristine === true) {
      formRef.value?.hydrate()
    }
  },
)

onMounted(() => {
  formRef.value?.hydrate()
})
</script>

<template>
  <section class="profile-basics" data-testid="step-profile-basics">
    <header class="profile-basics__header">
      <h2 class="text-h5">{{ t('creator.ui.wizard.steps.profile.title') }}</h2>
      <p class="text-body-2 text-medium-emphasis">
        {{ t('creator.ui.wizard.steps.profile.description') }}
      </p>
    </header>

    <v-form class="profile-basics__form" @submit.prevent="onSubmit">
      <ProfileBasicsForm ref="formRef" @readiness="onReadiness" />

      <div class="profile-basics__actions">
        <p
          v-if="!canContinue"
          class="profile-basics__requirements text-body-2"
          data-testid="profile-requirements-hint"
        >
          {{ t('creator.ui.wizard.fields.step_requirements_hint') }}
        </p>
        <v-btn
          type="submit"
          color="primary"
          :loading="isSaving"
          :disabled="!canContinue"
          data-testid="profile-submit"
        >
          {{ t('creator.ui.wizard.actions.save_and_continue') }}
        </v-btn>
      </div>
    </v-form>
  </section>
</template>

<style scoped>
.profile-basics {
  display: flex;
  flex-direction: column;
  gap: 16px;
  max-width: 720px;
}

.profile-basics__form {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.profile-basics__actions {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 8px;
}

.profile-basics__requirements {
  color: rgb(var(--v-theme-on-surface-variant));
}
</style>
