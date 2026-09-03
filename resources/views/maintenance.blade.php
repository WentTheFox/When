<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name') }}</title>
    {{-- No csrf-token meta here (unlike app.blade.php) — this view renders from
         inside the exception handler while the request is still in maintenance
         mode (Errors/Maintenance.vue), before the 'web' middleware group (and
         therefore StartSession) has ever run. Calling csrf_token() at that
         point throws — this page has no forms anyway, so it's simply omitted
         rather than worked around. --}}
    <meta name="robots" content="noindex, nofollow, noarchive">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <meta name="theme-color" content="#6181b6">
    @googlefonts
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
