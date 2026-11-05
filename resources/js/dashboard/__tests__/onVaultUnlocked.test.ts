import { nextTick, ref } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import { onVaultUnlocked } from '../onVaultUnlocked';

describe('onVaultUnlocked', () => {
  it('runs immediately when the vault is already unlocked', () => {
    const run = vi.fn();
    onVaultUnlocked(ref(true), run);
    expect(run).toHaveBeenCalledTimes(1);
  });

  it('does nothing while locked, then runs once the vault unlocks (the reload-with-a-record case)', async () => {
    const unlocked = ref(false);
    const run = vi.fn();

    onVaultUnlocked(unlocked, run);
    expect(run).not.toHaveBeenCalled();

    unlocked.value = true;
    await nextTick();
    expect(run).toHaveBeenCalledTimes(1);
  });

  it('does not run when the vault locks again, but runs on the next unlock', async () => {
    const unlocked = ref(true);
    const run = vi.fn();
    onVaultUnlocked(unlocked, run);
    expect(run).toHaveBeenCalledTimes(1);

    unlocked.value = false;
    await nextTick();
    expect(run).toHaveBeenCalledTimes(1);

    unlocked.value = true;
    await nextTick();
    expect(run).toHaveBeenCalledTimes(2);
  });
});
