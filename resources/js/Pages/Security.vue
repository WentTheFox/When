<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import PublicLayout from '../Layouts/PublicLayout.vue';

defineOptions({ layout: PublicLayout });

const page = usePage();
</script>

<template>
  <Head>
    <title>Security &amp; data handling — {{ page.props.appName }}</title>
    <meta name="description" content="What is end-to-end encrypted, what isn't, and why.">
  </Head>

  <div style="max-width: 42rem; margin: 0 auto;">
    <h1 class="mb-4">Security &amp; data handling</h1>

    <p class="mb-4">
      This page states plainly what {{ page.props.appName }} actually protects and what it
      doesn't, rather than making a blanket "everything is end-to-end encrypted"
      claim that wouldn't survive scrutiny. Two different kinds of data live in
      this system, and they get genuinely different treatment.
    </p>

    <div class="card mb-4">
      <div class="card-body">
        <h2 class="h5">
          <span class="badge text-bg-success me-2">True end-to-end encryption</span>
          Connections CRM
        </h2>
        <p class="mb-2">
          Names, notes, custom attributes, and sources you store in the
          Connections CRM are encrypted in your browser before they're ever
          sent anywhere. We store only opaque ciphertext blobs and serve them
          back unmodified &mdash; we cannot read this data, at rest, in
          transit, or in a running server process, even if compelled to.
        </p>
        <p class="mb-0">
          The encryption key is derived from your master password entirely
          client-side and never leaves your browser in any form.
        </p>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-body">
        <h2 class="h5">
          <span class="badge text-bg-warning text-dark me-2">Ciphertext at rest, not full E2EE</span>
          Calendar URL &amp; computed availability
        </h2>
        <p class="mb-2">
          Your calendar URL is encrypted at rest using a key that lives only
          in the server's runtime secrets &mdash; never committed to source
          control, never stored in the database. That defeats the most common
          real-world threat: someone getting a copy of the source code and
          database together. It is <strong>not</strong> protected against a
          compromised or malicious production server itself, since the
          server has to be able to decrypt the URL to actually fetch your
          calendar.
        </p>
        <p class="mb-2">
          That fetch is unavoidable: computing your free/busy times means
          periodically downloading and parsing your calendar feed, and this
          has to happen on a schedule independent of whether you're logged
          in &mdash; most calendar providers don't let a browser fetch the
          feed directly. The decrypted URL and the raw calendar data are held
          only transiently, in memory, for the duration of that one fetch.
          They are never logged, never written to any table, and never
          included in error reports.
        </p>
        <p class="mb-0">
          The computed result (which times are free, busy, or highlighted) is
          re-encrypted immediately after it's computed, using the relevant
          share link's own key, before it's cached or served to anyone
          viewing your link.
        </p>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-body">
        <h2 class="h5">How your master password works</h2>
        <p class="mb-2">
          You set one master password. It's split, client-side, into two
          independent one-way values using Argon2id with different salts: one
          becomes your login credential (checked by the server the way an
          ordinary password is), the other derives your vault key (which
          never leaves your browser). Knowing one of these values doesn't
          help reconstruct the other without brute-forcing the master
          password itself.
        </p>
        <p class="mb-0">
          If we lose your master password, we cannot recover your Connections
          data for you &mdash; that's the direct consequence of us never
          having the key. Choose something you can actually remember, or use
          a password manager.
        </p>
      </div>
    </div>

    <p class="text-muted mb-0">
      Source code for all of this is public &mdash;
      <a href="https://github.com/WentTheFox/WhenTheFox" target="_blank" rel="noopener">
        see for yourself
      </a>.
    </p>
  </div>
</template>
