# Laravel app image (FrankenPHP = Caddy + PHP). One image, four roles: app | worker | scheduler | migrate.
ARG FRANKENPHP_IMAGE=dunglas/frankenphp:1.13.5-php8.5-trixie

FROM ${FRANKENPHP_IMAGE} AS base
RUN install-php-extensions pdo_pgsql pgsql redis intl pcntl bcmath zip opcache \
 && apt-get update && apt-get install -y --no-install-recommends procps curl \
 && rm -rf /var/lib/apt/lists/*
COPY docker/php/php-production.ini /usr/local/etc/php/conf.d/zz-app.ini
WORKDIR /app

FROM base AS vendor
COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
 && rm -rf tests docker .env .env.* node_modules storage/logs/* bootstrap/cache/*.php database/database.sqlite

FROM base AS app
ARG APP_UID=10001
RUN useradd --system --uid ${APP_UID} --user-group --home /app app \
 # rootless FrankenPHP: drop the bind capability and give it writable Caddy dirs
 && setcap -r /usr/local/bin/frankenphp || true \
 && mkdir -p /data/caddy /config/caddy && chown -R app:app /data /config
COPY --from=vendor --chown=app:app /app /app
COPY docker/php/Caddyfile /etc/frankenphp/Caddyfile.app
COPY --chmod=0755 docker/php/entrypoint.sh /usr/local/bin/app-entrypoint
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/private/voice bootstrap/cache \
 && chown -R app:app storage bootstrap/cache && chmod -R 0750 storage

USER app
ENV APP_ENV=production APP_DEBUG=false XDG_CONFIG_HOME=/config XDG_DATA_HOME=/data
EXPOSE 8080
ENTRYPOINT ["app-entrypoint"]
CMD ["app"]
