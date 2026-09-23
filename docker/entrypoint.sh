#!/bin/sh
# M&E Platform — container entrypoint.
#
# Usage (as the container command):
#   php-fpm      serve the app (default)
#   horizon      run the queue supervisor
#   scheduler    run the cron loop
#   <anything>   exec'd verbatim, e.g. `php artisan migrate --force`
set -e

role="${1:-php-fpm}"

# storage/ is a volume in every real deployment, so it mounts over the tree the
# image built. Recreate it on every start rather than assuming the build's.
mkdir -p \
    storage/app/public \
    storage/app/private \
    storage/app/documents \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    storage/media-library/temp \
    bootstrap/cache

[ -e public/storage ] || ln -sfn ../storage/app/public public/storage

# Config and event caches are per-container (bootstrap/cache is image-local).
#
# NOT route:cache — routes/{oversight,tenant,portal}.php register closure
# endpoints, which cannot be serialized, so caching routes fails the boot
# rather than speeding it up.
if [ "${OPTIMIZE:-true}" = "true" ]; then
    php artisan config:cache
    php artisan event:cache

    # Compiled Blade lands in the SHARED storage volume, so only one role
    # warms it — three containers compiling the same files at once interleave
    # writes. The other roles compile on demand, which is safe and idempotent.
    if [ "$role" = "php-fpm" ]; then
        php artisan view:cache
    fi
fi

# Off by default: schema changes on a government system are a deliberate,
# observed step. compose.yml turns it on for the local stack only.
if [ "${RUN_MIGRATIONS:-false}" = "true" ] && [ "$role" = "php-fpm" ]; then
    php artisan migrate --force
fi

# Anything the steps above wrote was written as root; the workers are www-data.
chown -R www-data:www-data storage bootstrap/cache

case "$role" in
    php-fpm)
        exec php-fpm
        ;;
    horizon)
        exec su-exec www-data php artisan horizon
        ;;
    scheduler)
        exec su-exec www-data php artisan schedule:work
        ;;
    *)
        exec su-exec www-data "$@"
        ;;
esac
