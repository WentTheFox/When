<script setup lang="ts">
/**
 * Shared by SiteHeader.vue (`navigate` true — /free/{token} has a real
 * per-locale route, so switching is a full-page navigation to a
 * locale-prefixed URL) and Errors/Maintenance.vue (`navigate` false —
 * maintenance mode intercepts every route, so there's nowhere else to
 * navigate to; it swaps the loaded lang/{code}.json client-side instead,
 * staying on whatever URL is already showing). Either way the option list
 * is alphabetical by native name, with whatever navigator.languages
 * (Accept-Language) locales match at the top, divider-separated — no
 * divider (or reordering) when that's unavailable or matches none of
 * `locales`.
 *
 * `hrefFor` strips/re-adds the current locale prefix generically (works
 * for /free/{token} exactly the same way it works for the maintenance
 * page showing on an arbitrary URL) — it only needs `modelValue` to know
 * what to strip, not the specific route shape.
 */
import { faLanguage } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { BDropdown, BDropdownDivider, BDropdownItem } from 'bootstrap-vue-next';
import { loadLanguageAsync } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

export interface LocaleOption {
  code: string;
  native: string;
}

const props = withDefaults(defineProps<{
  locales: LocaleOption[];
  modelValue: string;
  navigate?: boolean;
}>(), { navigate: true });

const emit = defineEmits<{ 'update:modelValue': [code: string] }>();

const switching = ref(false);

const current = computed(() => (
  props.locales.find((l) => l.code === props.modelValue) ?? props.locales[0]
));

const sortedLocales = computed(() => (
  [...props.locales].sort((a, b) => a.native.localeCompare(b.native))
));

const preferredLocales = computed(() => {
  const languages = typeof navigator === 'undefined' ? [] : (navigator.languages ?? []);
  const seen = new Set<string>();
  const matched: LocaleOption[] = [];
  for (const tag of languages) {
    const base = tag.split('-')[0].toLowerCase();
    const match = props.locales.find((l) => l.code === base);
    if (match && !seen.has(match.code)) {
      seen.add(match.code);
      matched.push(match);
    }
  }
  return matched;
});

const otherLocales = computed(() => {
  const preferredCodes = new Set(preferredLocales.value.map((l) => l.code));
  return sortedLocales.value.filter((l) => !preferredCodes.has(l.code));
});

const orderedLocales = computed(() => [...preferredLocales.value, ...otherLocales.value]);

function hrefFor(code: string): string {
  const { pathname, search, hash } = window.location;
  const rest = props.modelValue === 'en' ? pathname : (pathname.replace(`/${props.modelValue}`, '') || '/');
  return (code === 'en' ? rest : `/${code}${rest}`) + search + hash;
}

async function handleClick(event: MouseEvent, code: string): Promise<void> {
  if (props.navigate) {
    return;
  }
  // Not a navigate-mode switch: BDropdownItem always renders as an <a>
  // (falling back to href="#" when none is given), so its default
  // click behavior has to be stopped explicitly or the URL would still
  // pick up a stray "#".
  event.preventDefault();
  if (code === props.modelValue || switching.value) {
    return;
  }
  switching.value = true;
  try {
    await loadLanguageAsync(code);
    emit('update:modelValue', code);
  } catch (e) {
    console.error(e);
  } finally {
    switching.value = false;
  }
}
</script>

<template>
  <BDropdown variant="link" toggle-class="nav-link" menu-class="wtf-language-menu" no-caret>
    <template #button-content>
      <FontAwesomeIcon :icon="faLanguage" class="me-1" />{{ current?.native }}
    </template>
    <template v-for="(l, i) in orderedLocales" :key="l.code">
      <BDropdownDivider v-if="i === preferredLocales.length && i > 0" />
      <BDropdownItem
        :href="navigate ? hrefFor(l.code) : undefined"
        :active="l.code === modelValue"
        :disabled="l.code === modelValue"
        @click="handleClick($event, l.code)"
      >
        {{ l.native }}
      </BDropdownItem>
    </template>
  </BDropdown>
</template>

<style>
/* Not scoped — menu-class renders on an element BDropdown teleports out
   of this component's own subtree, so a scoped style's data-v- attribute
   selector would never match it. 26 locales (App\Support\Locales::NAMES)
   is already tall enough to run off the bottom of a short viewport
   (mobile, or the language dropdown opened from a browser window resized
   short) — cap it and let it scroll instead of overflowing off-screen. */
.wtf-language-menu {
  max-height: 50vh;
  overflow-y: auto;
}
</style>
