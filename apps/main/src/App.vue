<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <v-app>
    <v-main>
      <v-container class="d-flex flex-column align-center justify-center" style="min-height: 100vh">
        <h1 class="text-h4 mb-2">{{ t('app.title') }}</h1>
        <p class="text-body-1 text-medium-emphasis">{{ t('app.subtitle') }}</p>
        <p class="text-caption mt-8 text-disabled">
          {{ t('app.sprint0Notice') }}
        </p>
      </v-container>
    </v-main>
  </v-app>
</template>
