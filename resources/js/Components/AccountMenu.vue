<script setup lang="ts">
/**
 * The signed-in owner's menu in the shared header: Account, Lock vault and
 * Sign out behind one icon, with the auto-lock countdown beside it so the time
 * left is readable without opening anything. Lives in SiteHeader's
 * always-visible group (not the collapsible nav), so the countdown shows on
 * mobile too.
 */
import { LockIcon, LockOpenIcon, LogOutIcon, UserRoundIcon } from '@lucide/vue';
import { router, usePage } from '@inertiajs/vue3';
import { BDropdown, BDropdownDivider, BDropdownItem, BTooltip } from 'bootstrap-vue-next';
import { computed } from 'vue';
import type { SharedPageProps } from '../sharedPageProps';
import { formatRemaining, remainingSeconds } from '../dashboard/vaultAutoLock';
import { lock, vaultUnlocked } from '../dashboard/useVault';
import { requestUnlock } from '../dashboard/vaultModal';
import AppIcon from './AppIcon.vue';

const page = usePage<SharedPageProps>();

/** The Account page is reached through this menu; its item is marked active inside the dropdown. */
const onAccountPage = computed(() => /^\/dashboard\/account(?:[/?#]|$)/.test(page.url));

/** What the badge says: the countdown, "Unlocked" (no timeout set) or "Locked". */
const badge = computed(() => {
  if (!vaultUnlocked.value) return 'Locked';
  return remainingSeconds.value === null ? 'Unlocked' : formatRemaining(remainingSeconds.value);
});

/** Under an hour the leading "0:" stays (so the width never changes) but is muted. */
const mutedHours = computed(() => /^0:\d\d:\d\d$/.test(badge.value));
const badgeText = computed(() => (mutedHours.value ? badge.value.slice(2) : badge.value));

/** The last minute is the warning stretch. */
const urgent = computed(() => vaultUnlocked.value && remainingSeconds.value !== null && remainingSeconds.value <= 60);

const badgeTitle = computed(() => (
  !vaultUnlocked.value
    ? 'Vault locked. Click to unlock.'
    : remainingSeconds.value === null
      ? 'Vault unlocked; auto-lock is off. Change the limit in your account settings.'
      : 'Time until the vault locks itself. Activity resets it. Change the limit in your account settings.'
));

function onBadgeClick(): void {
  if (!vaultUnlocked.value) void requestUnlock();
}

function logout(): void {
  lock(); // the in-memory key must not outlive the session
  router.post('/logout');
}
</script>

<template>
  <div v-if="page.props.auth?.user" class="d-flex align-items-center">
    <button
      id="vault-lock-badge"
      type="button"
      class="btn btn-sm py-0 px-2 me-1 d-inline-flex align-items-center text-nowrap wtf-vault-badge"
      :class="urgent ? 'btn-warning' : 'btn-outline-secondary'"
      @click="onBadgeClick"
    >
      <AppIcon :icon="vaultUnlocked ? LockOpenIcon : LockIcon" class="me-1" />
      <span class="font-monospace" aria-hidden="true"><span v-if="mutedHours" class="opacity-50">0:</span>{{ badgeText }}</span>
      <span class="visually-hidden">{{ badgeTitle }}</span>
    </button>
    <BTooltip target="vault-lock-badge" placement="bottom">{{ badgeTitle }}</BTooltip>

    <BDropdown variant="link" toggle-class="nav-link" no-caret end>
      <template #button-content>
        <img
          v-if="page.props.auth.user.avatarUrl"
          :src="page.props.auth.user.avatarUrl"
          alt=""
          class="rounded-circle"
          width="24"
          height="24"
        >
        <AppIcon v-else :icon="UserRoundIcon" />
        <span class="ms-2">{{ page.props.auth.user.name }}</span>
      </template>

      <BDropdownItem href="/dashboard/account" :active="onAccountPage" @click.prevent="router.visit('/dashboard/account')">
        <AppIcon :icon="UserRoundIcon" class="me-2" />Account
      </BDropdownItem>
      <BDropdownItem :disabled="!vaultUnlocked" @click="lock">
        <AppIcon :icon="LockIcon" class="me-2" />{{ vaultUnlocked ? 'Lock vault' : 'Vault is locked' }}
      </BDropdownItem>
      <BDropdownDivider />
      <BDropdownItem @click="logout">
        <AppIcon :icon="LogOutIcon" class="me-2" />Sign out
      </BDropdownItem>
    </BDropdown>
  </div>
</template>
