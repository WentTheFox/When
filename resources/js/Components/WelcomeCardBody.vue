<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import logoUrl from '../../img/WhenTheFox.svg';

const page = usePage();
</script>

<template>
  <div class="text-center mb-4">
    <img :src="logoUrl" alt="" width="160" height="160">
  </div>

  <h1 class="mb-2 text-center">{{ page.props.appName }}</h1>
  <p class="text-center mb-5">
    Share your availability with friends for simpler event planning across timezones
  </p>

  <h2 class="h5">What this is</h2>
  <p class="mb-3">
    <em>{{ page.props.appName }}</em> turns your calendar into a free/busy link you can hand to
    anyone. Friends, coworkers, whoever. It doesn't expose what's actually in your calendar
    (unless you explicitly enable it). Your calendar URL is encrypted at rest
    and only decrypted transiently to compute availability. The computed result is
    encrypted again before it's ever served to a viewer, and decrypted in
    their browser, not on the server.
  </p>
  <p>
    It also includes a small end-to-end-encrypted Connections CRM for
    keeping track of people. That data never leaves your device
    unencrypted, not even to us.
  </p>

  <h2 class="h5">Getting an account</h2>
  <p v-if="page.props.isFirstUser" class="mb-0">
    No one has registered yet. The first account created here needs no
    invite, but every account after it will.
  </p>
  <p v-else class="mb-0">
    Registration is invite-only. If someone shared a <em>{{ page.props.appName }}</em>
    calendar link with you, it comes with an invite to create your own. Otherwise,
    you'll need an invite from an existing user.
  </p>
</template>
