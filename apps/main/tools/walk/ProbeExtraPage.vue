<script setup lang="ts">
/**
 * Second probe page (harness only, `?probe=extra`): the primitive variants the agency
 * pages use and the default probe page does not carry. Nothing here is shared with the
 * creator baseline, so adding to it never changes a baseline hash.
 */

import { ref } from 'vue'

const on = ref(true)
const off = ref(false)
const text = ref('')
const combo = ref('')
</script>

<template>
  <section data-test="probe-extra" class="probe">
    <div class="d-flex ga-4 align-center my-3" data-test="extra-switches">
      <!-- The shape CampaignListPage uses: inset, compact, primary. -->
      <v-switch
        v-model="on"
        color="primary"
        density="compact"
        hide-details
        inset
        aria-label="On"
        data-test="extra-switch-on"
      />
      <v-switch
        v-model="off"
        color="primary"
        density="compact"
        hide-details
        inset
        aria-label="Off"
        data-test="extra-switch-off"
      />
    </div>

    <div class="d-flex ga-2 flex-wrap my-3" data-test="extra-buttons">
      <v-btn variant="outlined" color="primary">Outlined primary</v-btn>
      <v-btn variant="text" color="primary">Text primary</v-btn>
      <v-btn variant="tonal" color="primary">Tonal primary</v-btn>
      <v-btn variant="text" color="error">Text error</v-btn>
      <v-btn variant="outlined" size="small" class="text-none">Small secondary</v-btn>
    </div>

    <v-card class="pa-4 my-3" data-test="extra-card">
      <div class="d-flex flex-column ga-3" data-test="extra-fields">
        <v-text-field
          v-model="text"
          label="With hint"
          hint="A hint line"
          persistent-hint
          data-test="extra-hint"
        />
        <v-text-field
          label="With error"
          :error-messages="['This field is required']"
          data-test="extra-error"
        />
        <v-autocomplete label="Autocomplete" :items="['a', 'b']" data-test="extra-autocomplete" />
        <v-combobox
          v-model="combo"
          label="Combobox"
          :items="['a', 'b']"
          data-test="extra-combobox"
        />
        <v-text-field label="Disabled" disabled data-test="extra-disabled" />
      </div>
    </v-card>
  </section>
</template>
