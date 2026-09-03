<script setup lang="ts">
import { faDoorOpen } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';

/**
 * The one header used everywhere — PublicLayout.vue (guest and public
 * pages) and DashboardLayout.vue alike — instead of each layout
 * maintaining its own separately-styled navbar. There used to be two: a
 * public one with an accent-colored blurred-glow bar, and a dashboard one
 * with a flat neutral bar and different text-color wiring — subtly
 * different enough that navigating between a public page (e.g. /security)
 * and a dashboard one (e.g. /dashboard/account) visibly changed the
 * header's look for no reason a user would expect. Which nav-links show
 * (guest links vs. the dashboard's own) is the only thing that varies now,
 * gated on auth state below — the visual design itself never does.
 */
import { Link, router, usePage } from '@inertiajs/vue3';
import { BButton } from 'bootstrap-vue-next';
import { computed, ref, watch } from 'vue';
import type { SharedPageProps } from '../sharedPageProps';
import BrandMark from './BrandMark.vue';
import HeaderBar from './HeaderBar.vue';
import LanguageSwitcher from './LanguageSwitcher.vue';
import ThemeToggle from './ThemeToggle.vue';

const page = usePage<SharedPageProps>();

// Owned here (not local to HeaderBar) so it can be reset on navigation —
// otherwise following a link from the mobile menu would leave the panel
// stuck open over the next page.
const collapseOpen = ref(false);
watch(() => page.url, () => {
  collapseOpen.value = false;
});

// Only /free/{token} has locale-prefixed counterparts (one per
// App\Support\Locales::codes() entry — see routes/web.php's own comment)
// — every other page in the app is English-only, so LanguageSwitcher
// renders nothing anywhere else rather than linking somewhere that
// doesn't exist. This is SiteHeader's own concern, not
// LanguageSwitcher's — Errors/Maintenance.vue always shows its copy,
// on whatever URL happens to be up.
const LOCALE_PATH_RE = /^\/([a-z]{2})\/free\//;
const isFreePage = computed(() => (
  window.location.pathname.startsWith('/free/') || LOCALE_PATH_RE.test(window.location.pathname)
));
const currentLocaleCode = computed(() => window.location.pathname.match(LOCALE_PATH_RE)?.[1] ?? 'en');

function logout(): void {
  router.post('/logout');
}
</script>

<template>
  <HeaderBar v-model:open="collapseOpen">
    <BrandMark :app-name="page.props.appName" :href="page.props.auth?.user ? '/dashboard' : '/'" />

    <!--
      One flex group, not several loose children — .navbar > .container
      is justify-content: space-between, so these would otherwise spread
      evenly across the whole bar instead of sitting together on the
      right, next to the hamburger toggle HeaderBar renders after this
      slot. Stays visible at every width — only the nav-link/account
      content below (the #collapsible slot) collapses on mobile.
    -->
    <div class="d-flex align-items-center ms-auto">
      <LanguageSwitcher
        v-if="isFreePage"
        :locales="page.props.locales"
        :model-value="currentLocaleCode"
        class="me-2"
      />

      <ThemeToggle />
    </div>

    <template #collapsible>
      <div v-if="!page.props.auth?.user" class="navbar-nav me-auto">
        <Link class="nav-item nav-link" :class="{ active: page.url === '/login' }" href="/login">Log in</Link>
        <Link
          v-if="page.props.isFirstUser"
          class="nav-item nav-link"
          :class="{ active: page.url === '/register' }"
          href="/register"
        >
          Create account
        </Link>
      </div>

      <!--
        Shown on every page once logged in — not just dashboard ones — this
        is the one shared header for the whole app (see this file's own
        header comment), so one consistent way to get around it regardless
        of which page happens to render it.
      -->
      <div v-else class="navbar-nav me-auto">
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

      <div v-if="page.props.auth?.user" class="d-flex align-items-center flex-wrap ms-lg-auto">
        <Link href="/dashboard/account" class="d-flex align-items-center text-decoration-none my-2 my-lg-0 ms-lg-3 me-3" style="color: var(--app-header-text);">
          <img
            v-if="page.props.auth.user.avatarUrl"
            :src="page.props.auth.user.avatarUrl"
            alt=""
            class="rounded-circle me-2"
            width="28"
            height="28"
          >
          <span>{{ page.props.auth.user.name }}</span>
        </Link>
        <BButton variant="outline-secondary" size="sm" @click="logout"><FontAwesomeIcon :icon="faDoorOpen"/></BButton>
      </div>
    </template>
  </HeaderBar>
</template>
