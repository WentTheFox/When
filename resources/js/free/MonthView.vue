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
  allBlocks: ReturnType<typeof getBlocksForDay>;
  currentBlockIndex: number;
  currentTimeOffsetPct: number;
};

const dayStatuses = computed(() =>
  props.days.map((day): DayStatus => {
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
      allBlocks: blocks,
      currentBlockIndex,
      currentTimeOffsetPct,
    };
  }),
);

// Same neighbor-blending idea as the week/agenda views: a tentative block's
// edge fade blends into the adjacent block's color instead of just fading to
// transparent. At the very top/bottom of a day's own blocks, it carries over
// from the previous/next calendar day's last/first block, computed directly
// rather than looked up in the rendered day list — the visible month grid
// excludes the previous/next month's days entirely while the API still
// returns data for the day just outside the requested range — falling back
// to transparent only where there's truly no data for the adjacent day.
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

const weekRows = computed(() => {
  const rows: { cells: (DayStatus | null)[]; firstActiveDay: Date }[] = [];
  let cells: (DayStatus | null)[] = Array(firstDayOffset.value).fill(null);

  const pushRow = (): void => {
    const firstActiveDay = cells.find(c => c !== null && !c.isPast)?.day ?? null;
    // A row with no active day (everything past or padding) is empty — skip it,
    // otherwise the calendar renders a blank row with no visible dates.
    if (firstActiveDay) rows.push({ cells, firstActiveDay });
  };

  for (const status of dayStatuses.value) {
    cells.push(status);
    if (cells.length === 7) {
      pushRow();
      cells = [];
    }
  }
  if (cells.length > 0) {
    while (cells.length < 7) cells.push(null);
    pushRow();
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
          <template v-for="(cell, ci) in week.cells" :key="cell?.key ?? `empty-${ci}`">
            <div v-if="!cell || cell.isPast" class="wtf-fmonth-day-cell-empty" />
            <div
              v-else
              class="wtf-fmonth-day-cell"
              :class="{ 'is-today': cell.isToday }"
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
