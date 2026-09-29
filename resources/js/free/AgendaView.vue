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
import { format } from 'date-fns';
import { resolveDateFnsLocale } from './dateFnsLocale';
import { TZDate } from '@date-fns/tz';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { faSpinner } from '@fortawesome/free-solid-svg-icons';
import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import { computed } from 'vue';
import { currentLocale, trans } from 'laravel-vue-i18n';
import { formatFromTime, formatReservedDuration, formatTentativeStart, formatUntilTime, getBlocksForDay, isTentativeEndDisplay, isTentativeStartDisplay, isTentativeSuffixShown, pctToTime, tildeTime } from './nuxt-blocks';
import type { DayBlock, EventSlot } from './nuxt-blocks';
import { resolveLocalizedText } from './localizedText';
import { resolveIcon } from './icon-palette';
import { activityColorStyle as activityColorStyleFor, tentativeFadeStyle } from './blockColors';
import { useResolvedTheme } from '../composables/useTheme';

const resolvedTheme = useResolvedTheme();

const AGENDA_SLOT_CLASS: Record<DayBlock['type'], string> = {
  free: '',
  unavailable: 'wtf-fagenda-slot-unavailable',
  highlighted: 'wtf-fagenda-slot-highlighted',
  work: 'wtf-fagenda-slot-work',
  school: 'wtf-fagenda-slot-school',
  public: 'wtf-fagenda-slot-public',
  sleep: 'wtf-fagenda-slot-sleep',
};

const AGENDA_SLOT_LABEL_KEY: Record<DayBlock['type'], string> = {
  free: 'free.freeLabel',
  unavailable: 'free.unavailableLabel',
  highlighted: 'free.highlightedLabel',
  work: 'free.workLabel',
  school: 'free.schoolLabel',
  public: 'free.publicLabel',
  sleep: 'free.sleepLabel',
};

const props = defineProps<{
  days: Date[];
  events: EventSlot[];
  /** Owner-customizable per block type — already resolved to real FA icons by Free/Show.vue's resolvedIcons (icon-palette.ts). */
  icons: { free: IconDefinition; busy: IconDefinition; work: IconDefinition; school: IconDefinition; public: IconDefinition; sleep: IconDefinition; highlighted: IconDefinition };
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
  public: props.icons.public,
  sleep: props.icons.sleep,
}));

const dateFnsLocale = computed(() => resolveDateFnsLocale(currentLocale.value));

/** Same per-role icon override as CalendarView.vue's own iconFor — see its doc comment. */
function iconFor(slot: DayBlock): IconDefinition {
  if ((slot.type === 'highlighted' || slot.type === 'public') && slot.activityIcon) {
    return resolveIcon(slot.activityIcon, slot.type);
  }
  return slotTypeIcon.value[slot.type];
}

function slotLabel(slot: DayBlock): string {
  if (slot.type === 'highlighted') {
    const roleLabel = resolveLocalizedText(slot.activityLabel, currentLocale.value);
    if (roleLabel) return roleLabel;
    if (slot.activity) return slot.activity;
  }
  if (slot.type === 'public' && slot.activity) return slot.activity;
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

/** Same activityColor override as CalendarView.vue's own activityColorStyle — see blockColors.ts. */
function activityColorStyle(slot: DayBlock): Record<string, string> | undefined {
  return activityColorStyleFor(slot, resolvedTheme.value);
}

/** Same edge-fade blending as CalendarView.vue — see blockColors.ts's tentativeFadeStyle. */
function fadeStyle(day: Date, slots: DayBlock[], i: number): Record<string, string> {
  return tentativeFadeStyle(day, slots, i, props.events, props.timezone, resolvedTheme.value);
}

const agendaEntries = computed(() =>
  props.days.map(day => {
    const isToday = isDayToday(day);
    const slots = props.showBlocks
      ? getBlocksForDay(day, props.events, props.timezone)
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
          :style="{ ...slotHeightStyle(slot.heightPct), ...fadeStyle(day, slots, i), ...activityColorStyle(slot) }"
        >
          <div
            v-if="i === currentTimeSlotIndex"
            class="wtf-fagenda-current-time"
            :style="{ top: `${currentTimeOffsetPct}%` }"
          />
          <span class="wtf-fagenda-slot-time">{{ slotTimeText(slot) }}</span>
          <span class="wtf-fagenda-slot-label"><FontAwesomeIcon :icon="iconFor(slot)" class="wtf-fagenda-slot-icon me-1" />{{ slotLabel(slot) }}{{ isTentativeSuffixShown(slot) ? $t('free.tentativeSuffix') : '' }}</span>
        </div>
      </div>
    </div>
  </div>
</template>
