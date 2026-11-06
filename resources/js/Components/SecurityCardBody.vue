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
    Source code for all of this is publicly <a href="https://github.com/WentTheFox/When" target="_blank" rel="noopener">available on Github</a>.
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
  <p class="mb-4">
    The one exception inside the Connections CRM is the optional
    <strong>identifier</strong> attribute type, intended for linking a
    connection to an external service. Identifier values are
    <strong>not</strong> end-to-end encrypted: they're sent to the server
    in plaintext and encrypted at rest with the same server-held key as
    your calendar URL, so the server can read them. Everything else on a
    connection stays end-to-end encrypted. The same applies to the
    <strong>identifier list</strong> type, which can supply the highlight
    words of a connection's share link: its entries are readable by the
    server because it needs them to compute that link's availability. The
    <strong>yes/no</strong> type can likewise drive a share link's
    “bypass do not disturb” switch, and is stored the same way.
  </p>
  <p class="mb-4">
    An identifier or identifier list field can also be marked as holding
    <strong>VRChat user IDs</strong>. If you connect your own VRCX Headless server
    in Settings, the server reads those IDs, asks your VRCX Headless server for the
    matching VRChat names, profile pictures and the time you've spent in
    the same instances, and keeps the results so totals can build up over
    time. This is not end-to-end encrypted either: it's stored encrypted
    at rest with the same server-held key, and your VRCX Headless address and API
    key are stored that way too. The key is shown back to you in Settings
    (masked until you reveal it, like your calendar URL) but is never
    included in your data export. The Discord user ID that VRCX reports for a friend (when VRChat has ever shown one) is stored the same way and shown on
    their VRChat card; you can also pick a custom field for Discord user IDs. If your VRCX key can read
    your private VRChat notes, the server holds each note (encrypted at rest with the server's key, not end-to-end) only until you press
    Import: your browser then appends it to the connection's end-to-end encrypted notes and the server keeps just a fingerprint. If you switch on the VRChat friends list, the server also keeps your VRChat friend list (names
    and pictures, encrypted at rest) to show who has no connection yet; the connections themselves
    are only ever created by your browser when you choose to add a friend, so their names stay
    end-to-end encrypted.
  </p>
  <p class="mb-4">
    If you connect your Discord account in Settings, the server holds the access Discord gives it
    (permission to read your identity and your friends list, nothing else) and uses it to fetch your
    friends. That is not end-to-end encrypted either: the access tokens and the friends the server has seen
    (their Discord user IDs, names, your own nicknames for them and avatars) are stored encrypted at rest
    with the same server-held key, and the tokens are never included in your data export. As with VRChat friends, only
    your browser creates a connection or adds a Discord user ID to one, when you choose, so connection names stay
    end-to-end encrypted. Disconnecting revokes the access at Discord and forgets the tokens.
  </p>

  <h2 class="h5">Account security</h2>
  <p class="mb-2">
    Optional two-factor authentication is available for your login
    &mdash; an authenticator app (TOTP), a passkey, or both, in which
    case you pick whichever you like at each login. Passkeys work the
    way they do everywhere else: your device keeps the private key and
    never shares it; we store only the matching public key, which can't
    be used to log in. You can turn either on from your account's
    security settings.
  </p>
  <p class="mb-4">
    An email address is optional. If you set one, it's only ever used
    to fetch your Gravatar avatar (only an MD5 hash of it is sent to
    Gravatar, never the address itself) and, if you like, as an
    alternate way to log in alongside your name. It's stored
    encrypted at rest, the same as your calendar URL above.
  </p>

  <h2 class="h5">Exporting and deleting your data</h2>
  <p class="mb-2">
    You can download a copy of everything tied to your account from your
    account page, after re-confirming your master password. Some fields
    (your name, your calendar URL, two-factor recovery codes) are included
    in plain text, because the server can already read them day to day —
    the download flags these clearly since they're sensitive on their own
    terms. Your Connections CRM data stays encrypted
    in the download exactly as they're stored; a plaintext instructions
    file explains how to decrypt them yourself, offline, using your master
    password. This is rate-limited to 5 downloads per day.
  </p>
  <p class="mb-4">
    Deleting your account also requires re-confirming your master password,
    logs you out immediately, and is final from your side — there's no way
    to log back in and undo it. We keep the underlying records soft-deleted
    (inaccessible, but not yet erased) for 48 hours before a background
    process permanently deletes them; that window exists purely as an
    internal safety margin, not as a user-facing recovery option.
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
