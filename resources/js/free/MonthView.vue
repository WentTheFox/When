<script setup lang="ts">
/**
 * Ported from WentTheNuxt's app/components/free/MonthView.vue — same
 * adaptations as CalendarView.vue/AgendaView.vue (see CalendarView.vue's
 * header comment). Clicking a week row emits weekClick with that week's
 * first non-past day — Free/Show.vue switches to the week view anchored
 * there, same as the source app.
 */
import { addDays, format, getDay, subDays } from 'date-fns';
import { enUS, hu } from 'date-fns/locale';
import { TZDate } from '@date-fns/tz';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { faSpinner } from '@fortawesome/free-solid-svg-icons';
import { computed } from 'vue';
import { currentLocale } from 'laravel-vue-i18n';
import { getBlocksForDay, isTentativeDisplay } from './nuxt-blocks';
import type { DayBlock, FreeSlot, HighlightedSlot, TentativeSlot } from './nuxt-blocks';

const AVAIL_BLOCK_CLASS: Record<DayBlock['type'], string> = {
  free: 'wtf-fmonth-avail-block-free',
  unavailable: 'wtf-fmonth-avail-block-unavail',
  highlighted: 'wtf-fmonth-avail-block-highlighted',
  sleep: 'wtf-fmonth-avail-block-sleep',
};

const AVAIL_BLOCK_COLOR_VAR: Record<DayBlock['type'], string> = {
  free: '--wtf-color-free',
  unavailable: '--wtf-color-busy',
  highlighted: '--wtf-color-highlighted',
  sleep: '--wtf-color-sleep',
};

const props = defineProps<{
  days: Date[];
  freeSlots: FreeSlot[];
  highlightedSlots: HighlightedSlot[];
  unavailableSlots: TentativeSlot[];
  sleepSlots: FreeSlot[];
  pending: boolean;
  hasError: boolean;
  hasAnyFreeTime: boolean;
  timezone: string;
  showBlocks: boolean;
  showCurrentTime: boolean;
  currentTimePct: number;
  /** 0=Sunday..6=Saturday, same convention as date-fns' weekStartsOn. */
  weekStart: number;
}>();

const emit = defineEmits<{
  weekClick: [day: Date];
}>();

const dateFnsLocale = computed(() => currentLocale.value === 'hu' ? hu : enUS);

// April 6 2025 is a Sunday, so `6 + weekStart + i` walks forward from
// whichever weekday weekStart names — no hardcoded Monday-first assumption.
const weekDayNames = computed(() => {
  const loc = { locale: dateFnsLocale.value };
  return Array.from({ length: 7 }, (_, i) => format(new Date(2025, 3, 6 + props.weekStart + i), 'EEE', loc));
});

const firstDayOffset = computed(() => {
  if (props.days.length === 0) return 0;
  const tzDay = new TZDate(props.days[0]!, props.timezone);
  const dow = getDay(tzDay);
  return (dow - props.weekStart + 7) % 7;
});

/**
 * How many trailing days are needed to fill out the last row to a full
 * week — same idea as firstDayOffset, mirrored for the end of the month.
 */
const lastDayOffset = computed(() => {
  if (props.days.length === 0) return 0;
  const tzDay = new TZDate(props.days.at(-1)!, props.timezone);
  const dow = getDay(tzDay);
  return (props.weekStart - dow - 1 + 7) % 7;
});

/**
 * The actual month's days, padded front and back with real adjacent-month
 * dates so every row is a full week — a lone day like "Aug 31" on its own
 * row, with the rest of that week blank, reads as broken; every other
 * month-grid calendar shows the adjacent month's days there (dimmed) to
 * fill it out instead. Data-wise this is safe: the availability API's own
 * computed range already extends well past either end of any single
 * viewed month (§5.1's LOOKAHEAD_DAYS), so blocks exist for these days
 * the same way tentativeFadeStyle below already reaches a day past the
 * rendered list.
 */
const paddedDays = computed(() => {
  if (props.days.length === 0) return [];

  const leading = Array.from({ length: firstDayOffset.value }, (_, i) =>
    subDays(props.days[0]!, firstDayOffset.value - i));
  const trailing = Array.from({ length: lastDayOffset.value }, (_, i) =>
    addDays(props.days.at(-1)!, i + 1));

  return [...leading, ...props.days, ...trailing];
});

function isDayToday(day: Date): boolean {
  const tzNow = new TZDate(new Date(), props.timezone);
  const tzDay = new TZDate(day, props.timezone);
  return (
    tzNow.getFullYear() === tzDay.getFullYear() &&
    tzNow.getMonth() === tzDay.getMonth() &&
    tzNow.getDate() === tzDay.getDate()
  );
}

function isDayPast(day: Date): boolean {
  const tzNow = new TZDate(new Date(), props.timezone);
  const tzDay = new TZDate(day, props.timezone);
  if (tzDay.getFullYear() !== tzNow.getFullYear()) return tzDay.getFullYear() < tzNow.getFullYear();
  if (tzDay.getMonth() !== tzNow.getMonth()) return tzDay.getMonth() < tzNow.getMonth();
  return tzDay.getDate() < tzNow.getDate();
}

function formatDay(day: Date, fmt: string): string {
  return format(new TZDate(day, props.timezone), fmt, { locale: dateFnsLocale.value });
}

type DayStatus = {
  day: Date;
  key: string;
  number: string;
  isToday: boolean;
  isPast: boolean;
  /** A leading/trailing padding day from the adjacent month, not part of the month actually being viewed — rendered dimmed. */
  isOutsideMonth: boolean;
  allBlocks: ReturnType<typeof getBlocksForDay>;
  currentBlockIndex: number;
  currentTimeOffsetPct: number;
};

const dayStatuses = computed(() => {
  const monthStart = props.days[0];
  const monthEnd = props.days.at(-1);

  return paddedDays.value.map((day): DayStatus => {
    const blocks = props.showBlocks
      ? getBlocksForDay(day, props.freeSlots, props.highlightedSlots, props.unavailableSlots, props.sleepSlots, props.timezone)
      : [];
    const isToday = isDayToday(day);
    let currentBlockIndex = -1;
    let currentTimeOffsetPct = 0;
    if (isToday && props.showCurrentTime) {
      const pct = props.currentTimePct;
      currentBlockIndex = blocks.findIndex(b => pct >= b.topPct && pct < b.topPct + b.heightPct);
      if (currentBlockIndex >= 0) {
        const b = blocks[currentBlockIndex]!;
        currentTimeOffsetPct = ((pct - b.topPct) / b.heightPct) * 100;
      }
    }
    return {
      day,
      key: formatDay(day, 'yyyy-MM-dd'),
      number: formatDay(day, 'd'),
      isToday,
      isPast: isDayPast(day),
      isOutsideMonth: !monthStart || !monthEnd || day < monthStart || day > monthEnd,
      allBlocks: blocks,
      currentBlockIndex,
      currentTimeOffsetPct,
    };
  });
});

// Same neighbor-blending idea as the week/agenda views: a tentative block's
// edge fade blends into the adjacent block's color instead of just fading to
// transparent. At the very top/bottom of a day's own blocks, it carries over
// from the previous/next calendar day's last/first block, computed directly
// rather than looked up in the rendered day list — even with paddedDays
// filling out both ends of the grid, a tentative block right at the very
// first/last rendered day still has no rendered neighbor to look up,
// falling back to transparent only where there's truly no data at all.
function tentativeFadeStyle(cell: DayStatus, i: number): Record<string, string> {
  const blocks = cell.allBlocks;
  if (!isTentativeDisplay(blocks[i]!)) return {};

  const style: Record<string, string> = {};
  const prev = i > 0
    ? blocks[i - 1]
    : getBlocksForDay(subDays(cell.day, 1), props.freeSlots, props.highlightedSlots, props.unavailableSlots, props.sleepSlots, props.timezone).at(-1);
  const next = i < blocks.length - 1
    ? blocks[i + 1]
    : getBlocksForDay(addDays(cell.day, 1), props.freeSlots, props.highlightedSlots, props.unavailableSlots, props.sleepSlots, props.timezone)[0];
  if (prev) style['--fade-start'] = `var(${AVAIL_BLOCK_COLOR_VAR[prev.type]})`;
  if (next) style['--fade-end'] = `var(${AVAIL_BLOCK_COLOR_VAR[next.type]})`;
  return style;
}

// dayStatuses is paddedDays run through the same per-day computation, so
// it's already a whole number of full weeks — no null padding needed to
// square off a trailing partial row anymore.
const weekRows = computed(() => {
  const rows: { cells: DayStatus[]; firstActiveDay: Date }[] = [];
  let cells: DayStatus[] = [];

  for (const status of dayStatuses.value) {
    cells.push(status);
    if (cells.length === 7) {
      // A row with no active (non-past) day is entirely past — skip it,
      // otherwise the calendar renders a blank row with no visible dates.
      const firstActiveDay = cells.find(c => !c.isPast)?.day ?? null;
      if (firstActiveDay) rows.push({ cells, firstActiveDay });
      cells = [];
    }
  }
  return rows;
});
</script>

<template>
  <div class="wtf-fmonth-wrap">
    <div v-if="pending" class="wtf-fmonth-loading-overlay">
      <FontAwesomeIcon :icon="faSpinner" spin size="2x" />
    </div>
    <div v-else-if="hasError" class="wtf-fmonth-error-state">
      {{ $t('free.error') }}
    </div>
    <template v-else>
      <div class="wtf-fmonth-grid">
        <div class="wtf-fmonth-week-header-row">
          <div
            v-for="name in weekDayNames"
            :key="name"
            class="wtf-fmonth-weekday-header"
          >
            {{ name }}
          </div>
        </div>

        <div
          v-for="week in weekRows"
          :key="week.firstActiveDay.toISOString()"
          class="wtf-fmonth-week-row"
          role="button"
          @click="emit('weekClick', week.firstActiveDay)"
        >
          <template v-for="cell in week.cells" :key="cell.key">
            <div v-if="cell.isPast" class="wtf-fmonth-day-cell-empty" />
            <div
              v-else
              class="wtf-fmonth-day-cell"
              :class="{ 'is-today': cell.isToday, 'wtf-fmonth-day-cell-outside': cell.isOutsideMonth }"
            >
              <span class="wtf-fmonth-day-number" :class="{ 'wtf-fmonth-day-number-today': cell.isToday }">
                {{ cell.number }}
              </span>

              <div v-if="cell.allBlocks.length > 0" class="wtf-fmonth-avail-bar">
                <div
                  v-for="(block, i) in cell.allBlocks"
                  :key="i"
                  class="wtf-fmonth-avail-block"
                  :class="[AVAIL_BLOCK_CLASS[block.type], { 'wtf-fmonth-avail-block-tentative': isTentativeDisplay(block) }]"
                  :style="{ '--flex': block.heightPct, ...tentativeFadeStyle(cell, i) }"
                >
                  <div
                    v-if="i === cell.currentBlockIndex"
                    class="wtf-fmonth-current-time"
                    :style="{ top: `${cell.currentTimeOffsetPct}%` }"
                  />
                  <template v-if="block.type === 'free'">{{ block.startTime }}–{{ block.endTime }}</template>
                </div>
              </div>
            </div>
          </template>
        </div>
      </div>

      <p v-if="!hasAnyFreeTime && showBlocks" class="wtf-fmonth-no-data">
        {{ $t('free.noData') }}
      </p>
    </template>
  </div>
</template>
