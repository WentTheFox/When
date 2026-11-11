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
  `DISCORD_CLIENT_SECRET` `DISCORD_REDIRECT_URI` `DISCORD_BOT_TOKEN` `VITE_APP_NAME` (build-time only, default `When`).
  Secrets: `APP_KEY` (encrypts stored calendar URLs; losing it makes them unreadable), `DB_PASSWORD`, `REDIS_PASSWORD`,
  `DISCORD_CLIENT_SECRET`, `DISCORD_BOT_TOKEN`, `MAIL_PASSWORD`.
- **External services**: Postgres database `whenthefox` (role `whenthefox_app`), Valkey (queues/Horizon; set `REDIS_PREFIX` and
  `HORIZON_PREFIX` per ACL user), outbound HTTPS to owners' calendar (ICS) URLs and fonts.googleapis.com, SMTP if mail is enabled,
  Discord OAuth.
