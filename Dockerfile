# syntax=docker/dockerfile:1

#
# Two stages: one that has every build tool, one that has none of them.
#
# The build stage needs PHP *and* Node in the same image, which is unusual and
# not avoidable here: the Wayfinder Vite plugin shells out to `php artisan
# wayfinder:generate` while Vite is running, so a Node-only stage would build an
# app whose typed route helpers are missing. FrankenPHP's image is the one that
# already has the right PHP, so Node is added to it rather than the other way
# round.
#
# **PHP 8.4, not the 8.3 that `composer.json` asks for.** The lock file resolved
# to Symfony 8, which requires 8.4, so an 8.3 image cannot install this project
# at all — `composer install` refuses before a single file is written. Match the
# machine the lock was built on.
#
FROM dunglas/frankenphp:1-php8.4 AS build

# `install-php-extensions` comes with the FrankenPHP image and pulls in the
# system libraries each extension needs, which `docker-php-ext-install` does not.
RUN install-php-extensions pdo_sqlite mbstring intl zip opcache gmp

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl ca-certificates git unzip \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dependencies first, and by themselves: they change far less often than the
# code, so an edit to a Vue file rebuilds in seconds instead of re-resolving
# every package.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# `.npmrc` sets ignore-scripts, which is the project's rule and is honoured here
# too — it is copied before the install rather than after.
COPY package.json package-lock.json .npmrc ./
RUN npm ci

COPY . .

# Artisan boots the whole framework to generate the route helpers, and it will
# not boot without an `.env`. This one never reaches the final image; the
# container makes its own at startup.
#
# The autoloader has to come first: `composer install` above ran with
# `--no-autoloader` (the app was not copied in yet), so until this line there is
# no `vendor/autoload.php` and `artisan` cannot start at all.
#
# `storage` is not in the build context — it is the host's, and the crawler's
# page cache in it runs to gigabytes — so the skeleton the framework insists on
# has to be made here as well as at runtime. Without it `artisan` cannot boot
# far enough to answer the Wayfinder plugin, and Vite fails with a stack trace
# that says nothing about directories.
RUN mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && composer dump-autoload --optimize --no-dev \
    && cp .env.example .env \
    && php artisan key:generate --force \
    && npm run build \
    && rm -rf node_modules .env

#
# The image that actually runs. No Node, no Composer, no build cache.
#
FROM dunglas/frankenphp:1-php8.4 AS app

# `gmp` is for Web Push: the VAPID signature is elliptic-curve arithmetic, and
# without it the library falls back to a pure-PHP big-integer implementation
# that works and is markedly slower per notification.
RUN install-php-extensions pdo_sqlite mbstring intl zip opcache gmp

# curl is the health check, and the health check is what the scheduler waits on
# before it starts touching the same database.
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*

# Opcache settings for a long-running server: the code cannot change under it,
# so nothing needs to be re-checked on every request.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf '%s\n' \
        'opcache.enable=1' \
        'opcache.validate_timestamps=0' \
        'opcache.memory_consumption=192' \
        'memory_limit=512M' \
        > "$PHP_INI_DIR/conf.d/kitchen.ini"

WORKDIR /app

COPY --from=build /app /app
COPY docker/entrypoint.sh /usr/local/bin/kitchen-entrypoint
RUN chmod +x /usr/local/bin/kitchen-entrypoint

# The web root, the writable directories and the database all live outside the
# code, so the image itself never needs to be written to.
ENV DB_DATABASE=/data/database.sqlite

EXPOSE 8001

ENTRYPOINT ["kitchen-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
