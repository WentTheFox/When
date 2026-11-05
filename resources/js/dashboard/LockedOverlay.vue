<script setup lang="ts">
/**
 * Wraps a vault-tier list. While the vault is locked the content stays
 * visible underneath (lists show their items as raw ciphertext) but is dimmed
 * and inert, with an overlay on top that asks for the unlock only when it is
 * clicked — nothing prompts just because the page opened. Once unlocked the
 * wrapper is transparent.
 *
 * The dialog itself is the one global VaultUnlockModal (DashboardLayout.vue);
 * this only requests it.
 */
import { faLock } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { requestUnlock } from './vaultModal';
import { useVault } from './useVault';

const { vaultUnlocked } = useVault();
</script>

<template>
  <div class="locked-overlay-wrap">
    <div :class="{ 'locked-content': !vaultUnlocked }" :inert="!vaultUnlocked" :aria-hidden="!vaultUnlocked || undefined">
      <slot />
    </div>
    <button v-if="!vaultUnlocked" type="button" class="locked-overlay" @click="requestUnlock()">
      <FontAwesomeIcon :icon="faLock" />
      <span>Vault locked — click to unlock</span>
    </button>
  </div>
</template>

<style scoped>
.locked-overlay-wrap {
  position: relative;
  min-height: 6rem;
}

.locked-content {
  opacity: 0.55;
  user-select: none;
  pointer-events: none;
  overflow: hidden;
}

.locked-overlay {
  position: absolute;
  inset: 0;
  z-index: 5;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.35rem;
  border: 0;
  border-radius: var(--bs-border-radius);
  background: color-mix(in srgb, var(--bs-body-bg) 60%, transparent);
  color: var(--bs-body-color);
  cursor: pointer;
}

.locked-overlay:hover,
.locked-overlay:focus-visible {
  background: color-mix(in srgb, var(--bs-body-bg) 45%, transparent);
}
</style>
