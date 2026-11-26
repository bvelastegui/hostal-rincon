# Hostal Rincón de escritorio

## Ejecutar desde el código fuente

Desde la raíz del repositorio, con PHP 8.5, Composer, Node.js y npm:

    composer install
    npm ci
    npm run build
    cd desktop
    npm ci --ignore-scripts=false
    npm run dev

Electron ejecuta Laravel con el PHP del sistema y usa un perfil de desarrollo separado. En Linux puedes abrir un perfil vacío mediante:

    XDG_CONFIG_HOME="$(mktemp -d)" npm run dev

## Primer arranque

En **Configuración inicial**, elige **Empezar desde cero** o **Restaurar desde un respaldo**. Si recuperas un ZIP generado en **Ajustes → Respaldos**, inicia sesión con tus credenciales anteriores. Para restaurarlo, usa una versión igual o posterior a la que lo generó.

## Empaquetar para Windows x64

Desde este directorio, con Git, unzip y rsync disponibles:

    bash preparar-runtime.sh
    ELECTRON_SKIP_BINARY_DOWNLOAD=1 npm ci
    bash frankenphp/preparar-runtime.sh
    npm run build:frankenphp

El instalador se genera en **dist/frankenphp-entrega/**. Para empaquetar una revisión concreta, pásala como argumento a **preparar-runtime.sh**. Las revisiones que usan el icono SVG requieren librsvg e ImageMagick. Si el Wine integrado de electron-builder falla en Arch Linux, puedes definir **ELECTRON_BUILDER_WINE_TOOLSET_DIR** con un directorio que contenga **bin/wine**, **lib** y **wine-home**.

## Empaquetar para Linux x64

    bash preparar-runtime.sh --linux
    npm run build:linux

El paquete AppImage se genera en **dist/**. Si guardaste el ZIP del intérprete PHP de Linux en otra ruta, indica su ubicación con **HOSTAL_LINUX_PHP_ARCHIVE** antes de preparar el runtime.
