<script setup lang="ts">
import SiteFooter from '../Components/SiteFooter.vue';
import SiteHeader from '../Components/SiteHeader.vue';
</script>

<template>
  <div class="wtf-backdrop">
    <SiteHeader />
    <div class="wtf-page-content container py-5">
      <slot />
    </div>
    <SiteFooter />
  </div>
</template>
