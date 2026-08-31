/**
 * Vue-reactive theme cycle: same cookie name, same three-way
 * system → light → dark cycle, same "dark unless the visitor has ever
 * toggled" default, used by every Inertia page via ThemeToggle.vue.
 */
import { faCircleHalfStroke, faMoon, faSun } from '@fortawesome/free-solid-svg-icons';
import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import { onMounted, onUnmounted, ref } from 'vue';

type Preference = 'system' | 'light' | 'dark';

const COOKIE_NAME = 'wtf-theme';
const ORDER: Preference[] = ['dark', 'light', 'system'];
const ICONS: Record<Preference, IconDefinition> = {
  dark: faMoon,
  light: faSun,
  system: faCircleHalfStroke,
};

const media = typeof window !== 'undefined' ? window.matchMedia('(prefers-color-scheme: dark)') : null;

function getPreference(): Preference {
  const match = document.cookie.match(/(?:^|; )wtf-theme=([^;]*)/);
  const value = match ? decodeURIComponent(match[1]) : '';
  return (ORDER as string[]).includes(value) ? (value as Preference) : 'dark';
}

function setPreference(pref: Preference): void {
  try {
    const oneYear = 60 * 60 * 24 * 365;
    document.cookie = `${COOKIE_NAME}=${pref}; path=/; max-age=${oneYear}; SameSite=Lax`;
  } catch {
    // Ignore — the toggle still works for this page load either way.
  }
}

function resolve(pref: Preference): 'light' | 'dark' {
  return pref === 'system' ? (media?.matches ? 'dark' : 'light') : pref;
}

function apply(pref: Preference): void {
  // Bootstrap 5's own native theming attribute — its built-in component
  // styles (card, btn, form-control, alert, ...) re-theme themselves under
  // this automatically; dark-theme.css only needs to supply this app's own
  // --wtf-* variables and whatever Bootstrap doesn't cover on top of that.
  document.documentElement.setAttribute('data-bs-theme', resolve(pref));
}

export function useTheme() {
  const preference = ref<Preference>(getPreference());
  const icon = ref(ICONS[preference.value]);

  apply(preference.value);

  function cycle(): void {
    const next = ORDER[(ORDER.indexOf(preference.value) + 1) % ORDER.length];
    preference.value = next;
    icon.value = ICONS[next];
    setPreference(next);
    apply(next);
  }

  function onSystemChange(): void {
    if (preference.value === 'system') {
      apply('system');
    }
  }

  onMounted(() => media?.addEventListener('change', onSystemChange));
  onUnmounted(() => media?.removeEventListener('change', onSystemChange));

  return { icon, cycle };
}
