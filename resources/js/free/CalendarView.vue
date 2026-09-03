<script setup lang="ts">
/**
 * Ported from WentTheNuxt's app/components/free/CalendarView.vue as closely
 * as the two apps' stacks allow — same layout, same block-splitting
 * algorithm (nuxt-blocks.ts, itself a verbatim port), same visual language.
 * What had to change, and why:
 *   - useI18n()'s {t, locale} -> laravel-vue-i18n's trans()/currentLocale
 *     (this app uses the Laravel-family i18n package, not raw vue-i18n).
 *   - CSS modules (*.module.scss, Bootstrap 5 SCSS vars) -> plain CSS
 *     classes in resources/css/dark-theme.css using this app's own
 *     --wtf-color-* custom properties (this app is Bootstrap 4 + a manual
 *     dark-theme overlay, not Sass).
 *   - <FontAwesome> (Nuxt global component) -> local FontAwesomeIcon import.
 *   - <CutieMarkPlayer> (a WentTheNuxt-specific mascot asset) -> a plain
 *     spinning FontAwesome icon.
 * The template structure, class names (wtf-fcal-* here vs the source's CSS
 * module names), and all rendering logic are otherwise unchanged.
 */
import { addDays, format, subDays } from 'date-fns';
import { enUS, hu } from 'date-fns/locale';
import { TZDate } from '@date-fns/tz';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { faSpinner } from '@fortawesome/free-solid-svg-icons';
import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import { computed } from 'vue';
import { currentLocale, trans } from 'laravel-vue-i18n';
import { formatFromTime, formatReservedDuration, formatTentativeStart, formatUntilTime, getBlocksForDay, isTentativeEndDisplay, isTentativeStartDisplay, isTentativeSuffixShown, tildeTime } from './nuxt-blocks';
import type { DayBlock, FreeSlot, HighlightedSlot, TentativeSlot } from './nuxt-blocks';

const BLOCK_TYPE_CLASS: Record<DayBlock['type'], string> = {
  free: 'wtf-fcal-free-block',
  unavailable: 'wtf-fcal-unavailable-block',
  highlighted: 'wtf-fcal-highlighted-block',
  work: 'wtf-fcal-work-block',
  school: 'wtf-fcal-school-block',
  sleep: 'wtf-fcal-sleep-block',
};

const BLOCK_TYPE_LABEL_KEY: Record<DayBlock['type'], string> = {
  free: 'free.freeLabel',
  unavailable: 'free.unavailableLabel',
  highlighted: 'free.highlightedLabel',
  work: 'free.workLabel',
  school: 'free.schoolLabel',
  sleep: 'free.sleepLabel',
};

const BLOCK_TYPE_COLOR_VAR: Record<DayBlock['type'], string> = {
  free: '--wtf-color-free',
  unavailable: '--wtf-color-busy',
  highlighted: '--wtf-color-highlighted',
  work: '--wtf-color-work',
  school: '--wtf-color-school',
  sleep: '--wtf-color-sleep',
};

const props = defineProps<{
  visibleDays: Date[];
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
  hasAnyFreeTime: boolean;
  timezone: string;
  showBlocks: boolean;
  showCurrentTime: boolean;
  currentTimePct: number;
}>();

// DayBlock's own type names ('unavailable') don't quite match the
// icons prop's slot names ('busy') — see nuxt-blocks.ts vs
// icon-palette.ts's IconSlot for why (unavailable/busy naming has
// always been split between the wire shape and the owner-facing slot).
const blockTypeIcon = computed<Record<DayBlock['type'], IconDefinition>>(() => ({
  free: props.icons.free,
  unavailable: props.icons.busy,
  highlighted: props.icons.highlighted,
  work: props.icons.work,
  school: props.icons.school,
  sleep: props.icons.sleep,
}));

const dateFnsLocale = computed(() => currentLocale.value === 'hu' ? hu : enUS);

function blockLabel(block: DayBlock): string {
  if (block.type === 'highlighted' && block.activity) return block.activity;
  return trans(BLOCK_TYPE_LABEL_KEY[block.type]);
}

function blockTimeText(block: DayBlock): string {
  if (block.type !== 'free' && block.type !== 'highlighted') return '';

  const startFuzzy = isTentativeStartDisplay(block);
  const endFuzzy = isTentativeEndDisplay(block);

  // A single fuzzy edge collapses to just its known side + a reserved
  // duration ("From 17:00 (2h reserved)" / "Until 19:00 (2h reserved)")
  // rather than an explicit range — there's no point printing a clock time
  // for the edge we don't actually know.
  if (block.type === 'highlighted' && (startFuzzy || endFuzzy)) {
    const duration = trans('free.reservedSuffix', { duration: formatReservedDuration(block.startTime, block.endTime, currentLocale.value) });
    if (startFuzzy && endFuzzy) return ` ${formatTentativeStart(block.startTime, currentLocale.value)} (${duration})`;
    if (startFuzzy) return ` ${formatUntilTime(block.endTime, currentLocale.value)} (${duration})`;
    return ` ${formatFromTime(block.startTime, currentLocale.value)} (${duration})`;
  }

  return ` ${tildeTime(block.startTime, startFuzzy)} – ${tildeTime(block.endTime, endFuzzy)}`;
}

const dayBlocks = computed(() =>
  props.visibleDays.map(day => ({
    day,
    blocks: props.showBlocks
      ? getBlocksForDay(day, props.freeSlots, props.highlightedSlots, props.unavailableSlots, props.sleepSlots, props.timezone, props.workSlots, props.schoolSlots)
      : [],
  })),
);

// Blocks tile the day with no gaps, so the previous/next array entry is the
// immediately-adjacent block in time. Only an edge that's actually fuzzy
// (tentativeStart/tentativeEnd, independently) gets a gradient at all — the
// other edge renders as a hard line at its own solid color. A fuzzy edge's
// gradient blends into that neighbor's color via a CSS var. For a run of
// consecutive tentative blocks, each block's bottom edge still fades toward
// the next block's color — but the block below never fades in at its own
// top when its predecessor's bottom edge is also fuzzy, so a shared seam
// only ever fades once (attributed to the block above), not twice meeting
// in the middle. That was a previous bug: both sides independently faded
// toward each other's nominal color, producing a mismatched double-fade
// "pinch" at every internal boundary instead of one continuous cascade
// down the run.
//
// At the very top/bottom of a day's own blocks, the neighbor carries over
// from the previous/next calendar day's last/first block, computed
// directly rather than looked up in the rendered day list — the visible
// range can trim a day (e.g. past-day filtering on the current week)
// while the API still returns that day's data, padded a day either side
// of the requested range — falling back to transparent only where there's
// truly no data for the adjacent day (a hard, non-fuzzy edge never falls
// back to transparent, since it always renders its own solid color).
function tentativeFadeStyle(day: Date, blocks: DayBlock[], i: number): Record<string, string> {
  const block = blocks[i]!;
  const startFuzzy = isTentativeStartDisplay(block);
  const endFuzzy = isTentativeEndDisplay(block);
  if (!startFuzzy && !endFuzzy) return {};

  const style: Record<string, string> = {};

  if (startFuzzy) {
    const prev = i > 0
      ? blocks[i - 1]
      : getBlocksForDay(subDays(day, 1), props.freeSlots, props.highlightedSlots, props.unavailableSlots, props.sleepSlots, props.timezone, props.workSlots, props.schoolSlots).at(-1);
    if (prev) {
      style['--fade-start'] = isTentativeEndDisplay(prev)
        ? `var(${BLOCK_TYPE_COLOR_VAR[block.type]})`
        : `var(${BLOCK_TYPE_COLOR_VAR[prev.type]})`;
    }
  } else {
    style['--fade-start'] = `var(${BLOCK_TYPE_COLOR_VAR[block.type]})`;
  }

  if (endFuzzy) {
    const next = i < blocks.length - 1
      ? blocks[i + 1]
      : getBlocksForDay(addDays(day, 1), props.freeSlots, props.highlightedSlots, props.unavailableSlots, props.sleepSlots, props.timezone, props.workSlots, props.schoolSlots)[0];
    if (next) style['--fade-end'] = `var(${BLOCK_TYPE_COLOR_VAR[next.type]})`;
  } else {
    style['--fade-end'] = `var(${BLOCK_TYPE_COLOR_VAR[block.type]})`;
  }

  return style;
}

const HOURS = Array.from({ length: 24 }, (_, i) => i);

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
</script>

<template>
  <div class="wtf-fcal-wrap">
    <div class="wtf-fcal">
      <div class="wtf-fcal-header">
        <div class="wtf-fcal-time-gutter-header" />
        <div class="wtf-fcal-days-header" :style="{ gridTemplateColumns: `repeat(${visibleDays.length}, 1fr)` }">
          <div
            v-for="day in visibleDays"
            :key="formatDay(day, 'yyyy-MM-dd')"
            class="wtf-fcal-day-header"
            :class="{ 'is-today': isDayToday(day) }"
          >
            <div class="wtf-fcal-day-name">{{ formatDay(day, 'EEE') }}</div>
            <div class="wtf-fcal-day-date">{{ formatDay(day, 'MMM d') }}</div>
          </div>
        </div>
      </div>

      <div class="wtf-fcal-body">
        <div class="wtf-fcal-time-gutter">
          <div
            v-for="hour in HOURS"
            :key="hour"
            class="wtf-fcal-hour-label"
            :style="{ top: `${(hour / 24) * 100}%` }"
          >
            {{ String(hour).padStart(2, '0') }}:00
          </div>
        </div>

        <div v-if="pending" class="wtf-fcal-loading-overlay">
          <span
            class="spinner-border spinner-border-lg"
            role="status"
            aria-hidden="true"
          />
        </div>

        <div v-else-if="hasError" class="wtf-fcal-error-state">
          {{ $t('free.error') }}
        </div>

        <div v-else class="wtf-fcal-days-grid" :style="{ gridTemplateColumns: `repeat(${visibleDays.length}, 1fr)` }">
          <div
            v-for="{ day, blocks } in dayBlocks"
            :key="formatDay(day, 'yyyy-MM-dd')"
            class="wtf-fcal-day-column"
            :class="{ 'is-today': isDayToday(day) }"
          >
            <div
              v-if="showCurrentTime && isDayToday(day)"
              class="wtf-fcal-current-time"
              :style="{ top: `${currentTimePct}%` }"
            />

            <template v-if="showBlocks">
              <div
                v-for="(block, i) in blocks"
                :key="i"
                class="wtf-fcal-block"
                :class="[BLOCK_TYPE_CLASS[block.type], { 'wtf-fcal-tentative-block': isTentativeStartDisplay(block) || isTentativeEndDisplay(block) }]"
                :style="{ top: `${block.topPct}%`, height: `${block.heightPct}%`, ...tentativeFadeStyle(day, blocks, i) }"
              >
                <span class="wtf-fcal-block-label">
                  <strong><FontAwesomeIcon :icon="blockTypeIcon[block.type]" class="wtf-fcal-block-label-icon me-1" />{{ blockLabel(block) }}{{ isTentativeSuffixShown(block) ? $t('free.tentativeSuffix') : '' }}</strong><span class="wtf-fcal-block-label-time">{{ blockTimeText(block) }}</span>
                </span>
              </div>
            </template>
          </div>

          <div v-if="!hasAnyFreeTime" class="wtf-fcal-no-data">
            {{ $t('free.noDataWeek') }}
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
