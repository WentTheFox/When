<script setup lang="ts">
/**
 * Owner settings page — split into one component per card (see
 * resources/js/dashboard/SettingsXxxCard.vue) since the combined template
 * had grown genuinely too large to navigate as a single file. All four
 * cards still save through this ONE shared `form` (a single useForm()
 * instance passed down as a prop, mutated directly by each card via
 * v-model on its own slice of fields — legitimate in Vue since `form` is
 * one reactive object reference, never reassigned) and this one submit()
 * — each card's own "Save" button triggers the exact same PATCH covering
 * every field across every card, matching SettingsController::update()'s
 * own single-transaction save. Splitting the *template* into cards never
 * changed that; it was already true before this split (three separate
 * <form> wrappers, one shared form object) and still is.
 *
 * The one piece of state genuinely shared between two DIFFERENT cards —
 * previewAvailability, set by the Calendar card's own "Preview" button
 * but also read by the Public page card's own preview panel (preferring
 * real fetched data over its made-up example calendar once available) —
 * stays here and is threaded through as a v-model/prop pair. Everything
 * else (tooltip state, the synthetic example calendar, calendar_url's own
 * mini-form, etc.) is now fully local to whichever single card actually
 * uses it.
 */
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { BAlert } from 'bootstrap-vue-next';
import { ref, watch, onUnmounted } from 'vue';
import DashboardLayout from '../../Layouts/DashboardLayout.vue';
import { useLiveThemePreview } from '../../dashboard/liveThemePreview';
import SettingsCalendarCard from '../../dashboard/SettingsCalendarCard.vue';
import SettingsEventMatchingCard from '../../dashboard/SettingsEventMatchingCard.vue';
import SettingsWakeSleepCard from '../../dashboard/SettingsWakeSleepCard.vue';
import SettingsPublicPageCard from '../../dashboard/SettingsPublicPageCard.vue';
import { resolveSwatchHex } from '../../free/color-palette';
import { getDefaultSwatchKey } from '../../free/color-palette';
import { getDefaultIconKey } from '../../free/icon-palette';
import { getDefaultNowColorKey } from '../../free/now-color-presets';
import { useResolvedTheme } from '../../composables/useTheme';
import type { AvailabilityResponse } from '../../free/nuxt-blocks';
import type { SharedPageProps } from '../../sharedPageProps';
import type { Settings, SettingsDefaults } from '../../dashboard/settingsTypes';

defineOptions({ layout: DashboardLayout });

const props = defineProps<{
  settings: Settings;
  defaults: SettingsDefaults;
  timezones: string[];
  calendarUrl: string | null;
  sleepExceptions: { id: string; start_date: string; end_date: string; label_ciphertext: string | null }[];
}>();

const page = usePage<SharedPageProps>();

const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

const form = useForm({
  timezone: props.settings.timezone,
  week_start: props.settings.week_start,
  // None of these seven are pre-filled with their suggested default the
  // way the color-key fields below are — a color slot always needs *some*
  // resolved value to render, but a blank pattern here is a real,
  // functionally distinct state (dnd/nap/work/school: "genuinely off,
  // matches nothing"; highlight/tentative/open-end/open-start: "use the
  // built-in fallback pattern" for highlight, "genuinely off" for the
  // other three — see each card's own field descriptions). Silently
  // filling the form with the suggestion made an unsaved, still-blank-in-
  // the-database setting look already active — the suggestion is shown
  // as a "Suggested"/"Default" value with its own "Use" button instead.
  dnd_event_pattern: props.settings.dnd_event_pattern,
  nap_event_pattern: props.settings.nap_event_pattern,
  work_event_pattern: props.settings.work_event_pattern,
  school_event_pattern: props.settings.school_event_pattern,
  calendar_parsing_mode: props.settings.calendar_parsing_mode,
  highlight_clause_pattern: props.settings.highlight_clause_pattern,
  highlight_split_pattern: props.settings.highlight_split_pattern,
  activity_clause_pattern: props.settings.activity_clause_pattern ?? '',
  tentative_pattern: props.settings.tentative_pattern,
  open_end_pattern: props.settings.open_end_pattern,
  open_start_pattern: props.settings.open_start_pattern,
  public_page_title_en: props.settings.public_page_title_en ?? '',
  public_page_title_hu: props.settings.public_page_title_hu ?? '',
  accent_color_key: props.settings.accent_color_key ?? getDefaultSwatchKey('accent'),
  secondary_color_key: props.settings.secondary_color_key ?? getDefaultSwatchKey('secondary'),
  free_color_key: props.settings.free_color_key ?? getDefaultSwatchKey('free'),
  busy_color_key: props.settings.busy_color_key ?? getDefaultSwatchKey('busy'),
  work_color_key: props.settings.work_color_key ?? getDefaultSwatchKey('work'),
  school_color_key: props.settings.school_color_key ?? getDefaultSwatchKey('school'),
  sleep_color_key: props.settings.sleep_color_key ?? getDefaultSwatchKey('sleep'),
  highlight_color_key: props.settings.highlight_color_key ?? getDefaultSwatchKey('highlighted'),
  free_icon_key: props.settings.free_icon_key ?? getDefaultIconKey('free'),
  busy_icon_key: props.settings.busy_icon_key ?? getDefaultIconKey('busy'),
  work_icon_key: props.settings.work_icon_key ?? getDefaultIconKey('work'),
  school_icon_key: props.settings.school_icon_key ?? getDefaultIconKey('school'),
  sleep_icon_key: props.settings.sleep_icon_key ?? getDefaultIconKey('sleep'),
  highlight_icon_key: props.settings.highlight_icon_key ?? getDefaultIconKey('highlighted'),
  now_color_key: props.settings.now_color_key ?? getDefaultNowColorKey(),
  availability: days.map((_, i) => ({
    wake: props.settings.availability[i]?.wake ?? '',
    sleep: props.settings.availability[i]?.sleep ?? '',
  })),
});

/**
 * The exact type of this page's own single useForm() instance — every
 * SettingsXxxCard.vue receives `form` as a prop typed against this,
 * rather than each redeclaring its own approximation. Deliberately NOT
 * `ReturnType<typeof useForm<Settings>>`: useForm() here is called with
 * no explicit generic, so TS infers each field's type from the actual
 * initializer above (e.g. activity_clause_pattern narrows to plain
 * `string`, never `string | null`, because of its own `?? ''` fallback;
 * availability is a real array, not the Record<number, ...> shape
 * Settings.availability has as the raw server-payload prop) — an
 * explicit `<Settings>` generic would override that correct inference
 * with Settings' own (looser, prop-shaped) field types instead.
 */
export type SettingsForm = typeof form;

// Live-preview accent/secondary across the whole dashboard chrome (nav,
// links, muted text) as these two pickers are dragged in the Public page
// card, not just in that card's own preview panels — see
// liveThemePreview.ts. Cleared on unmount so navigating away restores the
// owner's actually-saved colors. Resolved against whichever theme the
// dashboard is actually rendered in right now, same as DashboardLayout
// does for the saved (non-live) colors — dragging the dark-mode picker
// while viewing in light mode shouldn't visibly change anything until the
// theme is actually switched.
const resolvedTheme = useResolvedTheme();
const liveTheme = useLiveThemePreview();
watch(
  () => [form.accent_color_key, form.secondary_color_key, resolvedTheme.value] as const,
  ([accentKey, secondaryKey, theme]) => {
    liveTheme.value = {
      accent: resolveSwatchHex(accentKey, 'accent', theme),
      secondary: resolveSwatchHex(secondaryKey, 'secondary', theme),
    };
  },
  { immediate: true },
);
onUnmounted(() => {
  liveTheme.value = null;
});

/** Set by SettingsCalendarCard's own "Preview" button; also read by SettingsPublicPageCard's preview panel (preferred over its own made-up example calendar once available) — see this file's own header comment. */
const previewAvailability = ref<AvailabilityResponse | null>(null);

function submit(): void {
  form.transform((data) => ({
    ...data,
    availability: Object.fromEntries(data.availability.map((day, i) => [i, day])),
  })).patch('/settings', {
    preserveScroll: true,
    // Updates form's own "reset to" baseline to the values just saved —
    // without this, every card's Reset button would always revert to
    // whatever was on the page at the very first load, never to a save
    // made sometime after that.
    onSuccess: () => form.defaults(),
  });
}
</script>

<template>
  <Head title="Settings" />

  <BAlert :model-value="!!page.props.flash?.status" variant="success">{{ page.props.flash?.status }}</BAlert>

  <SettingsCalendarCard v-model:preview-availability="previewAvailability" :form="form" :calendar-url="calendarUrl" :timezones="timezones" />
  <SettingsEventMatchingCard :form="form" :defaults="defaults" :submit="submit" />
  <SettingsWakeSleepCard :form="form" :sleep-exceptions="sleepExceptions" :submit="submit" />
  <SettingsPublicPageCard :form="form" :name="settings.name" :preview-availability="previewAvailability" :submit="submit" />
</template>
