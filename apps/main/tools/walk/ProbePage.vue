<script setup lang="ts">
/**
 * One deterministic page that exercises every Vuetify primitive the AH-114
 * agency restyle touches (and therefore every shared selector the creator web
 * could be hit by). No data, no dates, no randomness: its DOM and computed
 * styles must be identical on every run of the same commit.
 */

import { ref } from 'vue'

const tab = ref('one')
const dialog = ref(false)
const switchOn = ref(true)
const checked = ref(true)
const text = ref('Spring launch')
const select = ref('b')
const page = ref(2)

const headers = [
  { title: 'Campaign', key: 'name' },
  { title: 'Brand', key: 'brand' },
  { title: 'Budget', key: 'budget' },
]
const items = [
  { name: 'Spring launch', brand: 'Northwind', budget: '4,200' },
  { name: 'Summer reset', brand: 'Aster', budget: '7,000' },
]
</script>

<template>
  <section data-test="probe-page" class="probe">
    <h1 class="text-h5" data-test="probe-h5">Probe heading</h1>
    <h2 class="text-h6" data-test="probe-h6">Section heading</h2>
    <p class="text-subtitle-1">Subtitle one</p>
    <p class="text-subtitle-2">Subtitle two</p>
    <p class="text-body-1">Body one</p>
    <p class="text-body-2" data-test="probe-body2">Body two</p>
    <p class="text-caption" data-test="probe-caption">Caption text</p>
    <p class="text-overline">Overline text</p>

    <div class="d-flex ga-2 flex-wrap my-3" data-test="probe-buttons">
      <v-btn color="primary">Primary</v-btn>
      <v-btn variant="outlined">Secondary</v-btn>
      <v-btn variant="tonal">Soft</v-btn>
      <v-btn variant="text">Ghost</v-btn>
      <v-btn variant="outlined" color="error">Danger</v-btn>
      <v-btn color="primary" size="small">Small</v-btn>
      <v-btn color="primary" disabled>Disabled</v-btn>
      <v-btn icon="mdi-dots-vertical" variant="text" aria-label="More" />
    </div>

    <div class="d-flex ga-3 flex-wrap my-3" data-test="probe-fields">
      <v-text-field v-model="text" label="Name" hide-details style="max-width: 240px" />
      <v-select
        v-model="select"
        :items="['a', 'b', 'c']"
        label="Brand"
        hide-details
        style="max-width: 200px"
        data-test="probe-select"
      />
      <v-textarea label="Notes" rows="2" hide-details style="max-width: 280px" />
      <v-file-input label="Attachment" hide-details style="max-width: 280px" />
    </div>

    <div class="d-flex ga-4 align-center my-3" data-test="probe-toggles">
      <v-checkbox v-model="checked" label="Checkbox" hide-details />
      <v-switch v-model="switchOn" label="Toggle" hide-details color="primary" />
      <v-chip>Chip</v-chip>
      <v-chip color="success" variant="tonal">Success chip</v-chip>
      <v-progress-linear :model-value="40" style="max-width: 160px" />
    </div>

    <v-alert type="info" variant="tonal" class="my-3" data-test="probe-alert">
      An info banner with some copy.
    </v-alert>

    <v-card class="my-3" data-test="probe-card">
      <v-card-title>Card title</v-card-title>
      <v-card-text>Card body text.</v-card-text>
    </v-card>

    <v-tabs v-model="tab" data-test="probe-tabs">
      <v-tab value="one">Overview</v-tab>
      <v-tab value="two">Creators</v-tab>
    </v-tabs>

    <v-data-table
      :headers="headers"
      :items="items"
      hide-default-footer
      class="my-3"
      data-test="probe-table"
    />
    <v-pagination v-model="page" :length="5" data-test="probe-pagination" />

    <v-list density="compact" data-test="probe-list">
      <v-list-item title="List item one" />
      <v-list-item title="List item two" />
    </v-list>

    <div class="d-flex ga-2 my-3">
      <v-menu>
        <template #activator="{ props }">
          <v-btn v-bind="props" data-test="probe-menu-btn">Menu</v-btn>
        </template>
        <v-card data-test="probe-menu-card">
          <v-card-text>menu content</v-card-text>
        </v-card>
      </v-menu>
      <v-btn data-test="probe-dialog-btn" @click="dialog = true">Dialog</v-btn>
      <v-tooltip text="Tooltip text" location="bottom">
        <template #activator="{ props }">
          <v-btn v-bind="props" data-test="probe-tooltip-btn" variant="text">Tooltip</v-btn>
        </template>
      </v-tooltip>
    </div>

    <v-dialog v-model="dialog" max-width="420">
      <v-card data-test="probe-dialog-card">
        <v-card-title>Dialog title</v-card-title>
        <v-card-text>Dialog body text.</v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="dialog = false">Cancel</v-btn>
          <v-btn color="primary" @click="dialog = false">Confirm</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </section>
</template>
