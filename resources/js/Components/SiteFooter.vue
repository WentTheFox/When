<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { getRememberedInviteCode } from '../composables/useInviteCode';
import type { SharedPageProps } from '../sharedPageProps';

const page = usePage<SharedPageProps>();
const inviteCode = getRememberedInviteCode();
</script>
<template>
  <footer class="wtf-footer">
    <template v-if="!page.props.auth?.user && inviteCode">
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
    <span>&middot;</span>
    <span><a href="/about">About this project</a></span>
    <span>&middot;</span>
    <span><a href="https://github.com/WentTheFox/When" target="_blank" rel="noopener">Source code</a></span>
  </footer>
</template>
