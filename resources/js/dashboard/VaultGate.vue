<script setup lang="ts">
/**
 * compact: a small inline "Unlock vault" prompt instead of a full-height
 * placeholder — for gating a single vault-tier piece of a page that's
 * otherwise usable unlocked (e.g. sleep exceptions' optional private
 * note), rather than an entire page that's vault-tier top to bottom
 * (Connections, ShareLinks). Either way, the actual dialog is the one
 * global VaultUnlockModal (mounted once in DashboardLayout.vue) — this
 * component only ever requests it and gates its slot on the result.
 */
import { BButton } from 'bootstrap-vue-next';
import { onMounted } from 'vue';
import { requestUnlock } from './vaultModal';
import { useVault } from './useVault';

const props = withDefaults(defineProps<{ compact?: boolean }>(), { compact: false });

const { vaultUnlocked } = useVault();

// "Any time you navigate to a page ... that needs it" — a whole-page gate
// asks immediately (requestUnlock() itself no-ops if already unlocked).
// A compact gate is a single field on an otherwise-usable page, so it only
// asks once the visitor actually tries to interact with that field — see
// the click handler below — not merely because the page containing it
// loaded.
onMounted(() => {
  if (!props.compact) requestUnlock();
});
</script>

<template>
  <div v-if="!vaultUnlocked && compact" class="small">
    <a href="#" @click.prevent="requestUnlock()">Unlock your vault</a> to view or edit this.
  </div>
  <div v-else-if="!vaultUnlocked" class="text-center text-muted py-5">
    <p class="mb-3">Your vault is locked.</p>
    <BButton variant="outline-secondary" size="sm" @click="requestUnlock()">Unlock vault</BButton>
  </div>
  <slot v-else />
</template>
