<script setup lang="ts">
/**
 * The "narrow centered column" wrapper repeated across every single-card
 * page (Welcome, Security, Login, Register, TwoFactorChallenge), each with
 * its own copy of a hand-picked pixel/rem max-width. Bootstrap's grid
 * already does responsive centered columns — this just names the three
 * widths those pages actually need instead of inventing a new one per page.
 */
withDefaults(defineProps<{ size?: 'narrow' | 'medium' | 'wide' }>(), { size: 'medium' });

const COLUMN_CLASSES: Record<'narrow' | 'medium' | 'wide', string> = {
  narrow: 'col-12 col-sm-7 col-md-5 col-lg-4',
  medium: 'col-12 col-sm-8 col-md-6 col-lg-5',
  wide: 'col-12 col-md-10 col-lg-8 col-xl-6',
};
</script>

<template>
  <div class="row justify-content-center">
    <div :class="COLUMN_CLASSES[size]">
      <slot />
    </div>
  </div>
</template>
