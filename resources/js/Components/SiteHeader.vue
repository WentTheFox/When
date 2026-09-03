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
import type { SharedPageProps } from '../sharedPageProps';
import LanguageSwitcher from './LanguageSwitcher.vue';
import ThemeToggle from './ThemeToggle.vue';
// Imported (not a plain /public path) so Vite fingerprints it — unlike
// public/favicon.svg (referenced directly from app.blade.php's <head>,
// which isn't part of the Vite-built bundle and follows the ordinary
// browser convention of a fixed, unversioned favicon URL), this one
// renders from inside the app bundle itself and should get cache-busted
// like every other asset the bundle references.
import logoUrl from '../../img/When.svg';

const page = usePage<SharedPageProps>();

function logout(): void {
  router.post('/logout');
}
</script>

<template>
  <nav class="navbar navbar-expand navbar-dark sticky-top wtf-brand-header">
    <div class="container">
      <Link class="navbar-brand" :href="page.props.auth?.user ? '/dashboard' : '/'">
        <img :src="logoUrl" alt="" width="28" height="28" class="d-inline-block align-text-top me-2">
        {{ page.props.appName }}
      </Link>

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

      <!--
        One flex group, not several loose children — .navbar > .container
        is justify-content: space-between, so with no .me-auto nav-links
        div (logged in, none rendered above) these would otherwise spread
        evenly across the whole bar instead of sitting together on the
        right.
      -->
      <div class="d-flex align-items-center ms-auto">
        <LanguageSwitcher class="me-2" />

        <ThemeToggle />

        <template v-if="page.props.auth?.user">
          <Link href="/dashboard/account" class="d-flex align-items-center text-decoration-none ms-3 me-3" style="color: var(--app-header-text);">
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
        </template>
      </div>
    </div>
  </nav>
</template>
