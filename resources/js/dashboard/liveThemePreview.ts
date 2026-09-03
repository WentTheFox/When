import { inject, provide, ref, type Ref } from 'vue';

/**
 * Lets a settings-editing page (Settings.vue's accent/secondary color
 * pickers) live-preview a color change across the whole dashboard chrome
 * (nav, links, --app-text-muted elements) before it's ever saved — not just
 * in that page's own local preview panel. DashboardLayout provides the
 * shared ref; a page injects it and writes into it while mounted, clearing
 * it on unmount so navigating away restores the owner's actually-saved
 * colors.
 */
export interface LiveThemeOverride {
  accent?: string | null;
  secondary?: string | null;
}

const KEY = Symbol('liveThemePreview');

export function provideLiveThemePreview(): Ref<LiveThemeOverride | null> {
  const state = ref<LiveThemeOverride | null>(null);
  provide(KEY, state);
  return state;
}

export function useLiveThemePreview(): Ref<LiveThemeOverride | null> {
  const state = inject<Ref<LiveThemeOverride | null>>(KEY);
  if (!state) {
    throw new Error('useLiveThemePreview() must be called from a page rendered inside DashboardLayout.');
  }
  return state;
}
