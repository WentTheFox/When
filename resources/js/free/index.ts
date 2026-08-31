import {
  decryptString,
  DecryptionFailedError,
  deriveLegacyShareLinkKey,
  importKeyFromFragment,
  unwrapKeyWithPassphrase,
} from '../crypto';
import { getBlocksForDay, type AvailabilitySlot, type DayBlock } from './blocks';

interface ApiResponse {
  status: 'pending' | 'ready';
  ciphertext?: string;
  key_protection: 'fragment' | 'passphrase';
  wrapped_key?: string | null;
  wrap_salt?: string | null;
  computed_range_start?: string;
  computed_range_end?: string;
  stale?: boolean;
  timezone: string;
}

class LinkExpiredError extends Error {}

const root = document.getElementById('free-page')!;
const token = root.dataset.token!;
const keyProtection = root.dataset.keyProtection as 'fragment' | 'passphrase';

const mainEl = document.getElementById('free-main')!;
const expiredEl = document.getElementById('free-expired')!;
const statusEl = document.getElementById('free-status')!;
const statusTextEl = document.getElementById('free-status-text')!;
const calendarRoot = document.getElementById('free-calendar-root')!;
const agendaRoot = document.getElementById('free-agenda-root')!;
const navLabelEl = document.getElementById('nav-label')!;
const btnPrev = document.getElementById('btn-prev') as HTMLButtonElement;
const btnNext = document.getElementById('btn-next') as HTMLButtonElement;
const btnToday = document.getElementById('btn-today') as HTMLButtonElement;
const btnViewMonth = document.getElementById('btn-view-month') as HTMLButtonElement;
const btnViewWeek = document.getElementById('btn-view-week') as HTMLButtonElement;
const passphraseModal = document.getElementById('passphrase-modal')!;
const passphraseForm = document.getElementById('passphrase-form') as HTMLFormElement;
const passphraseInput = document.getElementById('passphrase-input') as HTMLInputElement;
const passphraseError = document.getElementById('passphrase-error')!;
const timezoneOffsetNoteEl = document.getElementById('timezone-offset-note')!;
const btnThemeToggle = document.getElementById('btn-theme-toggle') as HTMLButtonElement;
const themeToggleIcon = document.getElementById('theme-toggle-icon')!;

let slots: AvailabilitySlot[] = [];
// Default is week — see PLAN.md Stage 6.
let viewMode: 'week' | 'month' = (new URLSearchParams(location.search).get('view') === 'month') ? 'month' : 'week';
let anchorDate = parseAtParam() ?? new Date();

function parseAtParam(): Date | null {
  const at = new URLSearchParams(location.search).get('at');
  if (!at || !/^\d{4}-\d{2}-\d{2}$/.test(at)) return null;
  const [y, m, d] = at.split('-').map(Number);
  return new Date(y, m - 1, d);
}

function updateUrl(): void {
  const params = new URLSearchParams(location.search);
  params.set('view', viewMode);
  params.set('at', formatDateParam(anchorDate));
  history.replaceState(null, '', `${location.pathname}?${params.toString()}${location.hash}`);
}

function formatDateParam(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function startOfWeek(d: Date): Date {
  const r = new Date(d);
  const day = (r.getDay() + 6) % 7; // Monday = 0
  r.setDate(r.getDate() - day);
  r.setHours(0, 0, 0, 0);
  return r;
}

function startOfMonth(d: Date): Date {
  return new Date(d.getFullYear(), d.getMonth(), 1);
}

function addDays(d: Date, n: number): Date {
  const r = new Date(d);
  r.setDate(r.getDate() + n);
  return r;
}

function isSameDay(a: Date, b: Date): boolean {
  return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}

// ── Theme toggle ─────────────────────────────────────────────────────────

const THEME_STORAGE_KEY = 'wtf-theme';

function applyTheme(theme: 'dark' | 'light'): void {
  document.documentElement.setAttribute('data-theme', theme);
  themeToggleIcon.textContent = theme === 'dark' ? '☉' : '☽'; // sun / crescent moon
}

function initTheme(): void {
  let stored: string | null = null;
  try {
    stored = localStorage.getItem(THEME_STORAGE_KEY);
  } catch {
    // Private browsing / storage blocked — fall back to the default silently.
  }
  applyTheme(stored === 'light' ? 'light' : 'dark');
}

btnThemeToggle.addEventListener('click', () => {
  const current = document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
  const next = current === 'light' ? 'dark' : 'light';
  applyTheme(next);
  try {
    localStorage.setItem(THEME_STORAGE_KEY, next);
  } catch {
    // Ignore — the toggle still works for this page load either way.
  }
});

initTheme();

// ── Timezone comparison ─────────────────────────────────────────────────

function renderTimezoneOffsetNote(ownerTimezone: string): void {
  const viewerTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

  const now = new Date();
  const viewerOffset = -getTimezoneOffsetMinutes(now, viewerTimezone);
  const ownerOffset = -getTimezoneOffsetMinutes(now, ownerTimezone);
  const diffMinutes = viewerOffset - ownerOffset;

  timezoneOffsetNoteEl.hidden = false;

  if (diffMinutes === 0) {
    timezoneOffsetNoteEl.textContent = 'Our timezones match!';
    return;
  }

  const abs = Math.abs(diffMinutes);
  const hours = Math.floor(abs / 60);
  const minutes = abs % 60;
  const offsetText = `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
  timezoneOffsetNoteEl.textContent = diffMinutes > 0
    ? `You are ${offsetText} ahead compared to them`
    : `You are ${offsetText} behind compared to them`;
}

function getTimezoneOffsetMinutes(date: Date, timeZone: string): number {
  // The difference between UTC and the wall-clock time Intl reports for
  // this timezone, in minutes — same trick as Intl.DateTimeFormat has no
  // direct "give me the offset" API.
  const dtf = new Intl.DateTimeFormat('en-US', {
    timeZone,
    hourCycle: 'h23',
    year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', second: '2-digit',
  });
  const parts = Object.fromEntries(dtf.formatToParts(date).map((p) => [p.type, p.value]));
  const asUtc = Date.UTC(
    Number(parts.year), Number(parts.month) - 1, Number(parts.day),
    Number(parts.hour), Number(parts.minute), Number(parts.second),
  );
  return (asUtc - date.getTime()) / 60000;
}

// ── Scrambled placeholder (Stage 6's core visible trust signal) ────────────

const SCRAMBLE_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=';

function randomScrambledText(length: number): string {
  let out = '';
  for (let i = 0; i < length; i++) {
    out += SCRAMBLE_CHARS[Math.floor(Math.random() * SCRAMBLE_CHARS.length)];
  }
  return out;
}

function buildScrambledBlock(): HTMLElement {
  const wrap = document.createElement('div');
  wrap.className = 'border rounded p-3';
  wrap.style.filter = 'blur(0.5px)';
  wrap.setAttribute('aria-hidden', 'true');

  for (let i = 0; i < 7; i++) {
    const row = document.createElement('div');
    row.className = 'mb-2 text-monospace small text-muted';
    row.style.opacity = '0.5';
    row.textContent = randomScrambledText(40 + Math.floor(Math.random() * 30));
    wrap.appendChild(row);
  }

  return wrap;
}

// Populates both roots — whichever CSS shows for the current viewport
// width (.wtf-desktop-only / .wtf-mobile-only, dark-theme.css) is the one
// the viewer actually sees.
function renderScrambledPlaceholder(): void {
  calendarRoot.innerHTML = '';
  calendarRoot.hidden = false;
  calendarRoot.appendChild(buildScrambledBlock());

  agendaRoot.innerHTML = '';
  agendaRoot.hidden = false;
  agendaRoot.appendChild(buildScrambledBlock());

  statusEl.hidden = true;
}

// ── Decryption ───────────────────────────────────────────────────────────

async function resolveContentKey(response: ApiResponse): Promise<CryptoKey> {
  const fragment = location.hash.startsWith('#') ? location.hash.slice(1) : '';

  if (fragment.startsWith('k=')) {
    return importKeyFromFragment(fragment);
  }

  if (keyProtection === 'passphrase') {
    return promptForPassphraseKey(response);
  }

  // No fragment, not passphrase-protected: a legacy migrated link (§0.5) —
  // the key derives deterministically from the token itself.
  return deriveLegacyShareLinkKey(token);
}

function promptForPassphraseKey(response: ApiResponse): Promise<CryptoKey> {
  return new Promise((resolve) => {
    passphraseModal.hidden = false;
    passphraseInput.focus();

    passphraseForm.addEventListener('submit', async function handler(event) {
      event.preventDefault();
      passphraseError.hidden = true;

      try {
        const key = await unwrapKeyWithPassphrase(
          { wrappedKey: response.wrapped_key!, salt: response.wrap_salt! },
          passphraseInput.value,
        );
        passphraseModal.hidden = true;
        passphraseForm.removeEventListener('submit', handler);
        resolve(key);
      } catch (error) {
        if (error instanceof DecryptionFailedError) {
          passphraseError.textContent = 'Wrong passphrase. Please try again.';
        } else {
          passphraseError.textContent = 'Something went wrong. Please try again.';
        }
        passphraseError.hidden = false;
      }
    });
  });
}

async function fetchWithPolling(): Promise<ApiResponse> {
  for (;;) {
    const res = await fetch(`/api/share/${encodeURIComponent(token)}`, {
      headers: { Accept: 'application/json' },
    });

    if (res.status === 401) {
      throw new LinkExpiredError();
    }

    if (!res.ok) {
      throw new Error(`Request failed: ${res.status}`);
    }

    const data: ApiResponse = await res.json();

    if (data.status === 'ready') {
      return data;
    }

    statusTextEl.textContent = "Your friend's calendar is being fetched for the first time — this can take a moment…";
    await new Promise((r) => setTimeout(r, 2000));
  }
}

// ── Rendering ────────────────────────────────────────────────────────────

const BLOCK_LABELS: Record<DayBlock['type'], string> = {
  free: 'Free',
  busy: 'Unavailable',
  highlighted: 'Plans with you',
  sleep: 'Sleep',
  tentative: 'Tentative',
};

function blockLabel(block: DayBlock): string {
  if (block.type === 'highlighted' && block.highlightWord) return `Plans with ${block.highlightWord}`;
  return BLOCK_LABELS[block.type];
}

function renderWeek(days: Date[]): void {
  calendarRoot.innerHTML = '';

  const table = document.createElement('div');
  table.className = 'wtf-calendar border rounded overflow-hidden';
  table.style.display = 'grid';
  table.style.gridTemplateColumns = `3.5rem repeat(${days.length}, 1fr)`;
  table.style.overflowX = 'auto';

  // Header row.
  table.appendChild(document.createElement('div'));
  for (const day of days) {
    const header = document.createElement('div');
    header.className = 'text-center small font-weight-bold py-2 border-bottom border-left';
    if (isSameDay(day, new Date())) header.classList.add('text-warning');
    header.innerHTML = `${day.toLocaleDateString(undefined, { weekday: 'short' })}<br>${day.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}`;
    table.appendChild(header);
  }

  const hourHeightRem = 2.25;

  // Hour gutter.
  const gutter = document.createElement('div');
  gutter.style.position = 'relative';
  gutter.style.height = `${24 * hourHeightRem}rem`;
  for (let h = 0; h < 24; h++) {
    const label = document.createElement('div');
    label.className = 'small text-muted';
    label.style.position = 'absolute';
    label.style.top = `${(h / 24) * 100}%`;
    label.style.right = '0.25rem';
    label.style.transform = 'translateY(-50%)';
    label.style.fontSize = '0.65rem';
    label.textContent = `${String(h).padStart(2, '0')}:00`;
    gutter.appendChild(label);
  }
  table.appendChild(gutter);

  for (const day of days) {
    const column = document.createElement('div');
    column.className = 'border-left';
    column.style.position = 'relative';
    column.style.height = `${24 * hourHeightRem}rem`;
    column.style.backgroundImage = 'repeating-linear-gradient(to bottom, transparent 0, transparent calc(2.25rem - 1px), var(--wtf-border) calc(2.25rem - 1px), var(--wtf-border) 2.25rem)';

    if (isSameDay(day, new Date())) {
      column.style.backgroundColor = 'rgba(232, 133, 58, 0.06)';

      const nowLine = document.createElement('div');
      nowLine.style.position = 'absolute';
      nowLine.style.left = '0';
      nowLine.style.right = '0';
      nowLine.style.height = '2px';
      nowLine.style.backgroundColor = '#e5566a';
      const now = new Date();
      const pct = ((now.getHours() * 60 + now.getMinutes()) / 1440) * 100;
      nowLine.style.top = `${pct}%`;
      column.appendChild(nowLine);
    }

    for (const block of getBlocksForDay(day, slots)) {
      if (block.type === 'free') continue; // free is the implicit background, not drawn

      const el = document.createElement('div');
      el.style.position = 'absolute';
      el.style.left = '0';
      el.style.right = '0';
      el.style.top = `${block.topPct}%`;
      el.style.height = `${Math.max(block.heightPct, 0.5)}%`;
      el.style.overflow = 'hidden';
      el.style.padding = '2px 6px';
      el.style.fontSize = '0.8rem';
      el.style.backgroundColor = `var(--wtf-color-${block.type})`;

      const label = document.createElement('strong');
      label.textContent = blockLabel(block);
      el.appendChild(label);

      if (block.type !== 'sleep') {
        const time = document.createElement('span');
        time.className = 'd-block small';
        time.textContent = `${block.startTime} – ${block.endTime}`;
        el.appendChild(time);
      }

      column.appendChild(el);
    }

    table.appendChild(column);
  }

  calendarRoot.appendChild(table);
}

function renderMonth(monthStart: Date): void {
  calendarRoot.innerHTML = '';

  const grid = document.createElement('div');
  grid.style.display = 'grid';
  grid.style.gridTemplateColumns = 'repeat(7, 1fr)';
  grid.style.gap = '2px';

  const firstOfMonth = startOfMonth(monthStart);
  const gridStart = startOfWeek(firstOfMonth);

  for (let i = 0; i < 42; i++) {
    const day = addDays(gridStart, i);
    const inMonth = day.getMonth() === firstOfMonth.getMonth();

    const cell = document.createElement('button');
    cell.type = 'button';
    cell.className = 'btn btn-outline-secondary text-left';
    cell.style.opacity = inMonth ? '1' : '0.35';
    cell.style.minHeight = '4rem';
    if (isSameDay(day, new Date())) cell.classList.add('border-warning');

    const dateLabel = document.createElement('div');
    dateLabel.className = 'small font-weight-bold';
    dateLabel.textContent = String(day.getDate());
    cell.appendChild(dateLabel);

    const blocks = getBlocksForDay(day, slots).filter((b) => b.type !== 'free');
    const types = new Set(blocks.map((b) => b.type));
    if (types.size > 0) {
      const dots = document.createElement('div');
      dots.className = 'd-flex mt-1';
      dots.style.gap = '3px';
      for (const type of types) {
        const dot = document.createElement('span');
        dot.style.width = '8px';
        dot.style.height = '8px';
        dot.style.borderRadius = '50%';
        dot.style.display = 'inline-block';
        dot.style.backgroundColor = `var(--wtf-color-${type})`;
        dots.appendChild(dot);
      }
      cell.appendChild(dots);
    }

    cell.addEventListener('click', () => {
      anchorDate = day;
      viewMode = 'week';
      render();
    });

    grid.appendChild(cell);
  }

  calendarRoot.appendChild(grid);
}

// The 24-hour grid doesn't work on narrow screens — .wtf-mobile-only /
// .wtf-desktop-only (dark-theme.css) show exactly one of these at a time
// per viewport width, but both render the same underlying day set so
// there's no layout jump when the breakpoint is crossed mid-session.
function renderAgenda(days: Date[]): void {
  agendaRoot.innerHTML = '';

  for (const day of days) {
    const dayEl = document.createElement('div');
    dayEl.className = 'wtf-agenda-day';

    const header = document.createElement('div');
    header.className = 'wtf-agenda-day-header';
    if (isSameDay(day, new Date())) header.classList.add('is-today');
    header.textContent = `${day.toLocaleDateString(undefined, { weekday: 'short' })} ${day.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}`;
    dayEl.appendChild(header);

    const blocks = getBlocksForDay(day, slots).filter((b) => b.type !== 'free');

    if (blocks.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'small text-muted';
      empty.textContent = 'Free all day';
      dayEl.appendChild(empty);
    }

    for (const block of blocks) {
      const slotEl = document.createElement('div');
      slotEl.className = 'wtf-agenda-slot';
      slotEl.style.backgroundColor = `var(--wtf-color-${block.type})`;

      const label = document.createElement('div');
      label.className = 'font-weight-bold small';
      label.textContent = blockLabel(block);
      slotEl.appendChild(label);

      if (block.type !== 'sleep') {
        const time = document.createElement('div');
        time.className = 'small';
        time.textContent = `${block.startTime} – ${block.endTime}`;
        slotEl.appendChild(time);
      }

      dayEl.appendChild(slotEl);
    }

    agendaRoot.appendChild(dayEl);
  }
}

function render(): void {
  updateUrl();

  btnViewWeek.classList.toggle('btn-secondary', viewMode === 'week');
  btnViewWeek.classList.toggle('btn-outline-secondary', viewMode !== 'week');
  btnViewMonth.classList.toggle('btn-secondary', viewMode === 'month');
  btnViewMonth.classList.toggle('btn-outline-secondary', viewMode !== 'month');

  let days: Date[];

  if (viewMode === 'week') {
    const weekStart = startOfWeek(anchorDate);
    days = Array.from({ length: 7 }, (_, i) => addDays(weekStart, i));
    navLabelEl.textContent = `${weekStart.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })} – ${addDays(weekStart, 6).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })}`;
    renderWeek(days);
  } else {
    const monthStart = startOfMonth(anchorDate);
    const monthEnd = new Date(monthStart.getFullYear(), monthStart.getMonth() + 1, 0);
    days = Array.from({ length: monthEnd.getDate() }, (_, i) => addDays(monthStart, i));
    navLabelEl.textContent = monthStart.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
    renderMonth(monthStart);
  }

  renderAgenda(days);
}

btnPrev.addEventListener('click', () => {
  anchorDate = viewMode === 'week' ? addDays(anchorDate, -7) : new Date(anchorDate.getFullYear(), anchorDate.getMonth() - 1, 1);
  render();
});
btnNext.addEventListener('click', () => {
  anchorDate = viewMode === 'week' ? addDays(anchorDate, 7) : new Date(anchorDate.getFullYear(), anchorDate.getMonth() + 1, 1);
  render();
});
btnToday.addEventListener('click', () => {
  anchorDate = new Date();
  render();
});
btnViewWeek.addEventListener('click', () => {
  viewMode = 'week';
  render();
});
btnViewMonth.addEventListener('click', () => {
  viewMode = 'month';
  render();
});

// ── Bootstrap ────────────────────────────────────────────────────────────

const MINIMUM_SCRAMBLE_DISPLAY_MS = 500;

async function boot(): Promise<void> {
  renderScrambledPlaceholder();
  const scrambleShownAt = Date.now();

  try {
    const response = await fetchWithPolling();
    renderTimezoneOffsetNote(response.timezone);

    const key = await resolveContentKey(response);
    const plaintext = await decryptString(key, response.ciphertext!);
    slots = JSON.parse(plaintext) as AvailabilitySlot[];

    const elapsed = Date.now() - scrambleShownAt;
    if (elapsed < MINIMUM_SCRAMBLE_DISPLAY_MS) {
      await new Promise((r) => setTimeout(r, MINIMUM_SCRAMBLE_DISPLAY_MS - elapsed));
    }

    render();
  } catch (error) {
    if (error instanceof LinkExpiredError) {
      mainEl.hidden = true;
      expiredEl.hidden = false;
      return;
    }

    calendarRoot.hidden = true;
    agendaRoot.hidden = true;
    statusEl.hidden = false;
    statusTextEl.textContent = error instanceof DecryptionFailedError
      ? 'Could not decrypt this calendar. The link may be broken.'
      : 'Could not load this calendar right now. Please try again later.';
  }
}

boot();
