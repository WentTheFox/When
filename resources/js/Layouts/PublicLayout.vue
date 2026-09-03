<script setup lang="ts">
import SiteFooter from '../Components/SiteFooter.vue';
import SiteHeader from '../Components/SiteHeader.vue';

/**
 * `header` slot, `dir`, `showInviteCta` and `showAboutLink` exist purely
 * for Errors/Maintenance.vue, which needs the same backdrop/content/footer
 * chrome as every other public page but can't use SiteHeader itself (its
 * nav links/auth state have nowhere meaningful to go while the app is
 * down — see that file's own doc comment), needs a runtime-reactive text
 * direction (its language switcher changes locale, and therefore
 * RTL-ness, without a page reload), and — same reasoning as SiteHeader's
 * missing links — can't offer SiteFooter's "create your own account"
 * invite CTA or "About this project" link either, since /register and
 * /about are both intercepted by maintenance mode exactly like every
 * other route. Every other caller gets the exact same defaults it always
 * had.
 */
withDefaults(defineProps<{ dir?: 'ltr' | 'rtl'; showInviteCta?: boolean; showAboutLink?: boolean }>(), {
  dir: 'ltr',
  showInviteCta: true,
  showAboutLink: true,
});
</script>

<template>
  <div class="wtf-backdrop" :dir="dir">
    <slot name="header">
      <SiteHeader />
    </slot>
    <div class="wtf-page-content container py-3">
      <slot />
    </div>
    <SiteFooter :show-invite-cta="showInviteCta" :show-about-link="showAboutLink" />
  </div>
</template>
