<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import SiteFooter from '../Components/SiteFooter.vue';
import SiteHeader from '../Components/SiteHeader.vue';
import VaultUnlockModal from '../dashboard/VaultUnlockModal.vue';
import { provideLiveThemePreview } from '../dashboard/liveThemePreview';
import { hexToRgbTriplet, yiqTextColor } from '../free/color-utils';
import { resolveSwatchHex } from '../free/color-palette';
import { useResolvedTheme } from '../composables/useTheme';
import type { SharedPageProps } from '../sharedPageProps';

const page = usePage<SharedPageProps>();

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
  '--wtf-accent-text': yiqTextColor(accentColor.value),
  '--wtf-text-muted': secondaryColor.value,
}));
</script>

<template>
  <div class="wtf-backdrop" :style="accentStyle">
    <SiteHeader />

    <div class="container py-4">
      <slot />
    </div>

    <SiteFooter />

    <VaultUnlockModal />
  </div>
</template>
