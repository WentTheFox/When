<script setup lang="ts">
/**
 * Ported from WentTheNuxt's app/components/free/AgendaView.vue — same
 * adaptations as CalendarView.vue (see that file's header comment): laravel-
 * vue-i18n instead of vue-i18n, plain wtf-fagenda-* CSS classes instead of a
 * CSS module, local FontAwesomeIcon import, a spinning icon instead of
 * CutieMarkPlayer. This is the mobile/narrow-viewport view (shown by
 * .wtf-mobile-only below md, same breakpoint CalendarView.vue hides itself
 * at) — a per-day list instead of a side-by-side week grid.
 */
import { addDays, format, subDays } from 'date-fns';
import { resolveDateFnsLocale } from './dateFnsLocale';
import { TZDate } from '@date-fns/tz';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { faSpinner } from '@fortawesome/free-solid-svg-icons';
import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import { computed } from 'vue';
import { currentLocale, trans } from 'laravel-vue-i18n';
import { formatFromTime, formatReservedDuration, formatTentativeStart, formatUntilTime, getBlocksForDay, isTentativeEndDisplay, isTentativeStartDisplay, isTentativeSuffixShown, pctToTime, tildeTime } from './nuxt-blocks';
import type { DayBlock, FreeSlot, HighlightedSlot, TentativeSlot } from './nuxt-blocks';
import { resolveLocalizedText } from './localizedText';

const AGENDA_SLOT_CLASS: Record<DayBlock['type'], string> = {
  free: '',
  unavailable: 'wtf-fagenda-slot-unavailable',
  highlighted: 'wtf-fagenda-slot-highlighted',
  work: 'wtf-fagenda-slot-work',
  school: 'wtf-fagenda-slot-school',
  sleep: 'wtf-fagenda-slot-sleep',
};

const AGENDA_SLOT_LABEL_KEY: Record<DayBlock['type'], string> = {
  free: 'free.freeLabel',
  unavailable: 'free.unavailableLabel',
  highlighted: 'free.highlightedLabel',
  work: 'free.workLabel',
  school: 'free.schoolLabel',
  sleep: 'free.sleepLabel',
};

const AGENDA_SLOT_COLOR_VAR: Record<DayBlock['type'], string> = {
  free: '--app-color-free',
  unavailable: '--app-color-busy',
  highlighted: '--app-color-highlighted',
  work: '--app-color-work',
  school: '--app-color-school',
  sleep: '--app-color-sleep',
};

const props = defineProps<{
  days: Date[];
  freeSlots: FreeSlot[];
  highlightedSlots: HighlightedSlot[];
  unavailableSlots: TentativeSlot[];
  workSlots: TentativeSlot[];
  schoolSlots: TentativeSlot[];
  sleepSlots: FreeSlot[];
  /** Owner-customizable per block type — already resolved to real FA icons by Free/Show.vue's resolvedIcons (icon-palette.ts). */
  icons: { free: IconDefinition; busy: IconDefinition; work: IconDefinition; school: IconDefinition; sleep: IconDefinition; highlighted: IconDefinition };
  pending: boolean;
  hasError: boolean;
  timezone: string;
  showBlocks: boolean;
  showCurrentTime: boolean;
  currentTimePct: number;
}>();

// Same DayBlock-type-vs-icon-slot-name mismatch as CalendarView.vue.
const slotTypeIcon = computed<Record<DayBlock['type'], IconDefinition>>(() => ({
  free: props.icons.free,
  unavailable: props.icons.busy,
  highlighted: props.icons.highlighted,
  work: props.icons.work,
  school: props.icons.school,
  sleep: props.icons.sleep,
}));

const dateFnsLocale = computed(() => resolveDateFnsLocale(currentLocale.value));

function slotLabel(slot: DayBlock): string {
  if (slot.type === 'highlighted') {
    const roleLabel = resolveLocalizedText(slot.activityLabel, currentLocale.value);
    if (roleLabel) return roleLabel;
    if (slot.activity) return slot.activity;
  }
  return trans(AGENDA_SLOT_LABEL_KEY[slot.type]);
}

function slotTimeText(slot: DayBlock): string {
  const startFuzzy = isTentativeStartDisplay(slot);
  const endFuzzy = isTentativeEndDisplay(slot);

  // A single fuzzy edge collapses to just its known side + a reserved
  // duration ("From 17:00 (2h reserved)" / "Until 19:00 (2h reserved)")
  // rather than an explicit range — there's no point printing a clock time
  // for the edge we don't actually know.
  if (startFuzzy || endFuzzy) {
    const duration = trans('free.reservedSuffix', { duration: formatReservedDuration(slot.startTime, slot.endTime, currentLocale.value) });
    if (startFuzzy && endFuzzy) return `${formatTentativeStart(slot.startTime, currentLocale.value)} (${duration})`;
    if (startFuzzy) return `${formatUntilTime(slot.endTime, currentLocale.value)} (${duration})`;
    return `${formatFromTime(slot.startTime, currentLocale.value)} (${duration})`;
  }

  return `${tildeTime(slot.startTime, startFuzzy)} – ${tildeTime(slot.endTime, endFuzzy)}`;
}

function isDayToday(day: Date): boolean {
  const tzNow = new TZDate(new Date(), props.timezone);
  const tzDay = new TZDate(day, props.timezone);
  return (
    tzNow.getFullYear() === tzDay.getFullYear() &&
    tzNow.getMonth() === tzDay.getMonth() &&
    tzNow.getDate() === tzDay.getDate()
  );
}

function formatDay(day: Date, fmt: string): string {
  return format(new TZDate(day, props.timezone), fmt, { locale: dateFnsLocale.value });
}

function slotHeightStyle(heightPct: number): Record<string, string> {
  const proportionalRem = (heightPct / 100) * 24;
  return {
    minHeight: '2.5rem',
    height: `min(20vh, ${proportionalRem}rem)`,
  };
}

// Same neighbor-blending idea as the week view: only an edge that's actually
// fuzzy (tentativeStart/tentativeEnd, independently) blends into the
// adjacent slot's color — the other edge renders as a hard line at its own
// solid color. At the very top/bottom of a day's own slots, the neighbor
// carries over from the previous/next calendar day's last/first slot,
// computed directly rather than looked up in the rendered day list — the
// visible range can trim a day (e.g. past-day filtering) while the API
// still returns that day's data, padded a day either side of the requested
// range — falling back to transparent only where there's truly no data for
// the adjacent day (a hard, non-fuzzy edge never falls back to transparent,
// since it always renders its own solid color).
function tentativeFadeStyle(day: Date, slots: DayBlock[], i: number): Record<string, string> {
  const slot = slots[i]!;
  const startFuzzy = isTentativeStartDisplay(slot);
  const endFuzzy = isTentativeEndDisplay(slot);
  if (!startFuzzy && !endFuzzy) return {};

  const style: Record<string, string> = {};

  if (startFuzzy) {
    const prev = i > 0
      ? slots[i - 1]
      : getBlocksForDay(subDays(day, 1), props.freeSlots, props.highlightedSlots, props.unavailableSlots, props.sleepSlots, props.timezone, props.workSlots, props.schoolSlots).at(-1);
    if (prev) style['--fade-start'] = `var(${AGENDA_SLOT_COLOR_VAR[prev.type]})`;
  } else {
    style['--fade-start'] = `var(${AGENDA_SLOT_COLOR_VAR[slot.type]})`;
  }

  if (endFuzzy) {
    const next = i < slots.length - 1
      ? slots[i + 1]
      : getBlocksForDay(addDays(day, 1), props.freeSlots, props.highlightedSlots, props.unavailableSlots, props.sleepSlots, props.timezone, props.workSlots, props.schoolSlots)[0];
    if (next) style['--fade-end'] = `var(${AGENDA_SLOT_COLOR_VAR[next.type]})`;
  } else {
    style['--fade-end'] = `var(${AGENDA_SLOT_COLOR_VAR[slot.type]})`;
  }

  return style;
}

const agendaEntries = computed(() =>
  props.days.map(day => {
    const isToday = isDayToday(day);
    const slots = props.showBlocks
      ? getBlocksForDay(day, props.freeSlots, props.highlightedSlots, props.unavailableSlots, props.sleepSlots, props.timezone, props.workSlots, props.schoolSlots)
          .map(b => ({
            ...b,
            startTime: b.startTime || pctToTime(b.topPct),
            endTime: b.endTime || pctToTime(b.topPct + b.heightPct),
          }))
          .sort((a, b) => a.topPct - b.topPct)
      : [];

    let currentTimeSlotIndex = -1;
    let currentTimeOffsetPct = 0;
    if (isToday && props.showCurrentTime) {
      const pct = props.currentTimePct;
      currentTimeSlotIndex = slots.findIndex(s => pct >= s.topPct && pct < s.topPct + s.heightPct);
      if (currentTimeSlotIndex >= 0) {
        const s = slots[currentTimeSlotIndex]!;
        currentTimeOffsetPct = ((pct - s.topPct) / s.heightPct) * 100;
      }
    }

    return { day, slots, isToday, currentTimeSlotIndex, currentTimeOffsetPct };
  }),
);
</script>

<template>
  <div class="wtf-fagenda-wrap">
    <div v-if="pending" class="wtf-fagenda-loading-overlay">
      <FontAwesomeIcon :icon="faSpinner" spin size="2x" />
    </div>
    <div v-else-if="hasError" class="wtf-fagenda-error-state">
      {{ $t('free.error') }}
    </div>
    <div v-else class="wtf-fagenda-list">
      <div
        v-for="{ day, slots, isToday, currentTimeSlotIndex, currentTimeOffsetPct } in agendaEntries"
        :key="formatDay(day, 'yyyy-MM-dd')"
        class="wtf-fagenda-day"
      >
        <div class="wtf-fagenda-day-header" :class="{ 'is-today': isToday }">
          <span class="wtf-fagenda-day-name">{{ formatDay(day, 'EEE') }}</span>
          <span class="wtf-fagenda-day-date">{{ formatDay(day, 'MMM d') }}</span>
        </div>
        <div
          v-for="(slot, i) in slots"
          :key="i"
          class="wtf-fagenda-slot"
          :class="[AGENDA_SLOT_CLASS[slot.type], { 'wtf-fagenda-slot-tentative': isTentativeStartDisplay(slot) || isTentativeEndDisplay(slot) }]"
          :style="{ ...slotHeightStyle(slot.heightPct), ...tentativeFadeStyle(day, slots, i) }"
        >
          <div
            v-if="i === currentTimeSlotIndex"
            class="wtf-fagenda-current-time"
            :style="{ top: `${currentTimeOffsetPct}%` }"
          />
          <span class="wtf-fagenda-slot-time">{{ slotTimeText(slot) }}</span>
          <span class="wtf-fagenda-slot-label"><FontAwesomeIcon :icon="slotTypeIcon[slot.type]" class="wtf-fagenda-slot-icon me-1" />{{ slotLabel(slot) }}{{ isTentativeSuffixShown(slot) ? $t('free.tentativeSuffix') : '' }}</span>
        </div>
      </div>
    </div>
  </div>
</template>
