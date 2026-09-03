<script setup lang="ts">
/**
 * Only /free/{token} has locale-prefixed counterparts (one per
 * App\Support\Locales::codes() entry — see routes/web.php's own comment)
 * — every other page in the app is English-only, so this renders nothing
 * anywhere else rather than linking somewhere that doesn't exist. Each
 * option is a plain full-navigation <a> (BDropdownItem with `href`, not
 * `to`), not an Inertia visit: Free/Show.vue only loads the target
 * language once, in onMounted, so an SPA-style visit that reuses the
 * existing component instance would leave the page already-rendered in
 * the old language.
 */
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { faLanguage } from '@fortawesome/free-solid-svg-icons';
import { BDropdown, BDropdownItem } from 'bootstrap-vue-next';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { SharedPageProps } from '../sharedPageProps';

const page = usePage<SharedPageProps>();

const LOCALE_PATH_RE = /^\/([a-z]{2})\/free\//;

const isFreePage = computed(() => (
  window.location.pathname.startsWith('/free/') || LOCALE_PATH_RE.test(window.location.pathname)
));

const currentLocaleCode = computed(() => window.location.pathname.match(LOCALE_PATH_RE)?.[1] ?? 'en');

const current = computed(() => (
  page.props.locales.find((l) => l.code === currentLocaleCode.value) ?? page.props.locales[0]
));

function hrefFor(code: string): string {
  const suffix = window.location.search + window.location.hash;
  const unprefixedPath = window.location.pathname.replace(LOCALE_PATH_RE, '/free/');

  return (code === 'en' ? unprefixedPath : `/${code}${unprefixedPath}`) + suffix;
}
</script>

<template>
  <BDropdown v-if="isFreePage" variant="link" toggle-class="nav-link" no-caret>
    <template #button-content>
      <FontAwesomeIcon :icon="faLanguage" class="me-1" />{{ current?.native }}
    </template>
    <BDropdownItem
      v-for="locale in page.props.locales"
      :key="locale.code"
      :href="hrefFor(locale.code)"
      :active="locale.code === currentLocaleCode"
      :disabled="locale.code === currentLocaleCode"
    >
      {{ locale.code === 'en' ? 'English' : `${locale.native} (${locale.english})` }}
    </BDropdownItem>
  </BDropdown>
</template>
