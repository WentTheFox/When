import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('../vaultPersistence', () => ({ touchWrapKey: vi.fn(async () => null) }));

const listeners = new Map<string, Set<() => void>>();
const target = (map: Map<string, Set<() => void>>) => ({
  addEventListener: (type: string, fn: () => void) => {
    if (!map.has(type)) map.set(type, new Set());
    map.get(type)!.add(fn);
  },
  removeEventListener: (type: string, fn: () => void) => map.get(type)?.delete(fn),
});

async function load() {
  vi.resetModules();
  const mod = await import('../vaultAutoLock');
  // After the import: vue's runtime-dom reads the real `document` while loading.
  vi.stubGlobal('window', target(listeners));
  vi.stubGlobal('document', { ...target(new Map()), visibilityState: 'visible' });
  return mod;
}

describe('vaultAutoLock', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    listeners.clear();
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  it('formats the countdown', async () => {
    const { formatRemaining } = await load();
    expect(formatRemaining(872)).toBe('0:14:32');
    expect(formatRemaining(5)).toBe('0:00:05');
    expect(formatRemaining(3723)).toBe('1:02:03');
  });

  it('locks once the idle timeout elapses', async () => {
    const { applyTimeoutSetting, startAutoLock, setAutoLockHandler, remainingSeconds } = await load();
    const expire = vi.fn();
    setAutoLockHandler(expire);
    applyTimeoutSetting(5, false);

    startAutoLock();
    expect(remainingSeconds.value).toBe(300);

    vi.advanceTimersByTime(299_000);
    expect(expire).not.toHaveBeenCalled();
    vi.advanceTimersByTime(2_000);
    expect(expire).toHaveBeenCalledTimes(1);
  });

  it('activity pushes the deadline back', async () => {
    const { applyTimeoutSetting, startAutoLock, setAutoLockHandler } = await load();
    const expire = vi.fn();
    setAutoLockHandler(expire);
    applyTimeoutSetting(1, false);
    startAutoLock();

    vi.advanceTimersByTime(50_000);
    listeners.get('pointerdown')!.forEach((fn) => fn());
    vi.advanceTimersByTime(50_000); // 100s since start, but only 50s since activity
    expect(expire).not.toHaveBeenCalled();
    vi.advanceTimersByTime(11_000);
    expect(expire).toHaveBeenCalledTimes(1);
  });

  it('shows the full timeout right after activity, even between ticks', async () => {
    const { applyTimeoutSetting, startAutoLock, remainingSeconds } = await load();
    applyTimeoutSetting(15, false);
    startAutoLock();

    vi.advanceTimersByTime(1_400); // 400ms past the last 1s tick
    listeners.get('pointerdown')!.forEach((fn) => fn());
    expect(remainingSeconds.value).toBe(900);
  });

  it('never means no deadline and no listeners', async () => {
    const { applyTimeoutSetting, startAutoLock, remainingSeconds } = await load();
    applyTimeoutSetting(null, false);
    startAutoLock();

    expect(remainingSeconds.value).toBeNull();
    expect(listeners.get('pointerdown')?.size ?? 0).toBe(0);
  });

  it('stopAutoLock cancels the timer', async () => {
    const { applyTimeoutSetting, startAutoLock, stopAutoLock, setAutoLockHandler } = await load();
    const expire = vi.fn();
    setAutoLockHandler(expire);
    applyTimeoutSetting(1, false);
    startAutoLock();
    stopAutoLock();

    vi.advanceTimersByTime(120_000);
    expect(expire).not.toHaveBeenCalled();
  });

  it('switching the setting to never stops a running countdown', async () => {
    const { applyTimeoutSetting, startAutoLock, remainingSeconds } = await load();
    applyTimeoutSetting(15, false);
    startAutoLock();
    expect(remainingSeconds.value).toBe(900);

    applyTimeoutSetting(null, true);
    expect(remainingSeconds.value).toBeNull();
  });
});
