<script setup lang="ts">
/**
 * Owner settings page — split into one component per card (see
 * resources/js/dashboard/SettingsXxxCard.vue) since the combined template
 * had grown genuinely too large to navigate as a single file. Each card now
 * owns its own useForm() instance (calendarSettingsForm/
 * publicPageSettingsForm/availabilitySettingsForm/eventMatchingSettingsForm,
 * all created here and passed down as props) and PATCHes /settings with
 * only its own fields — SettingsController::update() only ever touches
 * keys actually present in a given request, so one card's save can never
 * clobber another's. calendar_url has its own third form/endpoint
 * entirely (see SettingsCalendarCard's calendarUrlForm and
 * SettingsController::updateCalendarUrl), unchanged by this split.
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
import SettingsSleepExceptionsCard from '../../dashboard/SettingsSleepExceptionsCard.vue';
import DashboardLayout from '../../Layouts/DashboardLayout.vue';
import { useLiveThemePreview } from '../../dashboard/liveThemePreview';
import SettingsActivityLocalizationsCard from '../../dashboard/SettingsActivityLocalizationsCard.vue';
import SettingsCalendarCard from '../../dashboard/SettingsCalendarCard.vue';
import SettingsEventMatchingCard from '../../dashboard/SettingsEventMatchingCard.vue';
import SettingsAvailabilityCard from '../../dashboard/SettingsAvailabilityCard.vue';
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
  sleepExceptions: {
    id: string;
    start_date: string;
    end_date: string;
    label_ciphertext: string | null
  }[];
  activityLocalizations: {
    id: string;
    pattern: string;
    label: Record<string, string>;
    sort_order: number
  }[];
}>();

const page = usePage<SharedPageProps>();

const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

const calendarSettingsForm = useForm({
  timezone: props.settings.timezone,
  week_start: props.settings.week_start,
  calendar_parsing_mode: props.settings.calendar_parsing_mode,
});
export type CalendarSettingsForm = typeof calendarSettingsForm;

const publicPageSettingsForm = useForm({
  public_page_title: props.settings.public_page_title ?? {},
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
});
export type PublicPageSettingsForm = typeof publicPageSettingsForm;

const availabilitySettingsForm = useForm({
  availability: days.map((_, i) => ({
    wake: props.settings.availability[i]?.wake ?? '',
    sleep: props.settings.availability[i]?.sleep ?? '',
  })),
});
export type AvailabilitySettingsForm = typeof availabilitySettingsForm;

const eventMatchingSettingsForm = useForm({
  // None of these are pre-filled with their suggested default the
  // way the color-key fields are — a color slot always needs *some*
  // resolved value to render, but a blank pattern here is a real,
  // functionally distinct state. Silently filling the form with the
  // suggestion made an unsaved, still-blank-in- the-database setting
  // look already active — the suggestion is shown as a "Suggested"/
  // "Default" value with its own "Use" button instead.
  dnd_event_pattern: props.settings.dnd_event_pattern,
  nap_event_pattern: props.settings.nap_event_pattern,
  work_event_pattern: props.settings.work_event_pattern,
  school_event_pattern: props.settings.school_event_pattern,
  highlight_clause_pattern: props.settings.highlight_clause_pattern,
  highlight_split_pattern: props.settings.highlight_split_pattern,
  activity_clause_pattern: props.settings.activity_clause_pattern,
  tentative_pattern: props.settings.tentative_pattern,
  open_end_pattern: props.settings.open_end_pattern,
  open_start_pattern: props.settings.open_start_pattern,
});
export type EventMatchingSettingsForm = typeof eventMatchingSettingsForm;

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
  () => [publicPageSettingsForm.accent_color_key, publicPageSettingsForm.secondary_color_key, resolvedTheme.value] as const,
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
</script>

<template>
  <Head title="Settings" />

  <BAlert :model-value="!!page.props.flash?.status" variant="success">{{ page.props.flash?.status }}</BAlert>

  <SettingsCalendarCard
    v-model:preview-availability="previewAvailability"
    :availability-settings-form="availabilitySettingsForm"
    :calendar-url="calendarUrl"
    :calendarSettingsForm="calendarSettingsForm"
    :event-matching-settings-form="eventMatchingSettingsForm"
    :public-page-settings-form="publicPageSettingsForm"
    :timezones="timezones"
  />
  <SettingsEventMatchingCard
    :defaults="defaults"
    :eventMatchingSettingsForm="eventMatchingSettingsForm"
  />
  <SettingsActivityLocalizationsCard :activity-localizations="activityLocalizations" />
  <SettingsAvailabilityCard
    :availability-settings-form="availabilitySettingsForm"
    :calendar-settings-form="calendarSettingsForm"
  />
  <SettingsSleepExceptionsCard :sleep-exceptions="sleepExceptions" />
  <SettingsPublicPageCard
    :availability-settings-form="availabilitySettingsForm"
    :calendar-settings-form="calendarSettingsForm"
    :name="settings.name"
    :preview-availability="previewAvailability"
    :public-page-settings-form="publicPageSettingsForm"
  />
</template>
