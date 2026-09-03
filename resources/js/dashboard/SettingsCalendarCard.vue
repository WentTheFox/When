<script setup lang="ts">
/**
 * Settings.vue's "Calendar" card — the calendar_url mini-form (its own
 * endpoint, see SettingsController::updateCalendarUrl's doc comment for
 * why it's deliberately separate from the main settings save) plus
 * parsing-mode/timezone/week-start, which live in this card visually but
 * are still part of the shared `form` saved by Settings.vue's own
 * submit(), not this component's own calendarUrlForm.
 */
import { useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { faEye, faEyeSlash } from '@fortawesome/free-solid-svg-icons';
import { BAlert, BBadge, BButton, BCard, BFormGroup, BFormInput, BFormSelect, BInputGroup } from 'bootstrap-vue-next';
import { addDays as addDaysFns, startOfWeek as startOfWeekFns } from 'date-fns';
import { computed, ref } from 'vue';
import CalendarView from '../free/CalendarView.vue';
import { resolveIcon } from '../free/icon-palette';
import { resolveNowColorHex } from '../free/now-color-presets';
import { resolveSwatchHex } from '../free/color-palette';
import { BLOCK_ALPHA, hexToRgba, hexToRgbTriplet, yiqTextColor } from '../free/color-utils';
import { useResolvedTheme } from '../composables/useTheme';
import type { AvailabilityResponse } from '../free/nuxt-blocks';
import type { SettingsForm } from '../Pages/Dashboard/Settings.vue';

const props = defineProps<{
  form: SettingsForm;
  calendarUrl: string | null;
  timezones: string[];
}>();

// v-model:preview-availability — the one piece of state the sibling
// "Public page" card also needs (its own preview panel prefers this over
// its made-up example data once a real preview has been fetched here).
const previewAvailability = defineModel<AvailabilityResponse | null>('previewAvailability', { default: null });

const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const weekStartOptions = days.map((label, value) => ({ value, label }));

const calendarUrlForm = useForm({
  calendar_url: props.calendarUrl ?? '',
  calendar_url_preview_confirmed: false as boolean,
});
const hadSavedCalendarUrl = ref(!!props.calendarUrl);
const calendarUrlRevealed = ref(false);

const previewStatus = ref('');
const previewing = ref(false);
const previewResult = ref<{ detected_mode: string; slotCount: number } | null>(null);
const calendarUrlJustSaved = ref(false);

const resolvedTheme = useResolvedTheme();
const previewDays = computed(() => {
  const weekStart = startOfWeekFns(new Date(), { weekStartsOn: props.form.week_start as 0 | 1 | 2 | 3 | 4 | 5 | 6 });
  return Array.from({ length: 7 }, (_, i) => addDaysFns(weekStart, i));
});

const currentTimePct = (() => {
  const now = new Date();
  return ((now.getHours() * 60 + now.getMinutes()) / 1440) * 100;
})();

/** Fed to this card's own live CalendarView preview — icons aren't theme-reactive (see icon-palette.ts), so this is a single computed, not a light/dark pair. */
const formIcons = computed(() => ({
  free: resolveIcon(props.form.free_icon_key, 'free'),
  busy: resolveIcon(props.form.busy_icon_key, 'busy'),
  work: resolveIcon(props.form.work_icon_key, 'work'),
  school: resolveIcon(props.form.school_icon_key, 'school'),
  sleep: resolveIcon(props.form.sleep_icon_key, 'sleep'),
  highlighted: resolveIcon(props.form.highlight_icon_key, 'highlighted'),
}));

/**
 * <input type="color"> only ever gives a solid hex, no alpha — binding it
 * straight to --wtf-color-* would make these preview blocks fully opaque,
 * losing the transparent-wash treatment every block gets on the real /free
 * page (see color-utils.ts). Re-applies the same alpha so what's previewed
 * here actually matches what a viewer would see. Same helper as
 * SettingsPublicPageCard.vue's own previewStyleFor — duplicated rather
 * than hoisted to a shared composable since each card's own theme
 * (live-following here, both themes side by side there) only needs its
 * own single call.
 */
function previewStyleFor(theme: 'light' | 'dark') {
  const accent = resolveSwatchHex(props.form.accent_color_key, 'accent', theme);
  const free = resolveSwatchHex(props.form.free_color_key, 'free', theme);
  const busy = resolveSwatchHex(props.form.busy_color_key, 'busy', theme);
  const work = resolveSwatchHex(props.form.work_color_key, 'work', theme);
  const school = resolveSwatchHex(props.form.school_color_key, 'school', theme);
  const sleep = resolveSwatchHex(props.form.sleep_color_key, 'sleep', theme);
  const highlighted = resolveSwatchHex(props.form.highlight_color_key, 'highlighted', theme);
  const alpha = BLOCK_ALPHA[theme];

  return {
    '--wtf-accent': accent,
    '--wtf-accent-rgb': hexToRgbTriplet(accent),
    '--wtf-accent-text': yiqTextColor(accent),
    '--wtf-color-free': hexToRgba(free, alpha.free),
    '--wtf-hue-free': free,
    '--wtf-color-busy': hexToRgba(busy, alpha.busy),
    '--wtf-color-work': hexToRgba(work, alpha.work),
    '--wtf-hue-work': work,
    '--wtf-color-school': hexToRgba(school, alpha.school),
    '--wtf-hue-school': school,
    '--wtf-color-sleep': hexToRgba(sleep, alpha.sleep),
    '--wtf-hue-sleep': sleep,
    '--wtf-color-highlighted': hexToRgba(highlighted, alpha.highlighted),
    '--wtf-hue-highlighted': highlighted,
    '--wtf-color-now': resolveNowColorHex(props.form.now_color_key, theme),
  };
}

/** Follows the page's own live theme (not a fixed side-by-side pair) — this panel isn't wrapped in its own .wtf-theme-preview scope the way the Public page card's is. */
const previewStyleLive = computed(() => previewStyleFor(resolvedTheme.value));

function onUrlInput(): void {
  calendarUrlForm.calendar_url_preview_confirmed = false;
  previewResult.value = null;
  previewAvailability.value = null;
  calendarUrlJustSaved.value = false;
}

async function preview(): Promise<void> {
  if (!calendarUrlForm.calendar_url) return;

  previewStatus.value = 'Fetching…';
  previewing.value = true;

  try {
    const availabilitySettings = Object.fromEntries(props.form.availability.map((day, i) => [i, day]));

    const { data } = await axios.post('/settings/calendar/preview', {
      calendar_url: calendarUrlForm.calendar_url,
      timezone: props.form.timezone,
      calendar_parsing_mode: props.form.calendar_parsing_mode,
      dnd_event_pattern: props.form.dnd_event_pattern,
      nap_event_pattern: props.form.nap_event_pattern,
      work_event_pattern: props.form.work_event_pattern,
      school_event_pattern: props.form.school_event_pattern,
      highlight_clause_pattern: props.form.highlight_clause_pattern,
      highlight_split_pattern: props.form.highlight_split_pattern,
      activity_clause_pattern: props.form.activity_clause_pattern,
      tentative_pattern: props.form.tentative_pattern,
      open_end_pattern: props.form.open_end_pattern,
      open_start_pattern: props.form.open_start_pattern,
      availability_settings: availabilitySettings,
    });

    previewResult.value = {
      detected_mode: data.detected_mode,
      slotCount: data.free.length + data.highlighted.length + data.unavailable.length + data.sleep.length + data.school.length,
    };
    // Suggest a parsing mode from what the feed actually contains, but only
    // for a brand-new setup — re-previewing an already-saved URL must never
    // silently clobber a mode the owner deliberately chose. "mixed" maps to
    // full_detail, not free_busy_only: that's the only choice that doesn't
    // drop title matching for the feed's real-titled events.
    if (!hadSavedCalendarUrl.value) {
      props.form.calendar_parsing_mode = data.detected_mode === 'free_busy_only' ? 'free_busy_only' : 'full_detail';
    }
    previewAvailability.value = {
      free: data.free,
      highlighted: data.highlighted,
      unavailable: data.unavailable,
      work: data.work,
      school: data.school,
      sleep: data.sleep,
    };
    calendarUrlForm.calendar_url_preview_confirmed = true;
    previewStatus.value = 'Looks good.';
  } catch (error) {
    console.error(error);
    previewStatus.value = 'Could not fetch that calendar. Check the URL and try again.';
    calendarUrlForm.calendar_url_preview_confirmed = false;
  } finally {
    previewing.value = false;
  }
}

function saveCalendarUrl(): void {
  calendarUrlJustSaved.value = false;

  calendarUrlForm.patch('/settings/calendar-url', {
    preserveScroll: true,
    onSuccess: () => {
      // Shown back verbatim (see the form's own doc comment above), so a
      // successful save keeps the just-saved value visible rather than
      // clearing the field — update the form's own "reset to" baseline to
      // match, and require a fresh Preview for anything typed after this.
      calendarUrlForm.calendar_url_preview_confirmed = false;
      calendarUrlForm.defaults();
      hadSavedCalendarUrl.value = true;
      previewResult.value = null;
      previewStatus.value = '';
      // The page-level flash alert lands at the very top of a long page and
      // preserveScroll keeps the view right here — without this, saving
      // leaves no visible trace where the owner is actually looking.
      calendarUrlJustSaved.value = true;
    },
  });
}

/** Mirrors onUrlInput's own cleanup — form.reset() sets calendar_url programmatically, which doesn't fire the native @input event onUrlInput is normally bound to. */
function resetCalendarUrl(): void {
  calendarUrlForm.reset();
  onUrlInput();
}
</script>

<template>
  <BCard class="mb-4">
    <h1 class="h3 mb-4">Settings</h1>

    <h2 class="h5 mb-3">Calendar</h2>

    <!--
      Its own form/endpoint, deliberately separate from the big settings
      form below — see SettingsController::updateCalendarUrl's doc
      comment. A pending, not-yet-previewed URL must only ever be able to
      block itself, never an unrelated change like timezone.
    -->
    <form @submit.prevent="saveCalendarUrl">
      <BFormGroup label-for="calendar_url" class="mb-3">
        <template #label>
          Calendar URL (ICS feed)
          <BBadge v-if="hadSavedCalendarUrl" variant="success" class="ms-1">Configured</BBadge>
          <BBadge v-else variant="secondary" class="ms-1">Not set</BBadge>
        </template>
        <BInputGroup>
          <BFormInput
            id="calendar_url"
            v-model="calendarUrlForm.calendar_url"
            :type="calendarUrlRevealed ? 'text' : 'password'"
            placeholder="https://..."
            @input="onUrlInput"
          />
          <BButton
            variant="outline-secondary"
            :aria-label="calendarUrlRevealed ? 'Hide calendar URL' : 'Show calendar URL'"
            @click="calendarUrlRevealed = !calendarUrlRevealed"
          >
            <FontAwesomeIcon :icon="calendarUrlRevealed ? faEyeSlash : faEye" />
          </BButton>
        </BInputGroup>
        <template #description>
          {{ hadSavedCalendarUrl
            ? "Edit it and preview before saving to replace it."
            : "Paste your calendar's ICS URL, then preview it before saving." }}
        </template>
        <div v-if="calendarUrlForm.errors.calendar_url" class="text-danger small">{{ calendarUrlForm.errors.calendar_url }}</div>
      </BFormGroup>

      <BButton
        variant="outline-secondary"
        :disabled="previewing || !calendarUrlForm.calendar_url"
        @click="preview"
      >
        Preview
      </BButton>
      <BButton
        v-if="calendarUrlForm.calendar_url_preview_confirmed"
        type="submit"
        variant="primary"
        class="ms-2"
        :disabled="calendarUrlForm.processing"
      >
        Save calendar URL
      </BButton>
      <BButton variant="outline-secondary" class="ms-2" @click="resetCalendarUrl">Reset</BButton>
      <span v-if="calendarUrlJustSaved" class="small text-success ms-2">Saved</span>
      <span v-else class="small text-muted ms-2">{{ previewStatus }}</span>

      <div v-if="previewResult" class="mt-3">
        <p class="mb-1"><strong>Detected feed type:</strong> {{ previewResult.detected_mode }}</p>
        <p class="mb-0 text-muted small">{{ previewResult.slotCount }} block(s) computed for the next 14 days.</p>
      </div>

      <div
        v-if="previewAvailability && !calendarUrlJustSaved"
        class="mt-3"
        :style="previewStyleLive"
      >
        <CalendarView
          :visible-days="previewDays"
          :free-slots="previewAvailability.free"
          :highlighted-slots="previewAvailability.highlighted"
          :unavailable-slots="previewAvailability.unavailable"
          :work-slots="previewAvailability.work"
          :school-slots="previewAvailability.school"
          :sleep-slots="previewAvailability.sleep"
          :icons="formIcons"
          :pending="false"
          :has-error="false"
          :has-any-free-time="true"
          :timezone="form.timezone"
          :show-blocks="true"
          :show-current-time="true"
          :current-time-pct="currentTimePct"
        />
      </div>
    </form>

    <hr class="my-4">

    <!--
      Parsing mode/timezone/week start all directly affect how the URL
      above gets parsed and previewed, so they live in this card too now,
      not a separate "Parsing & timezone" section — still part of the main
      settings `form`/submit() below, not the calendar_url mini-form above;
      physical placement here doesn't change which endpoint saves them.
    -->
    <BFormGroup label="Parsing mode" label-for="calendar_parsing_mode" class="mb-3">
      <BFormSelect id="calendar_parsing_mode" v-model="form.calendar_parsing_mode">
        <option value="full_detail">Full detail (event titles are used for highlighting)</option>
        <option value="free_busy_only">Free/busy only (track availability only, ignoring event titles)</option>
      </BFormSelect>
      <template #description>
        Previewing your calendar URL above picks one of these for you the first time you set it up, based on what
        your feed actually contains — change it here any time afterward if it guessed wrong.
      </template>
    </BFormGroup>

    <BFormGroup label="Timezone" label-for="timezone" class="mb-3">
      <BFormSelect id="timezone" v-model="form.timezone">
        <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
      </BFormSelect>
    </BFormGroup>

    <BFormGroup label="Week starts on" label-for="week_start" class="mb-3">
      <BFormSelect id="week_start" v-model="form.week_start">
        <option v-for="opt in weekStartOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
      </BFormSelect>
      <template #description>
        Applies to your public calendar's week/month view, both preview calendars below, and
        the row order of the Wake &amp; sleep times table.
      </template>
    </BFormGroup>
  </BCard>
</template>
