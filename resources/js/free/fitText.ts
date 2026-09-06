import type { Directive } from 'vue';

/**
 * v-fit-text: shrinks an element's own font-size, in place, just enough to
 * stop its content from vertically overflowing — used on calendar block
 * labels (CalendarView.vue) instead of guessing a shrink threshold off the
 * block's duration. Duration was a proxy for "will the title fit" at best:
 * a short block with a short title never needed shrinking, and a long
 * title could still overflow a block plenty of duration-based heuristics
 * would've left untouched. Measuring the actual content is exact regardless
 * of why the label doesn't fit (short block, long title, or both).
 *
 * Resizes off the label's own parent, not the label itself — observing the
 * label would refire the moment fit() changes its font-size (the label's
 * box shrinks along with the text), which is exactly the kind of
 * self-triggering loop ResizeObserver is built to warn about. The parent
 * block's own box is sized by inline top/height percentages set elsewhere
 * (CalendarView.vue), never by its label's content, so watching it is safe.
 */

const MIN_FONT_SIZE_PX = 9;
const SHRINK_STEP = 0.92;
const MAX_ITERATIONS = 14;
const OVERFLOW_TOLERANCE_PX = 1;

function fit(el: HTMLElement): void {
  // Always start from the CSS-defined base size, not whatever a previous
  // fit() left behind — the available space may have grown since (a
  // container resize, a shorter title after a locale switch), and this is
  // the only way to detect that and grow back.
  el.style.fontSize = '';
  const baseFontSize = parseFloat(getComputedStyle(el).fontSize);
  if (!Number.isFinite(baseFontSize) || baseFontSize <= 0) {
    return;
  }

  let fontSize = baseFontSize;
  let iterations = 0;
  while (
    el.scrollHeight > el.clientHeight + OVERFLOW_TOLERANCE_PX
    && fontSize > MIN_FONT_SIZE_PX
    && iterations < MAX_ITERATIONS
  ) {
    fontSize = Math.max(MIN_FONT_SIZE_PX, fontSize * SHRINK_STEP);
    el.style.fontSize = `${fontSize}px`;
    iterations += 1;
  }
}

interface FitTextElement extends HTMLElement {
  __fitTextObserver?: ResizeObserver;
}

export const vFitText: Directive<FitTextElement> = {
  mounted(el) {
    fit(el);
    const target = el.parentElement ?? el;
    const observer = new ResizeObserver(() => fit(el));
    observer.observe(target);
    el.__fitTextObserver = observer;
  },
  updated(el) {
    fit(el);
  },
  beforeUnmount(el) {
    el.__fitTextObserver?.disconnect();
    delete el.__fitTextObserver;
  },
};
