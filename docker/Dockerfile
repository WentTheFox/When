# When: one build, two runtime images (targets: fpm, web). The fpm image also runs Horizon, the scheduler and the
# migrate job (different commands). Environment is runtime configuration only; nothing secret is baked in.
#   docker buildx bake -f docker/docker-bake.hcl            (context is the repository root)
ARG PHP_IMAGE=php:8.5-fpm-trixie
ARG NODE_IMAGE=node:24-trixie-slim

# The build stage runs on the builder's own architecture (output is architecture independent: pure PHP vendor tree,
# built JS/CSS), so multi-arch builds only emulate the stages that really ship.
FROM --platform=$BUILDPLATFORM ${PHP_IMAGE} AS php-base-build
COPY --from=ghcr.io/mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions bcmath intl opcache pcntl pdo_pgsql redis igbinary zip \
 && rm -rf /var/lib/apt/lists/* /tmp/*

FROM ${PHP_IMAGE} AS php-base
COPY --from=ghcr.io/mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions bcmath intl opcache pcntl pdo_pgsql redis igbinary zip \
 && rm -rf /var/lib/apt/lists/* /tmp/*

FROM --platform=$BUILDPLATFORM ${NODE_IMAGE} AS node-bin
FROM --platform=$BUILDPLATFORM composer:2 AS composer-bin

# ---------------------------------------------------------------------------------------------------------
FROM php-base-build AS build
ARG GIT_COMMIT_HASH=unknown
ARG GIT_COMMIT_DATE=unknown
ARG APP_NAME=When
ENV GIT_COMMIT_HASH=${GIT_COMMIT_HASH} GIT_COMMIT_DATE=${GIT_COMMIT_DATE} COMPOSER_ALLOW_SUPERUSER=1 CI=true \
    APP_NAME=${APP_NAME} VITE_APP_NAME=${APP_NAME}
COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
COPY --from=node-bin /usr/local/bin/node /usr/local/bin/node
COPY --from=node-bin /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
 && npm install -g pnpm@11 \
 && apt-get update && apt-get install -y --no-install-recommends git unzip \
 && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./
RUN pnpm install --frozen-lockfile
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts
# dummy env only so artisan can boot while building; real env is injected at runtime (no config:cache on purpose)
RUN APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= APP_ENV=production \
    sh -c 'php artisan package:discover --ansi && pnpm run build && php artisan route:cache && php artisan view:cache && php artisan event:cache'

# runtime tree for the fpm image: everything the app needs at runtime, minus node tooling and tests
RUN mkdir /runtime && cp -a /app/. /runtime/ \
 && rm -rf /runtime/node_modules /runtime/tests /runtime/.git /runtime/docs /runtime/.env /runtime/storage/logs/* /runtime/storage/framework/views/*

# ---------------------------------------------------------------------------------------------------------
FROM php-base AS fpm
RUN apt-get update && apt-get install -y --no-install-recommends postgresql-client && rm -rf /var/lib/apt/lists/*
RUN { echo "memory_limit = 256M"; echo "expose_php = Off"; \
      echo "upload_max_filesize = 8M"; echo "post_max_size = 10M"; echo "zend.exception_ignore_args = Off"; \
      echo "session.upload_progress.enabled = 0"; \
      echo "opcache.enable=1"; echo "opcache.validate_timestamps=0"; echo "opcache.memory_consumption=96"; \
      echo "opcache.max_accelerated_files=20000"; } > /usr/local/etc/php/conf.d/99-app.ini
COPY docker/www.conf /usr/local/etc/php-fpm.d/zz-app.conf
WORKDIR /var/www/html
COPY --from=build --chown=www-data:www-data /runtime/ ./
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 9000
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------------------------------------
FROM nginx:1-alpine-slim AS web
# static assets + index.php only; PHP itself runs in the fpm container of the same pod (same absolute paths).
# /storage/ (storage/app/public, incl. the Google Fonts cache) comes from the shared volume mounted by the pod.
COPY --from=build /app/public /var/www/html/public
