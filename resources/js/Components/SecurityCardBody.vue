<script setup lang="ts">

import { usePage } from '@inertiajs/vue3';

const page = usePage();
</script>

<template>
  <h2 class="h3 mb-4">Security &amp; data handling</h2>

  <p class="mb-2">
    This page states plainly what <em>{{ page.props.appName }}</em> actually protects and what it
    doesn't, rather than making a blanket "everything is end-to-end encrypted"
    claim that wouldn't survive scrutiny. Two different kinds of data live in
    this system, and they get genuinely different treatment.
  </p>

  <p class="text-muted mb-4">
    Source code for all of this is publicly <a href="https://github.com/WentTheFox/WhenTheFox" target="_blank" rel="noopener">available on Github</a>.
  </p>

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
  <p class="mb-4">
    The encryption key is derived from your master password entirely
    client-side and never leaves your browser in any form.
  </p>

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
  <p class="mb-4">
    The computed result (which times are free, busy, or highlighted) is
    re-encrypted immediately after it's computed, using the relevant
    share link's own key, before it's cached or served to anyone
    viewing your link.
  </p>

  <h2 class="h5">Account security</h2>
  <p class="mb-2">
    Optional two-factor authentication (TOTP, i.e. an authenticator
    app) is available for your login &mdash; you can turn it on from
    your account's security settings.
  </p>
  <p class="mb-4">
    An email address is optional. If you set one, it's only ever used
    to fetch your Gravatar avatar (only an MD5 hash of it is sent to
    Gravatar, never the address itself) and, if you like, as an
    alternate way to log in alongside your name. It's stored
    encrypted at rest, the same as your calendar URL above.
  </p>

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
    If you lose your master password, we cannot recover your Connections
    data for you &mdash; that's the direct consequence of us never
    having the key. Choose something you can actually remember, or use
    a password manager.
  </p>
</template>
