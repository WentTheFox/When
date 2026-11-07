<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Discord OAuth2 (identify + relationships.read) for the friends import. The app needs Discord's Social SDK
    // approval for relationships.read. The redirect URI must be registered in the Developer Portal exactly as
    // it is here (default: the app's own settings.discord.callback route).
    'discord' => [
        'client_id' => env('DISCORD_CLIENT_ID'),
        'client_secret' => env('DISCORD_CLIENT_SECRET'),
        'redirect' => env('DISCORD_REDIRECT_URI'),
        // The app's bot token: only used to look up a user by id (GET /users/{id}) for a connection's Discord card.
        'bot_token' => env('DISCORD_BOT_TOKEN'),
    ],

    // Owner calendar URLs are fetched server-side, so CalendarUrlGuard blocks loopback/private/link-local
    // targets and non-standard ports. Enable ONLY in local development (e.g. for /dev/mock-calendar.ics).
    'calendar' => [
        'allow_private_hosts' => (bool) env('CALENDAR_ALLOW_PRIVATE_HOSTS', false),
        // Seconds to establish the connection, and for the whole request (connect + transfer) per redirect hop.
        'connect_timeout' => (int) env('CALENDAR_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('CALENDAR_TIMEOUT', 15),
        // Largest ICS body accepted, in bytes; bigger feeds are aborted mid-download.
        'max_body_bytes' => (int) env('CALENDAR_MAX_BODY_BYTES', 5 * 1024 * 1024),
        // Redirect hops followed (each one re-checked by CalendarUrlGuard).
        'max_redirects' => (int) env('CALENDAR_MAX_REDIRECTS', 3),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
