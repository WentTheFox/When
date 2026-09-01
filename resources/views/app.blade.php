<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name') }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- This app has no public content worth surfacing in search results —
         every page is either an owner's private dashboard or a share link
         meant for exactly one named recipient (§0's whole design). Belt and
         braces with public/robots.txt's blanket Disallow, since that alone
         doesn't stop a page already indexed elsewhere from staying listed. --}}
    <meta name="robots" content="noindex, nofollow, noarchive">
    {{-- Not for search (see robots above) — purely so a pasted link renders a
         nice embed in Discord/Slack/etc. Most link-preview scrapers (Discord
         included) use the first og:* tag of each name they find, and
         @inertiaHead's per-page overrides render below this block — so a
         page wanting its own og:title/description/image (e.g. a
         /free/{token} share link) needs those tags moved above this block,
         not just added in its own <Head>. --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ config('app.name') }}">
    <meta property="og:title" content="{{ config('app.name') }}">
    <meta
        property="og:description"
        content="Share your availability with friends for simpler event planning across timezones"
    >
    <meta property="og:image" content="{{ url('/icons/icon-512.png') }}">
    <meta property="og:image:width" content="512">
    <meta property="og:image:height" content="512">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:creator" content="@WentTheFox">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <meta name="theme-color" content="#6181b6">
    {{-- Self-hosted via spatie/laravel-google-fonts (config/google-fonts.php) —
         fetched once, served from our own origin, never a live request to
         Google for every visitor. Same brand font as WentTheNuxt (its
         nuxt.config.ts's googleFonts.families) — see app.css's
         --bs-font-sans-serif override for how it's actually applied. --}}
    @googlefonts
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
