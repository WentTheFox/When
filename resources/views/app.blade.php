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
