<script setup lang="ts">
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
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import type { SharedPageProps } from '../sharedPageProps';
import AccountMenu from './AccountMenu.vue';
import BrandMark from './BrandMark.vue';
import HeaderBar from './HeaderBar.vue';
import LanguageSwitcher from './LanguageSwitcher.vue';
import ThemeToggle from './ThemeToggle.vue';

/** Who a /free link was made for (their avatar and name), shown like the logged-in user but without any account links. */
export interface HeaderIdentity {
  provider: string;
  name: string;
  username: string | null;
  picture_url: string | null;
}

const props = defineProps<{
  identity?: HeaderIdentity | null;
  /** Render as an anonymous visitor would see it, even though someone is logged in (the owner's "preview as visitor" on /free). */
  asVisitor?: boolean;
  /** Language shown in the switcher instead of the URL's (the visitor preview applies a detected visitor locale client-side). */
  localeOverride?: string;
}>();

const page = usePage<SharedPageProps>();
const user = computed(() => (props.asVisitor ? null : page.props.auth?.user));

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
// page.url carries the query string and hash (`/dashboard?x=1`), which an
// exact comparison would miss — match on the path alone.
const path = computed(() => page.url.split(/[?#]/)[0]);
const isActive = (prefix: string, exact = false) => (
  exact ? path.value === prefix : path.value === prefix || path.value.startsWith(`${prefix}/`)
);

const LOCALE_PATH_RE = /^\/([a-z]{2})\/free\//;
const isFreePage = computed(() => (
  window.location.pathname.startsWith('/free/') || LOCALE_PATH_RE.test(window.location.pathname)
));
const currentLocaleCode = computed(() => props.localeOverride ?? window.location.pathname.match(LOCALE_PATH_RE)?.[1] ?? 'en');
</script>

<template>
  <HeaderBar v-model:open="collapseOpen">
    <BrandMark :app-name="page.props.appName" :href="user ? '/dashboard' : '/'" />

    <!--
      One flex group, not several loose children — .navbar > .container
      is justify-content: space-between, so these would otherwise spread
      evenly across the whole bar instead of sitting together on the
      right, next to the hamburger toggle HeaderBar renders after this
      slot. Stays visible at every width — only the nav-link/account
      content below (the #collapsible slot) collapses on mobile.

      order-lg-last: at ≥lg the collapsible slot's nav-links/account
      content becomes a normal (visible, non-collapsed) flex item in this
      same .container row, sitting between this div and the (hidden at lg)
      toggle in DOM order — without an explicit order, ms-auto here just
      pulls this div to hug the *left* edge of that nav-links content
      instead of past it, landing it between the brand and the nav links
      rather than at the actual right edge of the bar. Below lg the
      collapse is genuinely collapsed/hidden, so order is irrelevant there
      and this stays right where DOM order already puts it, next to the
      toggle.
    -->
    <div class="d-flex align-items-center ms-auto order-lg-last">
      <LanguageSwitcher
        v-if="isFreePage"
        :locales="page.props.locales"
        :model-value="currentLocaleCode"
        class="me-2"
      />

      <ThemeToggle />

      <!-- Here, not in the collapsible nav below, so the auto-lock countdown stays visible on mobile too. -->
      <AccountMenu v-if="!asVisitor" />
    </div>

    <template #collapsible>
      <div v-if="!user" class="navbar-nav me-auto">
        <Link class="nav-item nav-link" :class="{ active: isActive('/login', true) }" :aria-current="isActive('/login', true) ? 'page' : undefined" href="/login">Log in</Link>
        <Link
          v-if="page.props.isFirstUser"
          class="nav-item nav-link"
          :class="{ active: isActive('/register', true) }"
          :aria-current="isActive('/register', true) ? 'page' : undefined"
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
        <Link class="nav-item nav-link" :class="{ active: isActive('/dashboard', true) }" :aria-current="isActive('/dashboard', true) ? 'page' : undefined" href="/dashboard">Overview</Link>
        <Link class="nav-item nav-link" :class="{ active: isActive('/settings') }" :aria-current="isActive('/settings') ? 'page' : undefined" href="/settings">Settings</Link>
        <Link
          class="nav-item nav-link"
          :class="{ active: isActive('/dashboard/connections') }"
          :aria-current="isActive('/dashboard/connections') ? 'page' : undefined"
          href="/dashboard/connections"
        >
          Connections
        </Link>
        <Link class="nav-item nav-link" :class="{ active: isActive('/invites') }" :aria-current="isActive('/invites') ? 'page' : undefined" href="/invites">Invites</Link>
      </div>

      <div v-if="identity" class="d-flex align-items-center my-2 my-lg-0 ms-lg-auto me-3" style="color: var(--app-header-text);">
        <img
          v-if="identity.picture_url"
          :src="identity.picture_url"
          alt=""
          class="rounded-circle me-2"
          width="28"
          height="28"
          referrerpolicy="no-referrer"
        >
        <span :title="identity.username ? `@${identity.username}` : undefined">{{ identity.name }}</span>
      </div>
    </template>
  </HeaderBar>
</template>
