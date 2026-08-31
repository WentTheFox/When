<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { BButton } from 'bootstrap-vue-next';
import ThemeToggle from '../Components/ThemeToggle.vue';
import VaultUnlockModal from '../dashboard/VaultUnlockModal.vue';

const page = usePage();

function logout(): void {
  router.post('/logout');
}
</script>

<template>
  <nav
    class="navbar navbar-expand navbar-dark"
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

      <img
        v-if="page.props.auth?.user?.avatarUrl"
        :src="page.props.auth.user.avatarUrl"
        alt=""
        class="rounded-circle me-2"
        width="28"
        height="28"
      >
      <span class="small me-3">{{ page.props.auth?.user?.name }}</span>

      <BButton variant="outline-secondary" size="sm" @click="logout">Log out</BButton>
    </div>
  </nav>

  <div class="container py-4">
    <slot />
  </div>

  <VaultUnlockModal />
</template>
