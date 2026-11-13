# Running When in the Rathalos cluster

Images (built by `.github/workflows/images.yml` on every push to `main`, multi-arch, tags `sha-<sha7>` only):
`ghcr.io/wentthefox/when-fpm` (php-fpm 8.5, also used for Horizon, scheduler and migrations) and
`ghcr.io/wentthefox/when-web` (nginx + `public/`; nginx config lives in the Rathalos repo).

- **Web pod**: `when-fpm` (FastCGI `127.0.0.1:9000`) + `when-web` (nginx, listen port decided by the Rathalos config). nginx must
  serve `/storage/` from the shared volume's `storage/app/public` (there is no `public/storage` symlink) and send PHP to fpm.
- **Health**: `GET /status` (send `Accept: application/json`) is 200 when Postgres and the cache store answer, else 503. It stays
  200 during the read-only window.
- **Horizon**: separate Deployment, same fpm image, `php artisan horizon` (stop with `horizon:terminate`, SIGTERM is fine). 1 replica.
- **Scheduler**: separate Deployment, `php artisan schedule:work`, **1 replica, strategy Recreate** (only `HardDeleteExpiredAccounts`, hourly).
- **Migrations**: a Job/hook running `php artisan migrate --force && php artisan google-fonts:fetch` (the second step caches the
  Google Fonts into `storage/app/public/fonts`; if it fails the app falls back to the Google CDN link, so it is non-critical).
  Migrations must stay expand-only (see `docs/zero-downtime-deploy.md`).
- **Volume**: one shared PVC mounted at `/var/www/html/storage` in web (fpm, and read-only for nginx at `storage/app/public`),
  Horizon, scheduler and the migrate job. Holds fonts cache, logs (`availability.log` is a file channel), framework cache dirs and
  the read-only flag file. The container entrypoint creates the missing skeleton directories. Runs as `www-data` (uid 33).
- **No `config:cache`/`optimize`** in the image: config comes from env at runtime. Routes/views/events are cached at build.
- **Env var names** (values in a ConfigMap/Secret, never in git): `APP_NAME` `APP_ENV` `APP_KEY` `APP_DEBUG` `APP_URL` `APP_LOCALE`
  `APP_FALLBACK_LOCALE` `LOG_CHANNEL` (`stderr`) `LOG_LEVEL` `LOG_AVAILABILITY_LEVEL` `DB_CONNECTION` `DB_HOST` `DB_PORT` `DB_DATABASE`
  `DB_USERNAME` `DB_PASSWORD` `SESSION_DRIVER` `SESSION_LIFETIME` `SESSION_ENCRYPT` `SESSION_DOMAIN` `QUEUE_CONNECTION` `CACHE_STORE`
  `REDIS_CLIENT` `REDIS_HOST` `REDIS_PORT` `REDIS_USERNAME` `REDIS_PASSWORD` `REDIS_PREFIX` `HORIZON_PREFIX` `MAIL_*` `DISCORD_CLIENT_ID`
  `DISCORD_CLIENT_SECRET` `DISCORD_REDIRECT_URI` `DISCORD_BOT_TOKEN` `TELEGRAM_SIDECAR_URL` `TELEGRAM_SIDECAR_TOKEN` `VITE_APP_NAME` (build-time only, default `When`).
  Secrets: `APP_KEY` (encrypts stored calendar URLs; losing it makes them unreadable), `DB_PASSWORD`, `REDIS_PASSWORD`,
  `DISCORD_CLIENT_SECRET`, `DISCORD_BOT_TOKEN`, `TELEGRAM_SIDECAR_TOKEN`, `MAIL_PASSWORD`.
- **Telegram sidecar (optional, off by default)**: Telegram contacts need a logged-in user account (no OAuth-style permission exists), which
  the PHP side does not speak, so a small Telethon service does. It lives in its own repo and image,
  [WentTheFox/telethon-docker](https://github.com/WentTheFox/telethon-docker) (`ghcr.io/wentthefox/telethon-docker`, multi-arch, built by that
  repo's CI), and knows nothing about When. Skip it entirely and the feature stays hidden: Telegram is on only when **both**
  `TELEGRAM_SIDECAR_URL` and `TELEGRAM_SIDECAR_TOKEN` are set on the fpm/Horizon pods.
  - Sidecar env: `TELEGRAM_API_ID`, `TELEGRAM_API_HASH` (an app registered at https://my.telegram.org), `TELEGRAM_SIDECAR_TOKEN` (the same
    value as on the app side), optional `PORT` (default 8080). It refuses to start without the first three. Probe: `GET /healthz`.
  - When side env: `TELEGRAM_SIDECAR_URL` (the sidecar's in-cluster Service URL, port 8080), `TELEGRAM_SIDECAR_TOKEN`.
  - **Stateless, any replica count, no volume.** Every request carries the owner's session string and the reply carries it back; nothing is
    written to disk. Keep it reachable only from the When pods (a NetworkPolicy): it handles phone numbers, login codes and full-account
    sessions, so the bearer token is the only thing standing between the network and them. It needs outbound access to Telegram (TCP 443/80
    to the Telegram data centres, no inbound from the internet) and no database.
  - Secrets: `TELEGRAM_API_HASH`, `TELEGRAM_SIDECAR_TOKEN`. The session strings live in Postgres encrypted with `APP_KEY`, like the Discord tokens.
  - **The `/v1/*` contract is shared between the two repos.** When's side is `app/Services/Telegram/TelegramClient.php` (and the faked
    responses in `tests/Feature/TelegramIntegrationTest.php`). Routes: `POST /v1/login/start|code|password`, `/v1/contacts`, `/v1/photos`,
    `/v1/logout`. `/v1/photos` answers `{photos, checked, retry_after?, session}`: `checked` is what Telegram was actually asked about, which
    is how When tells "no photo to give" from "not reached yet". When a change to any of these is needed, change the sidecar repo too and
    **roll the sidecar before the app**: a newer sidecar works with an older app, not the reverse.
  - Timeouts: When waits up to 90s per sidecar call and a sync makes several, so nginx's `fastcgi_read_timeout` and any ingress read timeout
    should allow a few minutes (the background job has a 180s timeout).
- **External services**: Postgres database `whenthefox` (role `whenthefox_app`), Valkey (queues/Horizon; set `REDIS_PREFIX` and
  `HORIZON_PREFIX` per ACL user), outbound HTTPS to owners' calendar (ICS) URLs and fonts.googleapis.com, SMTP if mail is enabled,
  Discord OAuth, and (only when enabled) the Telegram sidecar.
