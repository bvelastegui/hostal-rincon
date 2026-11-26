#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"
platform=windows
if [[ ${1:-} == '--linux' ]]; then
    platform=linux
    shift
fi
revision=${1:---workspace}
php_version=8.5.13
php_archive="php-${php_version}-nts-Win32-vs17-x64.zip"
php_sha256=4ae6c72bc0ca4f6b8f3e9b107874bae0135699f3aabe6c26a1f6103d040eea97
staging=$(mktemp -d)
trap 'rm -rf -- "$staging"' EXIT

mkdir -p "$staging/php" "$staging/web" runtime
if [[ "$platform" == 'linux' ]]; then
    php_zip=${HOSTAL_LINUX_PHP_ARCHIVE:-../../desktop-nativephp/nativephp/binaries/main/linux/x64/php-8.5.zip}
    linux_php_sha256=968fb30620410ad85dac2fb07c2be680c36f37e0106c25e65c7c4fc4157ca6b3
    printf '%s  %s\n' "$linux_php_sha256" "$php_zip" | sha256sum --check
    unzip -p "$php_zip" php > "$staging/php/php"
    chmod 755 "$staging/php/php"
    "$staging/php/php" -n -r '
        foreach (["ctype", "dom", "fileinfo", "intl", "mbstring", "openssl", "pdo_sqlite", "sqlite3", "tokenizer"] as $extension) {
            if (!extension_loaded($extension)) { fwrite(STDERR, "Falta la extensión $extension\n"); exit(1); }
        }
    '
    curl --fail --location --output "$staging/php/license.txt" https://www.php.net/license/3_01.txt
else
    curl --fail --location --output "$staging/php.zip" "https://downloads.php.net/~windows/releases/archives/$php_archive" ||
        curl --fail --location --output "$staging/php.zip" "https://downloads.php.net/~windows/releases/$php_archive"
    printf '%s  %s\n' "$php_sha256" "$staging/php.zip" | sha256sum --check
    unzip -q "$staging/php.zip" -d "$staging/php"
    curl --fail --location --output "$staging/vc_redist.x64.exe" https://aka.ms/vc14/vc_redist.x64.exe
fi

if [[ "$revision" == '--workspace' ]]; then
    git -C .. ls-files -z --cached --others --exclude-standard -- . ':(exclude)desktop' |
        rsync -a --from0 --files-from=- ../ "$staging/web/"
else
    git -C .. archive "$revision" | tar --exclude=desktop -x -C "$staging/web"
fi
(
    cd "$staging/web"
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
    npm ci --no-audit --no-fund
    VITE_APP_NAME='Hostal Rincón' npm run build
)
rsvg-convert --width 256 --height 256 "$staging/web/public/favicon.svg" > "$staging/hostal-rincon.png"
rsync -a --delete --exclude=node_modules "$staging/web/" runtime/web/

if [[ "$platform" == 'linux' ]]; then
    rsync -a --delete "$staging/php/" runtime/php-linux/
    cp "$staging/hostal-rincon.png" runtime/
else
    magick "$staging/hostal-rincon.png" -define icon:auto-resize=256,128,64,48,32,16 "$staging/hostal-rincon.ico"
    rsync -a --delete "$staging/php/" runtime/php/
    cp "$staging/vc_redist.x64.exe" "$staging/hostal-rincon.ico" runtime/
fi
