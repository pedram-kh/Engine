<script setup lang="ts">
/**
 * Transient section stub mounted by Sprint-13 sub-step 1 (the shell) at
 * every LIVE module route whose substantive page lands in a later
 * sub-step. The shell (D-1) is the blocker: it must mount the full route
 * table before any module is built, so each live route resolves to this
 * stub until its owning sub-step overwrites the page file in place.
 *
 * Distinct from `ComingSoonPage.vue` (D-13): that one is a deliberate,
 * shipped placeholder for payment surfaces S10 lights up; THIS one is
 * scaffolding that every Sprint-13 sub-step removes as it builds its
 * module. By end of sprint no live route points here.
 */
import { useI18n } from 'vue-i18n'

const props = defineProps<{ headingKey: string }>()
const { t } = useI18n()
</script>

<template>
  <section data-testid="admin-section-stub">
    <h1 class="text-h5 mb-2">{{ t(props.headingKey) }}</h1>
    <p class="text-body-2 text-medium-emphasis">{{ t('app.sectionStub.body') }}</p>
  </section>
</template>
