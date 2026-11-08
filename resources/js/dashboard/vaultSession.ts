/**
 * Glue between Inertia page loads and the vault's persisted session:
 * restores an unlocked vault after a hard reload, keeps the auto-lock timeout
 * in step with the saved setting, and locks everything the moment the page
 * stops being an authenticated one (logout, expired session) — the in-memory
 * key used to outlive logout in the SPA.
 */
import { clearPersistedVaultKey } from './vaultPersistence';
import { applyTimeoutSetting } from './vaultAutoLock';
import { isVaultUnlocked } from './vault';
import { lock, restore } from './useVault';
import { autoUnlockPending } from './vaultModal';

interface PageLike {
  props: Record<string, unknown>;
}

function authUser(page: PageLike): { vaultLockTimeoutMinutes?: number | null } | null {
  const user = (page.props.auth as { user?: { vaultLockTimeoutMinutes?: number | null } | null } | undefined)?.user;

  return user ?? null;
}

/** First page of a hard load. Call once, before the app mounts. */
export function initVaultSession(page: PageLike): void {
  const user = authUser(page);

  if (!user) {
    void clearPersistedVaultKey();
    return;
  }

  applyTimeoutSetting(user.vaultLockTimeoutMinutes ?? null, false);

  // Makes requestUnlock() wait for this instead of popping the passphrase
  // modal over a vault that is about to open by itself.
  autoUnlockPending.value = true;
  restore()
    .catch(() => false)
    .finally(() => { autoUnlockPending.value = false; });
}

/** Every later Inertia navigation. */
export function syncVaultSession(page: PageLike): void {
  const user = authUser(page);

  if (!user) {
    if (isVaultUnlocked()) lock();
    return;
  }

  applyTimeoutSetting(user.vaultLockTimeoutMinutes ?? null, isVaultUnlocked());
}
