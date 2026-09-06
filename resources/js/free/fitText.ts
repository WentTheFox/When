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
 * Tries a fixed ladder of steps off the CSS base size rather than
 * compounding a percentage shrink every iteration — a compounding shrink
 * overshoots past the first size that would've actually fit, and produces
 * a different final size depending on how many steps it took to get there
 * instead of a small, predictable set of sizes. Never goes below 0.70 of
 * the base size even if that still clips — a smaller floor (0.5rem was
 * tried) read as illegible in practice.
 *
 * Resizes off the label's own parent, not the label itself — observing the
 * label would refire the moment fit() changes its font-size (the label's
 * box shrinks along with the text), which is exactly the kind of
 * self-triggering loop ResizeObserver is built to warn about. The parent
 * block's own box is sized by inline top/height percentages set elsewhere
 * (CalendarView.vue), never by its label's content, so watching it is safe.
 */

const SCALE_STEPS = [1, 0.95, 0.9, 0.85, 0.8, 0.75, 0.7];
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

  for (const step of SCALE_STEPS) {
    if (step !== 1) {
      el.style.fontSize = `${baseFontSize * step}px`;
    }
    if (el.scrollHeight <= el.clientHeight + OVERFLOW_TOLERANCE_PX) {
      return;
    }
  }
  // None of the steps fit — leave it at the smallest one (already applied
  // as the last iteration above) rather than shrinking further.
}

interface FitTextElement extends HTMLElement {
  __fitTextObserver?: ResizeObserver;
}

export const vFitText: Directive<FitTextElement> = {
  mounted(el) {
    fit(el);
    // Self-hosted webfont (config/google-fonts.php): on first render its
    // file may not have finished loading yet, so this initial fit() can
    // measure against the fallback font's metrics — usually wider/taller —
    // and shrink text that the real font would've fit at full size. Once
    // it loads, every already-laid-out element reflows on its own, but
    // nothing re-runs fit() to notice a shrink is no longer needed; without
    // this, that wrong shrink is permanent for the rest of the page's life.
    document.fonts?.ready?.then(() => fit(el)).catch(() => {});

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
