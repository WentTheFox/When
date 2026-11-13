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
import { BAlert, BBadge, BCard, BTab, BTabs } from 'bootstrap-vue-next';
import { nextTick, onBeforeUnmount, onMounted, ref, watch, onUnmounted } from 'vue';
import { buildSettingsSearch, parseSettingsHash, SETTINGS_TABS, type SettingsTabName } from '../../dashboard/settingsTabs';
import SettingsSleepExceptionsCard from '../../dashboard/SettingsSleepExceptionsCard.vue';
import PageHeader from '../../Components/PageHeader.vue';
import DashboardLayout from '../../Layouts/DashboardLayout.vue';
import { useLiveThemePreview } from '../../dashboard/liveThemePreview';
import SettingsActivityLocalizationsCard from '../../dashboard/SettingsActivityLocalizationsCard.vue';
import SettingsCalendarCard from '../../dashboard/SettingsCalendarCard.vue';
import SettingsConnectionsCard from '../../dashboard/SettingsConnectionsCard.vue';
import SettingsShareLinksCard from '../../dashboard/SettingsShareLinksCard.vue';
import SettingsDiscordCard from '../../dashboard/SettingsDiscordCard.vue';
import SettingsTelegramCard from '../../dashboard/SettingsTelegramCard.vue';
import SettingsVrchatCard from '../../dashboard/SettingsVrchatCard.vue';
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
  identifierListDefinitions: { id: string; label_ciphertext: string }[];
  booleanDefinitions: { id: string; label_ciphertext: string }[];
  alternativeNamesDefinitions: { id: string; label_ciphertext: string; type: string }[];
  graphFieldDefinitions: { id: string; label_ciphertext: string; type: string }[];
  connectionSources: { id: string; name_ciphertext: string; color_key: string | null }[];
  vrchatIdDefinitions: { id: string; label_ciphertext: string; type: string }[];
  vrcx: {
    base_url: string | null;
    api_key: string | null;
    vrchat_user_id_definition_id: string | null;
    discord_user_id_definition_id: string | null;
    auto_create_friends: boolean;
    friends_source_id: string | null;
    synced_at: string | null;
  };
  discord: {
    available: boolean;
    connected: boolean;
    account_name: string | null;
    synced_at: string | null;
    user_id_definition_id: string | null;
  };
  /** `available` is false unless the operator set up the optional Telegram sidecar: the tab is then hidden. */
  telegram: {
    available: boolean;
    connected: boolean;
    account_name: string | null;
    synced_at: string | null;
    user_id_definition_id: string | null;
  };
  sleepExceptions: {
    id: string;
    start_date: string;
    end_date: string;
    label_ciphertext: string | null
  }[];
  activityLocalizations: {
    id: string;
    pattern: string;
    pattern_preview: string | null;
    label: Record<string, string>;
    sort_order: number;
    icon_key: string | null;
    color_key: string | null;
  }[];
}>();

const page = usePage<SharedPageProps>();

const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

/**
 * timezone stays null in the DB until an owner's first Settings save (see
 * settingsTypes.ts) — rather than showing that as a blank/UTC-looking
 * select on someone's very first visit, try the browser's own IANA zone
 * first. Only ever seeds the in-memory form value; nothing is persisted
 * until the owner actually submits this card, same as any other field
 * here. Falls back to 'UTC' if Intl is unavailable or resolves to
 * something not in the server's own known-timezones list.
 */
function detectTimezone(knownTimezones: string[]): string {
  try {
    const detected = Intl.DateTimeFormat().resolvedOptions().timeZone;
    if (detected && knownTimezones.includes(detected)) return detected;
  } catch {
    // Intl.DateTimeFormat unsupported/threw — fall through to UTC below.
  }
  return 'UTC';
}

const calendarSettingsForm = useForm({
  timezone: props.settings.timezone ?? detectTimezone(props.timezones),
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
  public_color_key: props.settings.public_color_key ?? getDefaultSwatchKey('public'),
  sleep_color_key: props.settings.sleep_color_key ?? getDefaultSwatchKey('sleep'),
  highlight_color_key: props.settings.highlight_color_key ?? getDefaultSwatchKey('highlighted'),
  free_icon_key: props.settings.free_icon_key ?? getDefaultIconKey('free'),
  busy_icon_key: props.settings.busy_icon_key ?? getDefaultIconKey('busy'),
  public_icon_key: props.settings.public_icon_key ?? getDefaultIconKey('public'),
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
  dnd_event_pattern_preview: props.settings.dnd_event_pattern_preview,
  nap_event_pattern: props.settings.nap_event_pattern,
  nap_event_pattern_preview: props.settings.nap_event_pattern_preview,
  public_event_pattern: props.settings.public_event_pattern,
  public_event_pattern_preview: props.settings.public_event_pattern_preview,
  highlight_clause_pattern: props.settings.highlight_clause_pattern,
  highlight_clause_pattern_preview: props.settings.highlight_clause_pattern_preview,
  highlight_split_pattern: props.settings.highlight_split_pattern,
  highlight_split_pattern_preview: props.settings.highlight_split_pattern_preview,
  activity_clause_pattern: props.settings.activity_clause_pattern,
  activity_clause_pattern_preview: props.settings.activity_clause_pattern_preview,
  tentative_pattern: props.settings.tentative_pattern,
  tentative_pattern_preview: props.settings.tentative_pattern_preview,
  open_end_pattern: props.settings.open_end_pattern,
  open_end_pattern_preview: props.settings.open_end_pattern_preview,
  open_start_pattern: props.settings.open_start_pattern,
  open_start_pattern_preview: props.settings.open_start_pattern_preview,
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

/**
 * The page is split into tabs (the cards sat in one long scroll). Every tab's
 * content stays mounted while hidden, so nothing is lost switching between them
 * (the forms live in this component anyway). The active tab is kept in the `?tab=` query
 * parameter with replaceState, so it survives a reload without piling up history, and
 * a link to an element id (#share-links, #highlight-tokens, ...) opens the tab
 * holding it and then scrolls there (see settingsTabs.ts).
 */
const initialHash = typeof window === 'undefined' ? { tab: 'calendar' as SettingsTabName } : parseSettingsHash(window.location.hash, window.location.search);
const activeTab = ref<string>(SETTINGS_TABS[initialHash.tab]);

function activeTabName(): SettingsTabName {
  return (Object.keys(SETTINGS_TABS) as SettingsTabName[]).find((name) => SETTINGS_TABS[name] === activeTab.value) ?? 'calendar';
}

function writeHash(): void {
  // An element-id hash that already leads to this tab stays as it is, and the query matches the tab.
  const search = buildSettingsSearch(activeTabName(), window.location.search);
  if (search === window.location.search && parseSettingsHash(window.location.hash, search).tab === activeTabName()) return;

  // A hash naming another tab's element would fight the query, so it is dropped.
  const hash = parseSettingsHash(window.location.hash).anchor && parseSettingsHash(window.location.hash).tab === activeTabName() ? window.location.hash : '';
  window.history.replaceState(window.history.state, '', `${window.location.pathname}${search}${hash}`);
}

watch(activeTab, writeHash);

/** Opens the tab the hash names, then scrolls to the element it points at (once the tab is visible). */
function followHash(): void {
  const state = parseSettingsHash(window.location.hash, window.location.search);
  activeTab.value = SETTINGS_TABS[state.tab];

  if (state.anchor) {
    const anchor = state.anchor;
    nextTick(() => document.getElementById(anchor)?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
  }
}

onMounted(() => {
  followHash();
  window.addEventListener('hashchange', followHash);
});
onBeforeUnmount(() => window.removeEventListener('hashchange', followHash));

/** Set by SettingsCalendarCard's own "Preview" button; also read by SettingsPublicPageCard's preview panel (preferred over its own made-up example calendar once available) — see this file's own header comment. */
const previewAvailability = ref<AvailabilityResponse | null>(null);
</script>

<template>
  <Head title="Settings" />

  <PageHeader title="Settings" subtitle="Your calendar, how event titles are read, sharing, VRChat and your public page." />

  <BAlert :model-value="!!page.props.flash?.status" variant="success">{{ page.props.flash?.status }}</BAlert>

  <!-- One card, like Connections: the tab bar inside it and one tab per settings section (each its own form). -->
  <BCard class="mb-4">
    <BTabs v-model="activeTab" pills no-fade content-class="pt-3">
      <BTab :id="SETTINGS_TABS.calendar" title="Calendar">
        <SettingsCalendarCard
          v-model:preview-availability="previewAvailability"
          :availability-settings-form="availabilitySettingsForm"
          :calendar-url="calendarUrl"
          :calendarSettingsForm="calendarSettingsForm"
          :event-matching-settings-form="eventMatchingSettingsForm"
          :public-page-settings-form="publicPageSettingsForm"
          :timezones="timezones"
        />
      </BTab>

      <BTab :id="SETTINGS_TABS.availability" title="Wake & sleep">
        <SettingsAvailabilityCard
          :availability-settings-form="availabilitySettingsForm"
          :calendar-settings-form="calendarSettingsForm"
        />
      </BTab>

      <BTab :id="SETTINGS_TABS.sleep">
        <template #title>
          Sleep exceptions
          <BBadge variant="secondary" pill class="ms-1">{{ sleepExceptions.length }}</BBadge>
        </template>
        <SettingsSleepExceptionsCard :sleep-exceptions="sleepExceptions" />
      </BTab>

      <BTab :id="SETTINGS_TABS.matching" title="Event matching">
        <SettingsEventMatchingCard
          :defaults="defaults"
          :eventMatchingSettingsForm="eventMatchingSettingsForm"
          :highlight-field-set="settings.highlight_identifier_definition_id !== null"
          :bypass-field-set="settings.bypass_dnd_definition_id !== null"
        />
      </BTab>

      <BTab :id="SETTINGS_TABS.activities">
        <template #title>
          Activity labels
          <BBadge variant="secondary" pill class="ms-1">{{ activityLocalizations.length }}</BBadge>
        </template>
        <SettingsActivityLocalizationsCard :activity-localizations="activityLocalizations" />
      </BTab>

      <BTab :id="SETTINGS_TABS.sharing" title="Share links">
        <SettingsShareLinksCard
          :highlight-definitions="identifierListDefinitions"
          :bypass-definitions="booleanDefinitions"
          :selected-highlight-id="settings.highlight_identifier_definition_id"
          :selected-bypass-id="settings.bypass_dnd_definition_id"
        />
      </BTab>

      <BTab :id="SETTINGS_TABS.connections" title="Connections">
        <SettingsConnectionsCard
          :definitions="alternativeNamesDefinitions"
          :selected-id="settings.alternative_names_definition_id"
          :graph-definitions="graphFieldDefinitions"
          :selected-graph-ids="settings.graph_field_ids"
        />
      </BTab>

      <BTab :id="SETTINGS_TABS.vrchat" title="VRChat">
        <SettingsVrchatCard :definitions="vrchatIdDefinitions" :sources="connectionSources" :vrcx="vrcx" />
      </BTab>

      <BTab :id="SETTINGS_TABS.discord" title="Discord">
        <SettingsDiscordCard :definitions="vrchatIdDefinitions" :discord="discord" />
      </BTab>

      <BTab v-if="telegram.available" :id="SETTINGS_TABS.telegram" title="Telegram">
        <SettingsTelegramCard :definitions="vrchatIdDefinitions" :telegram="telegram" />
      </BTab>

      <BTab :id="SETTINGS_TABS.public" title="Public page">
        <SettingsPublicPageCard
          :availability-settings-form="availabilitySettingsForm"
          :calendar-settings-form="calendarSettingsForm"
          :preview-availability="previewAvailability"
          :public-page-settings-form="publicPageSettingsForm"
        />
      </BTab>
    </BTabs>
  </BCard>
</template>
