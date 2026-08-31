<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import PublicLayout from '../Layouts/PublicLayout.vue';
import logoUrl from '../../img/WhenTheFox.svg';

defineOptions({ layout: PublicLayout });

const page = usePage();
</script>

<template>
  <Head>
    <title>{{ page.props.appName }}</title>
    <meta name="description" content="End-to-end-encrypted availability sharing and a Connections CRM.">
  </Head>

  <div style="max-width: 42rem; margin: 0 auto;">
    <div class="card mb-4">
      <div class="card-body">
        <div class="text-center mb-4">
          <img :src="logoUrl" alt="" width="160" height="160">
        </div>

        <h1 class="mb-2 text-center">{{ page.props.appName }}</h1>
        <p class="text-center mb-5">
          Share your availability with friends for simpler event planning across timezones
        </p>

        <h2 class="h5">What this is</h2>
        <p class="mb-3">
          {{ page.props.appName }} turns your calendar into a free/busy link you can hand to
          anyone. Friends, coworkers, whoever. It doesn't expose what's actually
          on your calendar. Your calendar URL is encrypted at rest and only
          decrypted transiently to compute availability. The computed result is
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
          Registration is invite-only. If someone shared a {{ page.props.appName }} calendar
          link with you, it comes with an invite to create your own. Otherwise,
          you'll need an invite from an existing owner.
        </p>
      </div>
    </div>
  </div>
</template>
