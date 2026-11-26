#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."
archive=${1:-}
staging=$(mktemp -d)
trap 'rm -rf -- "$staging"' EXIT

if [[ -z "$archive" ]]; then
    archive="$staging/frankenphp.zip"
    curl --fail --location --output "$archive" \
        https://github.com/php/frankenphp/releases/download/v1.13.4/frankenphp-windows-x86_64.zip
else
    archive=$(realpath "$archive")
fi
printf '%s  %s\n' \
    22e92d1465437480ca3b6c0618a6f1d2377f7ecd5ae212ba705ed035478d72d1 "$archive" |
    sha256sum --check
unzip -q "$archive" -d "$staging/frankenphp"

test -f runtime/web/public/build/manifest.json
test -f runtime/web/composer.lock
mkdir -p "$staging/web"
rsync -a --exclude=node_modules --exclude=.env --exclude=/storage --exclude=/public/hot \
    runtime/web/ "$staging/web/"
(
    cd "$staging/web"
    composer require laravel/octane:2.26.0 --no-install --no-scripts --no-interaction --minimal-changes
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
    cp vendor/laravel/octane/src/Commands/stubs/frankenphp-worker.php public/frankenphp-worker.php
)

rsync -a --delete "$staging/frankenphp/" runtime/frankenphp/
rsync -a --delete "$staging/web/" runtime/octane-web/
