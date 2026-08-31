<script setup lang="ts">
/**
 * Only /free/{token} has a Hungarian counterpart route (/hu/free/{token} —
 * see routes/web.php's own comment) — every other page in the app is
 * English-only, so this renders nothing anywhere else rather than linking
 * somewhere that doesn't exist. A plain full-navigation <a>, not an
 * Inertia <Link>: Free/Show.vue only loads the target language once, in
 * onMounted, so an SPA-style visit that reuses the existing component
 * instance would leave the page already-rendered in the old language.
 */
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { faLanguage } from '@fortawesome/free-solid-svg-icons';
import { computed } from 'vue';

const otherLocaleHref = computed(() => {
  const path = window.location.pathname;
  const suffix = window.location.search + window.location.hash;

  if (path.startsWith('/hu/free/')) return path.slice('/hu'.length) + suffix;
  if (path.startsWith('/free/')) return `/hu${path}${suffix}`;

  return null;
});

const otherLocaleLabel = computed(() => (
  window.location.pathname.startsWith('/hu/free/') ? 'English' : 'Magyar'
));
</script>

<template>
  <a v-if="otherLocaleHref" class="nav-link btn btn-link" :href="otherLocaleHref">
    <FontAwesomeIcon :icon="faLanguage" class="me-1" />{{ otherLocaleLabel }}
  </a>
</template>
