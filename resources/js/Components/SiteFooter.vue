<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { getRememberedInviteCode } from '../composables/useInviteCode';
import type { SharedPageProps } from '../sharedPageProps';

/**
 * `showInviteCta`/`showAboutLink` exist purely for Errors/Maintenance.vue:
 * /register and /about are both intercepted by maintenance mode exactly
 * like every other route, so pointing a visitor at either while the app is
 * down just lands them back on this same page — same reasoning as that
 * page's own header leaving out SiteHeader's Login/Create account links.
 * "Developed by WentTheFox" and "Source code" stay either way (external
 * links, unaffected by maintenance mode).
 */
withDefaults(defineProps<{ showInviteCta?: boolean; showAboutLink?: boolean }>(), {
  showInviteCta: true,
  showAboutLink: true,
});

const page = usePage<SharedPageProps>();
const inviteCode = getRememberedInviteCode();
</script>
<template>
  <footer class="wtf-footer">
    <template v-if="showInviteCta && !page.props.auth?.user && inviteCode">
      <span>
        <a :href="`/register?code=${inviteCode}`">
          Create your own <em>{{ $page.props.appName }}</em> calendar
        </a>
      </span>
      <span>&middot;</span>
    </template>
    <span>Developed by <a
      href="https://went.tf"
      target="_blank"
      rel="noopener"
    >WentTheFox</a></span>
    <template v-if="showAboutLink">
      <span>&middot;</span>
      <span><a href="/about">About this project</a></span>
    </template>
    <span>&middot;</span>
    <span><a href="https://github.com/WentTheFox/When" target="_blank" rel="noopener">Source code</a></span>
  </footer>
</template>
