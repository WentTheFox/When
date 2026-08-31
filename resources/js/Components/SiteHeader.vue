<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { BButton } from 'bootstrap-vue-next';
import ThemeToggle from './ThemeToggle.vue';

const page = usePage();

function logout(): void {
  router.post('/logout');
}
</script>

<template>
  <nav class="navbar navbar-expand navbar-dark fixed-top wtf-brand-header">
    <div class="container">
      <Link class="navbar-brand" :href="page.props.auth?.user ? '/dashboard' : '/'">{{ page.props.appName }}</Link>

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
        One flex group, not several loose children — .navbar > .container
        is justify-content: space-between, so with no .me-auto nav-links
        div (logged in, none rendered above) these would otherwise spread
        evenly across the whole bar instead of sitting together on the
        right.
      -->
      <div class="d-flex align-items-center ms-auto">
        <ThemeToggle />

        <template v-if="page.props.auth?.user">
          <Link href="/dashboard/account" class="d-flex align-items-center text-decoration-none ms-3 me-3" style="color: var(--wtf-header-text);">
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
          <BButton variant="outline-secondary" size="sm" @click="logout">Log out</BButton>
        </template>
      </div>
    </div>
  </nav>
</template>
