#!/usr/bin/env bash
set -Eeuo pipefail

for setting in STAGING_URL DEPLOY_HOST DEPLOY_KNOWN_HOSTS DEPLOY_PATH DEPLOY_PORT DEPLOY_SSH_KEY DEPLOY_USER; do
    if [[ -z "${!setting:-}" ]]; then
        printf 'Missing staging setting: %s\n' "$setting" >&2
        exit 1
    fi
done

[[ "$STAGING_URL" =~ ^https://[a-zA-Z0-9.-]+(:[0-9]+)?/?$ ]]
[[ "$DEPLOY_HOST" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]*$ ]]
[[ "$DEPLOY_USER" =~ ^[a-zA-Z_][a-zA-Z0-9_-]*$ ]]
[[ "$DEPLOY_PORT" =~ ^[1-9][0-9]{0,4}$ ]]
(( DEPLOY_PORT <= 65535 ))
[[ "$DEPLOY_PATH" =~ ^(/[a-zA-Z0-9_][a-zA-Z0-9_.-]*)+$ ]]

main_sha=$(gh api "repos/$GITHUB_REPOSITORY/git/ref/heads/main" --jq '.object.sha')
if [[ "$GITHUB_SHA" != "$main_sha" ]]; then
    echo 'Skipping deployment because the tested revision is no longer the latest on main.'
    exit 0
fi

umask 077
ssh_dir=$(mktemp -d)
trap 'rm -rf -- "$ssh_dir"' EXIT
printf '%s\n' "$DEPLOY_SSH_KEY" > "$ssh_dir/key"
printf '%s\n' "$DEPLOY_KNOWN_HOSTS" > "$ssh_dir/known_hosts"

ssh_options=(-i "$ssh_dir/key" -p "$DEPLOY_PORT"
    -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes
    -o "UserKnownHostsFile=$ssh_dir/known_hosts" -o ConnectTimeout=10)
target="$DEPLOY_USER@$DEPLOY_HOST"
release="$DEPLOY_PATH/releases/$GITHUB_SHA-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT"

ssh "${ssh_options[@]}" "$target" bash -s -- "$DEPLOY_PATH" "$release" <<'PREPARE'
set -Eeuo pipefail
umask 0002
root="$1"
release="$2"
test -f "$root/shared/.env"
command -v composer
command -v rsync
php -r 'exit(PHP_VERSION_ID >= 80500 ? 0 : 1);'
mkdir -p "$root/releases" "$root/shared/storage"
mkdir "$release"
PREPARE

printf -v rsync_ssh '%q ' ssh "${ssh_options[@]}"
rsync -az --no-owner --no-group \
    --exclude='/.git/' --exclude='/.github/' --exclude='/.env*' \
    --exclude='/node_modules/' --exclude='/storage/' \
    --exclude='/bootstrap/cache/*' --exclude='/public/hot' \
    --exclude='/public/fonts-manifest.dev.json' --exclude='/database/*.sqlite*' \
    -e "$rsync_ssh" ./ "$target:$release/"

ssh "${ssh_options[@]}" "$target" bash -s -- "$DEPLOY_PATH" "$release" <<'ACTIVATE'
set -Eeuo pipefail
umask 0002
root="$1"
release="$2"
cd "$release"
ln -s "$root/shared/.env" .env
ln -s "$root/shared/storage" storage
mkdir -p bootstrap/cache storage/app/public storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs
chmod g+w bootstrap/cache

composer check-platform-reqs --no-dev --no-interaction
php -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (! $app->environment("staging") || config("app.debug") || ! config("app.key")) {
        fwrite(STDERR, "Expected APP_ENV=staging, APP_DEBUG=false and an APP_KEY.\n");
        exit(1);
    }
    if (config("database.default") === "sqlite") {
        $db = realpath((string) config("database.connections.sqlite.database"));
        $shared = realpath($argv[1]);
        if ($db === false || $shared === false || ! str_starts_with($db, $shared."/") || ! is_file($db)) {
            fwrite(STDERR, "SQLite must use an existing database file under shared/.\n");
            exit(1);
        }
    }
' "$root/shared"

php artisan migrate --force --no-interaction
php artisan optimize --no-interaction

# Only switch the web root once dependencies, migrations and caches are ready.
ln -s "$release" "$release/.current"
mv -Tf "$release/.current" "$root/current"
ACTIVATE

status=$(curl --fail --silent --show-error --max-time 15 \
    --retry 5 --retry-all-errors --retry-delay 3 --output /dev/null \
    --write-out '%{http_code}' "${STAGING_URL%/}/up")
[[ "$status" == 200 ]]
printf 'Staging is available at %s\n' "$STAGING_URL"
