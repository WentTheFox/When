<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { BButton } from 'bootstrap-vue-next';
import { computed } from 'vue';
import SiteFooter from '../Components/SiteFooter.vue';
import ThemeToggle from '../Components/ThemeToggle.vue';
import VaultUnlockModal from '../dashboard/VaultUnlockModal.vue';
import { provideLiveThemePreview } from '../dashboard/liveThemePreview';
import { hexToRgbTriplet } from '../free/color-utils';
import { resolveSwatchHex } from '../free/color-palette';
import { useResolvedTheme } from '../composables/useTheme';

const page = usePage();

function logout(): void {
  router.post('/logout');
}

const liveThemeOverride = provideLiveThemePreview();
const resolvedTheme = useResolvedTheme();

/**
 * Reflects the owner's own public-page accent/secondary colors (Settings)
 * across their dashboard too, not just their public share page's own
 * preview — same --wtf-accent/--wtf-accent-rgb/--wtf-text-muted mapping
 * Free/Show.vue's rootStyle already applies there, resolved against
 * whichever theme the dashboard itself is currently rendered in. A live
 * override (see liveThemePreview.ts) takes priority while Settings.vue's
 * color pickers are being dragged, before anything is saved — it's already
 * resolved to the current theme by the time it lands here.
 */
const accentColor = computed(() => liveThemeOverride.value?.accent
  ?? resolveSwatchHex(page.props.auth?.user?.accentColorKey, 'accent', resolvedTheme.value));
const secondaryColor = computed(() => liveThemeOverride.value?.secondary
  ?? resolveSwatchHex(page.props.auth?.user?.secondaryColorKey, 'secondary', resolvedTheme.value));
const accentStyle = computed(() => ({
  '--wtf-accent': accentColor.value,
  '--wtf-accent-rgb': hexToRgbTriplet(accentColor.value),
  '--wtf-text-muted': secondaryColor.value,
}));
</script>

<template>
  <div class="wtf-backdrop" :style="accentStyle">
    <nav
      class="navbar navbar-expand navbar-dark sticky-top"
      style="background: var(--wtf-bg-elevated); border-bottom: 1px solid var(--wtf-border);"
    >
      <div class="container">
        <Link class="navbar-brand" href="/dashboard">{{ page.props.appName }}</Link>
        <div class="navbar-nav me-auto">
          <Link class="nav-item nav-link" :class="{ active: page.url === '/dashboard' }" href="/dashboard">Overview</Link>
          <Link class="nav-item nav-link" :class="{ active: page.url.startsWith('/settings') }" href="/settings">Settings</Link>
          <Link
            class="nav-item nav-link"
            :class="{ active: page.url.startsWith('/dashboard/share-links') }"
            href="/dashboard/share-links"
          >
            Share links
          </Link>
          <Link
            class="nav-item nav-link"
            :class="{ active: page.url.startsWith('/dashboard/connections') }"
            href="/dashboard/connections"
          >
            Connections
          </Link>
          <Link class="nav-item nav-link" :class="{ active: page.url.startsWith('/invites') }" href="/invites">Invites</Link>
        </div>

        <ThemeToggle class="me-3" />

        <Link href="/dashboard/account" class="d-flex align-items-center text-decoration-none me-3" style="color: inherit;">
          <img
            v-if="page.props.auth?.user?.avatarUrl"
            :src="page.props.auth.user.avatarUrl"
            alt=""
            class="rounded-circle me-2"
            width="28"
            height="28"
          >
          <span class="small">{{ page.props.auth?.user?.name }}</span>
        </Link>

        <BButton variant="outline-secondary" size="sm" @click="logout">Log out</BButton>
      </div>
    </nav>

    <div class="container py-4">
      <slot />
    </div>

    <SiteFooter />

    <VaultUnlockModal />
  </div>
</template>
