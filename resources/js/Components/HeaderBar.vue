<script setup lang="ts">
/**
 * The `<nav class="navbar ... wtf-brand-header"><div class="container">`
 * shell shared by SiteHeader.vue and Errors/Maintenance.vue's own trimmed
 * header (see that file's own doc comment for why it can't just reuse
 * SiteHeader outright) — only what goes inside the container differs
 * between them.
 *
 * `navbar-expand-lg`: below 992px the nav-link/account content SiteHeader
 * puts inside the `#collapsible` slot no longer fits on one row without
 * overflowing — it collapses behind the toggle button below. `open` is a
 * v-model owned by the caller (not local state) so SiteHeader can reset it
 * to false on navigation — otherwise following a link on mobile would
 * leave the panel stuck open over the next page. Maintenance.vue's single
 * language switcher never needs collapsing, so it just leaves `open`
 * unbound and passes `hide-toggle`. No Bootstrap JS bundle is loaded in
 * this app (see app.ts — only bootstrap-vue-next's Vue components are
 * wired up), so the toggle is driven by BNavbarToggle/BCollapse's own
 * id/target pairing, not Bootstrap's data-bs-toggle attribute JS.
 */
import { BCollapse, BNavbarToggle } from 'bootstrap-vue-next';

withDefaults(defineProps<{ hideToggle?: boolean }>(), { hideToggle: false });

const open = defineModel<boolean>('open', { default: false });

const COLLAPSE_ID = 'site-header-collapse';
</script>

<template>
  <nav class="navbar navbar-expand-lg navbar-dark sticky-top wtf-brand-header">
    <div class="container">
      <slot :open="open" />

      <BNavbarToggle v-if="!hideToggle" :target="COLLAPSE_ID" class="d-lg-none ms-2" />

      <BCollapse :id="COLLAPSE_ID" v-model="open" class="navbar-collapse w-100" is-nav>
        <slot name="collapsible" :open="open" />
      </BCollapse>
    </div>
  </nav>
</template>
