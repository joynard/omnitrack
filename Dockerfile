# syntax=docker/dockerfile:1

###############################################################################
# Stage 1: Composer dependency builder
# Installs only production dependencies into an isolated vendor/ directory.
###############################################################################
FROM composer:2 AS vendor

WORKDIR /build

# Copy manifests first so the dependency layer is cached independently of code.
COPY composer.json composer.lock* ./

# --no-scripts: artisan does not exist yet at this point in the build.
# composer.json pins config.platform.php, so resolution targets PHP 8.3 even
# though this stage may run a different Composer/PHP version.
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader \
        --no-scripts

###############################################################################
# Stage 1b: Dev dependency builder (used only by the `testing` target)
###############################################################################
FROM composer:2 AS vendor-dev

WORKDIR /build

COPY composer.json composer.lock* ./

RUN composer install \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader \
        --no-scripts

###############################################################################
# Stage 2: PHP extension builder
#
# Compiles the extensions on a full toolchain. This stage is thrown away, so
# the build headers never reach the runtime image -- and, crucially, the
# runtime libraries (libpng, freetype, libjpeg) are never removed, which a
# naive `apk del <x>-dev` in a single stage would do, silently breaking gd.
###############################################################################
FROM php:8.3-fpm-alpine AS extensions

RUN apk add --no-cache \
        --virtual .build-deps \
        $PHPIZE_DEPS \
        postgresql-dev \
        libzip-dev \
        icu-dev \
        oniguruma-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo \
        pdo_pgsql \
        pgsql \
        zip \
        intl \
        bcmath \
        gd \
        opcache \
        pcntl

###############################################################################
# Stage 3: Runtime image (PHP-FPM + Nginx + Supervisor in one container)
###############################################################################
FROM php:8.3-fpm-alpine AS runtime

# Runtime libraries only. These stay installed for good -- gd.so links against
# libpng/libjpeg/freetype at load time.
RUN apk add --no-cache \
        nginx \
        supervisor \
        libpq \
        libzip \
        icu-libs \
        oniguruma \
        libpng \
        libjpeg-turbo \
        freetype \
        zip \
        unzip \
        curl

# Copy the compiled extensions from the builder stage.
COPY --from=extensions /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=extensions /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

# Production OPcache + PHP settings.
COPY docker/php.ini /usr/local/etc/php/conf.d/99-omnitrack.ini

# PHP-FPM must inherit container environment variables (Laravel reads config
# from env). The official image sets clear_env = yes by default, which would
# silently strip DB_*, DEEPSEEK_* and HARNESS_* inside the FPM workers.
RUN printf '[global]\nerror_log = /proc/self/fd/2\n\n[www]\nclear_env = no\ncatch_workers_output = yes\ndecorate_workers_output = no\naccess.log = /proc/self/fd/2\n' \
        > /usr/local/etc/php-fpm.d/zz-omnitrack.conf

WORKDIR /var/www/html

# Application code and production dependencies.
COPY . .
COPY --from=vendor /build/vendor ./vendor

# Nginx and Supervisor configuration.
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

# The host .env must never be baked into the image: it would silently override
# every platform-provided variable (Koyeb secrets) with stale local values.
# Configuration comes from the environment at runtime instead.
RUN rm -f /var/www/html/.env

# Prepare writable paths owned by www-data, then make the entrypoint runnable.
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p \
        storage/app/google \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && rm -f bootstrap/cache/*.php \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

# Fail the build early if an extension did not survive the copy.
RUN php -m | grep -q pdo_pgsql \
    && php -m | grep -q zip \
    && php -m | grep -q intl \
    && php -m | grep -q gd \
    && php -m | grep -q pcntl \
    && echo "All required PHP extensions loaded."

# Koyeb and docker-compose both reach the container on 8080.
EXPOSE 8080

# Supervisor is PID 1: it manages nginx, php-fpm and the queue worker.
CMD ["/usr/local/bin/entrypoint.sh"]

###############################################################################
# Stage 4 (target: testing): the runtime image plus dev dependencies.
#
# Used by docker-compose.test.yml so `php artisan test` can run without
# shipping PHPUnit and Faker inside the production image.
#   docker build --target testing -t omnitrack-engine:test .
###############################################################################
FROM runtime AS testing

COPY --from=vendor-dev /build/vendor ./vendor

# NOTE: no CMD override here on purpose. Setting one would also be inherited by
# any earlier stage that gets tagged with the same image name, which previously
# caused the server image to boot `php artisan test`. The test command is
# supplied by docker-compose.test.yml instead.

