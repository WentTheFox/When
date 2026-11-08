/**
 * Idle auto-lock for the vault: a visible countdown plus the timer that fires
 * the lock. The deadline slides forward on user activity (and is mirrored to
 * the server, which is what actually enforces expiry of the persisted key —
 * see vaultPersistence.ts); this module only decides *when to lock this tab*.
 *
 * Event-driven: one setTimeout aimed at the deadline (re-aimed if activity
 * moved it) and passive activity listeners. The only recurring thing is the
 * 1s tick that repaints the countdown, and it exists only while a deadline
 * does. It never decides anything.
 */
import { computed, ref } from 'vue';
import { touchWrapKey } from './vaultPersistence';

/** Idle minutes before locking; null = never. Synced from the shared page props. */
export const lockTimeoutMinutes = ref<number | null>(15);

/** Epoch ms (this machine's clock) at which the vault locks; null = no deadline running. */
const deadline = ref<number | null>(null);
const now = ref(Date.now());

/** Whole seconds left, or null when there is no running deadline. */
export const remainingSeconds = computed(() => (
  deadline.value === null ? null : Math.max(0, Math.ceil((deadline.value - now.value) / 1000))
));

/** Always "h:mm:ss" ("0:14:32", "1:02:03") so the badge keeps one width as it counts down; the header mutes a leading "0:". */
export function formatRemaining(seconds: number): string {
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = seconds % 60;
  const pad = (n: number) => String(n).padStart(2, '0');

  return `${h}:${pad(m)}:${pad(s)}`;
}

const ACTIVITY_EVENTS = ['pointerdown', 'keydown', 'touchstart'] as const;
/** Server pings are throttled; the local deadline moves on every event. */
const TOUCH_THROTTLE_MS = 10_000;

let onExpire: () => void = () => {};
let lockTimer: ReturnType<typeof setTimeout> | null = null;
let tickTimer: ReturnType<typeof setInterval> | null = null;
let listening = false;
let lastTouchAt = 0;

/** useVault.ts registers its own lock() here (this module can't import it back). */
export function setAutoLockHandler(handler: () => void): void {
  onExpire = handler;
}

function armLockTimer(): void {
  if (lockTimer !== null) clearTimeout(lockTimer);
  if (deadline.value === null) return;

  lockTimer = setTimeout(checkDeadline, Math.max(0, deadline.value - Date.now()));
}

function checkDeadline(): void {
  lockTimer = null;
  if (deadline.value === null) return;

  if (Date.now() >= deadline.value) {
    onExpire();
  } else {
    armLockTimer(); // activity moved the deadline since this was scheduled
  }
}

function onActivity(): void {
  const minutes = lockTimeoutMinutes.value;
  if (deadline.value === null || minutes === null) return;

  // Refresh `now` together with the deadline: the 1s tick can be almost a
  // second stale, which made the badge read 15:01 right after a click.
  const t = Date.now();
  now.value = t;
  deadline.value = t + minutes * 60_000;

  if (t - lastTouchAt >= TOUCH_THROTTLE_MS) {
    lastTouchAt = t;
    void touchWrapKey();
  }
}

/** Background tabs throttle timers; re-check the moment the tab is looked at again. */
function onVisibility(): void {
  if (document.visibilityState === 'visible') {
    now.value = Date.now();
    checkDeadline();
  }
}

function listen(): void {
  if (listening) return;
  listening = true;
  ACTIVITY_EVENTS.forEach((e) => window.addEventListener(e, onActivity, { passive: true }));
  document.addEventListener('visibilitychange', onVisibility);
  tickTimer = setInterval(() => { now.value = Date.now(); }, 1000);
}

function unlisten(): void {
  if (!listening) return;
  listening = false;
  ACTIVITY_EVENTS.forEach((e) => window.removeEventListener(e, onActivity));
  document.removeEventListener('visibilitychange', onVisibility);
  if (tickTimer !== null) clearInterval(tickTimer);
  tickTimer = null;
}

/**
 * Starts (or restarts) the countdown for an unlocked vault. `deadlineAt` lets
 * a restored session continue where the server says it stands; otherwise it's
 * a full timeout from now. No-op for "never".
 */
export function startAutoLock(deadlineAt?: number | null): void {
  const minutes = lockTimeoutMinutes.value;
  if (minutes === null && deadlineAt == null) {
    stopAutoLock();
    return;
  }

  now.value = Date.now();
  deadline.value = deadlineAt ?? Date.now() + (minutes as number) * 60_000;
  listen();
  armLockTimer();
}

export function stopAutoLock(): void {
  deadline.value = null;
  if (lockTimer !== null) clearTimeout(lockTimer);
  lockTimer = null;
  unlisten();
}

/** Applies a changed timeout setting to a running countdown. */
export function applyTimeoutSetting(minutes: number | null, vaultUnlocked: boolean): void {
  const changed = lockTimeoutMinutes.value !== minutes;
  lockTimeoutMinutes.value = minutes;

  if (!changed || !vaultUnlocked) return;

  if (minutes === null) {
    stopAutoLock();
  } else {
    startAutoLock();
  }
  void touchWrapKey(); // the server recomputes its expiry from the saved setting
}
