const { app, BrowserWindow, dialog, Menu, session } = require('electron');
const { execFile, spawn } = require('node:child_process');
const { createHash, randomBytes, randomUUID } = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { setTimeout: delay } = require('node:timers/promises');
const { promisify } = require('node:util');

const run = promisify(execFile);
const packaged = app.isPackaged;
const resources = packaged ? process.resourcesPath : __dirname;
const webRoot = packaged
    ? path.join(resources, 'web')
    : path.resolve(__dirname, '..');
const windows = process.platform === 'win32';
const frankenRoot = path.join(resources, 'frankenphp');
const frankenphp = path.join(frankenRoot, 'frankenphp.exe');
const hasFrankenphp = packaged && windows && fs.existsSync(frankenphp);
const useOctane = hasFrankenphp && !process.argv.includes('--comparar-php');
const port = packaged
    ? hasFrankenphp
        ? 18648
        : 18647
    : Number(process.env.HOSTAL_DESKTOP_PORT ?? 18649);
const address = '127.0.0.1:' + port;
const origin = 'http://' + address;
const phpRoot = hasFrankenphp ? frankenRoot : path.join(resources, 'php');
const php = packaged ? path.join(phpRoot, windows ? 'php.exe' : 'php') : 'php';
const phpOptions = hasFrankenphp
    ? []
    : [
          '-d',
          'expose_php=0',
          '-d',
          'variables_order=EGPCS',
          '-d',
          'upload_max_filesize=64M',
          '-d',
          'post_max_size=65M',
      ];
if (packaged && !hasFrankenphp) phpOptions.unshift('-n');
if (packaged && windows && !hasFrankenphp) {
    phpOptions.push(
        '-d',
        'extension_dir=' + path.join(phpRoot, 'ext'),
        ...[
            'mbstring',
            'openssl',
            'fileinfo',
            'intl',
            'pdo_sqlite',
            'sqlite3',
        ].flatMap((extension) => ['-d', 'extension=' + extension]),
    );
}
let window;
let splash;
let server;
let leaving = false;

async function showSplash() {
    splash = new BrowserWindow({
        width: 510,
        height: 310,
        frame: false,
        resizable: false,
        maximizable: false,
        closable: false,
        show: false,
        title: 'Hostal Rincón',
        icon: path.join(webRoot, 'public', 'favicon.ico'),
        webPreferences: {
            sandbox: true,
            contextIsolation: true,
            devTools: false,
        },
    });
    splash.setMenuBarVisibility(false);
    await splash.loadFile(path.join(__dirname, 'frankenphp', 'splash.html'));
    const logo = fs.readFileSync(
        path.join(webRoot, 'public', 'apple-touch-icon.png'),
        'base64',
    );
    await splash.webContents.executeJavaScript(`
        document.querySelector('.brand img').src = ${JSON.stringify('data:image/png;base64,' + logo)};
    `);
    splash.show();
}

async function showStep(step, detail) {
    if (!splash) return;
    await splash.webContents.executeJavaScript(`(() => {
        document.querySelectorAll('li').forEach((item, index) => {
            item.classList.toggle('done', index < ${step});
            if (index === ${step}) item.setAttribute('aria-current', 'step');
            else item.removeAttribute('aria-current');
        });
        const active = document.querySelector('[aria-current="step"] .step-label');
        const detail = ${JSON.stringify(detail ?? '')};
        if (detail) active.textContent = detail;
    })()`);
}

function configureCache(storage, options) {
    const { env } = options;
    const packageFile = fs.statSync(path.join(resources, 'app.asar'));
    const cacheVersion = createHash('sha256')
        .update(
            JSON.stringify([
                app.getVersion(),
                packageFile.mtimeMs,
                webRoot,
                env.APP_KEY,
            ]),
        )
        .digest('hex')
        .slice(0, 20);
    const cache = path.join(storage, 'framework', 'optimize', cacheVersion);
    const marker = path.join(cache, 'ready');
    const views = path.join(cache, 'views');
    const files = {
        APP_CONFIG_CACHE: path.join(cache, 'config.php'),
        APP_EVENTS_CACHE: path.join(cache, 'events.php'),
        APP_ROUTES_CACHE: path.join(cache, 'routes-v7.php'),
    };
    const hasFiles = () =>
        Object.values(files).every((filename) => fs.existsSync(filename)) &&
        fs.existsSync(views) &&
        fs.readdirSync(views).some((filename) => filename.endsWith('.php'));

    Object.assign(env, files, { VIEW_COMPILED_PATH: views });
    if (fs.existsSync(marker) && hasFiles()) return;

    fs.mkdirSync(views, { recursive: true });
    fs.rmSync(marker, { force: true });
    // A retry may find an incomplete set of caches from an interrupted startup.
    for (const filename of Object.values(files))
        fs.rmSync(filename, { force: true });

    return async () => {
        const { stdout } = await run(
            php,
            [
                ...phpOptions,
                'artisan',
                'optimize',
                '--no-interaction',
                '--no-ansi',
            ],
            options,
        );
        if (stdout.includes('FAIL') || !hasFiles()) {
            throw new Error(
                'No se pudo preparar la caché de la aplicación. Cierra y vuelve a abrir Hostal Rincón.',
            );
        }
        fs.writeFileSync(marker, '');
    };
}

async function start() {
    if (hasFrankenphp) await showSplash();
    const data = app.getPath('userData');
    const storage = path.join(data, 'storage');
    for (const folder of [
        'framework/cache/data',
        'framework/sessions',
        'framework/views',
        'logs',
    ]) {
        fs.mkdirSync(path.join(storage, folder), { recursive: true });
    }

    const database = path.join(data, 'hostal.sqlite');
    const key = path.join(data, 'clave-app');
    if (!fs.existsSync(key)) {
        if (fs.existsSync(database)) {
            throw new Error(
                'Falta la clave de la base existente. Restaura el archivo clave-app desde tu copia.',
            );
        }
        fs.writeFileSync(key, 'base64:' + randomBytes(32).toString('base64'), {
            flag: 'wx',
            mode: 0o600,
        });
    }
    if (!fs.existsSync(database)) {
        fs.closeSync(fs.openSync(database, 'wx', 0o600));
    }

    const deviceId = path.join(data, 'device-id');
    if (!fs.existsSync(deviceId)) {
        fs.writeFileSync(deviceId, randomUUID(), { flag: 'wx', mode: 0o600 });
    }

    const token = randomBytes(32).toString('hex');
    const env = {
        ...process.env,
        APP_ENV: 'production',
        APP_NAME: 'Hostal Rincón',
        APP_DEBUG: 'false',
        APP_KEY: fs.readFileSync(key, 'utf8'),
        APP_URL: origin,
        APP_LOCALE: 'es_EC',
        APP_FALLBACK_LOCALE: 'es_EC',
        DB_CONNECTION: 'sqlite',
        DB_DATABASE: database,
        LARAVEL_STORAGE_PATH: storage,
        SESSION_DRIVER: 'file',
        SESSION_SECURE_COOKIE: 'false',
        CACHE_STORE: 'file',
        QUEUE_CONNECTION: 'sync',
        LOG_CHANNEL: 'single',
        MAIL_MAILER: 'array',
        HOSTAL_DESKTOP_TOKEN: token,
        HOSTAL_DESKTOP_SETUP: 'true',
        HOSTAL_DESKTOP_VERSION: app.getVersion(),
        HOSTAL_DESKTOP_DEVICE_ID: fs.readFileSync(deviceId, 'utf8').trim(),
        ...(!packaged && {
            APP_CONFIG_CACHE: path.join(storage, 'framework', 'config.php'),
            APP_ROUTES_CACHE: path.join(storage, 'framework', 'routes.php'),
        }),
        ...(hasFrankenphp && {
            PHPRC: path.join(frankenRoot, 'php.ini'),
            PHP_INI_SCAN_DIR: '',
            HOSTAL_PHP_EXTENSIONS: path.join(frankenRoot, 'ext'),
            HOSTAL_DESKTOP_ADDRESS: address,
            HOSTAL_WEB_ROOT: webRoot.replaceAll(path.sep, '/'),
            HOSTAL_CADDY_STORAGE: path
                .join(storage, 'caddy')
                .replaceAll(path.sep, '/'),
        }),
    };
    const options = { cwd: webRoot, env, windowsHide: true };
    let prepareCache;
    if (hasFrankenphp && !process.argv.includes('--sin-cache')) {
        prepareCache = configureCache(storage, options);
    }

    await showStep(1);
    await run(
        php,
        [...phpOptions, 'artisan', 'migrate', '--force', '--no-interaction'],
        options,
    );

    if (prepareCache) {
        await showStep(2);
        await prepareCache();
    } else {
        await showStep(
            2,
            process.argv.includes('--sin-cache')
                ? 'Iniciando sin cachés de Laravel'
                : 'Cachés listas para este inicio',
        );
    }

    await showStep(3);

    const serverCommand = useOctane ? frankenphp : php;
    const serverOptions = useOctane
        ? [
              'run',
              '--config',
              path.join(resources, 'Caddyfile'),
              '--adapter',
              'caddyfile',
          ]
        : [
              ...phpOptions,
              '-S',
              address,
              '-t',
              path.join(webRoot, 'public'),
              path.join(resources, 'router.php'),
          ];

    const serverLog = fs.openSync(
        path.join(storage, 'logs', 'servidor-local.log'),
        'a',
    );
    try {
        server = spawn(serverCommand, serverOptions, {
            ...options,
            stdio: ['ignore', serverLog, serverLog],
        });
    } finally {
        fs.closeSync(serverLog);
    }

    let serverFailure;
    server.once('error', (error) => {
        serverFailure = error;
    });
    server.once('exit', () => {
        serverFailure = new Error(
            'El servidor local dejó de funcionar. Verifica que el puerto ' +
                port +
                ' esté disponible.',
        );
        if (window && !leaving) {
            dialog.showErrorBox('Hostal Rincón', serverFailure.message);
            app.quit();
        }
    });

    let ready = false;
    for (let attempt = 0; attempt < 40; attempt++) {
        if (serverFailure) throw serverFailure;
        try {
            const reply = await fetch(origin + '/desktop-ready', {
                headers: { 'X-Hostal-Desktop': token },
                redirect: 'error',
                signal: AbortSignal.timeout(500),
            });
            ready =
                reply.status === 204 &&
                reply.headers.get('x-hostal-ready') === token;
            if (ready) break;
        } catch {
            // El proceso puede tardar en aceptar la primera conexión.
        }
        await delay(150);
    }
    if (!ready) throw new Error('No se pudo iniciar el servidor local.');

    session.defaultSession.webRequest.onBeforeRequest(
        { urls: ['http://*/*', 'https://*/*'] },
        ({ url }, done) => {
            done({ cancel: new URL(url).origin !== origin });
        },
    );
    session.defaultSession.webRequest.onBeforeSendHeaders(
        { urls: ['http://127.0.0.1/*'] },
        ({ url, requestHeaders }, done) => {
            if (new URL(url).origin === origin)
                requestHeaders['X-Hostal-Desktop'] = token;
            done({ requestHeaders });
        },
    );
    let savingBackup = false;
    session.defaultSession.on('will-download', (event, item, webContents) => {
        if (
            webContents !== window?.webContents ||
            item.getURL() !== origin + '/settings/backups/download'
        )
            return;

        if (savingBackup) {
            event.preventDefault();
            return;
        }

        const parent = window;
        const filename = item.getFilename();
        let temporary;
        let archive;
        try {
            temporary = fs.mkdtempSync(
                path.join(app.getPath('temp'), 'hostal-backup-'),
            );
            archive = path.join(temporary, 'backup.zip');
            // Set the path synchronously so Chromium does not open its own save dialog.
            item.setSavePath(archive);
        } catch {
            event.preventDefault();
            if (temporary)
                fs.rmSync(temporary, { recursive: true, force: true });
            dialog.showErrorBox(
                'No se guardó el respaldo',
                'No se pudo preparar el archivo temporal.',
            );
            return;
        }

        savingBackup = true;
        item.once('done', async (_event, state) => {
            let staging;
            try {
                if (state === 'cancelled' || parent.isDestroyed()) return;
                if (state !== 'completed') {
                    dialog.showErrorBox(
                        'No se guardó el respaldo',
                        'No se pudo descargar el ZIP. Vuelve a intentarlo.',
                    );
                    return;
                }

                const { canceled, filePath } = await dialog.showSaveDialog(
                    parent,
                    {
                        title: 'Guardar respaldo',
                        defaultPath: path.join(
                            app.getPath('documents'),
                            filename,
                        ),
                        filters: [
                            {
                                name: 'Respaldo de Hostal Rincón',
                                extensions: ['zip'],
                            },
                        ],
                    },
                );
                if (canceled || !filePath) return;

                const directory = fs.realpathSync(path.dirname(filePath));
                const destination = path.join(
                    directory,
                    path.basename(filePath),
                );
                const relative = path.relative(
                    fs.realpathSync(data),
                    directory,
                );
                const outsideData =
                    relative === '..' ||
                    relative.startsWith('..' + path.sep) ||
                    path.isAbsolute(relative);
                const isLink = fs
                    .lstatSync(destination, { throwIfNoEntry: false })
                    ?.isSymbolicLink();
                if (
                    !outsideData ||
                    isLink ||
                    path.extname(destination).toLowerCase() !== '.zip'
                ) {
                    dialog.showErrorBox(
                        'No se guardó el respaldo',
                        'Elige una ubicación fuera de la carpeta de datos y usa la extensión .zip.',
                    );
                    return;
                }

                staging = await fs.promises.mkdtemp(
                    path.join(directory, '.hostal-backup-'),
                );
                const stagedArchive = path.join(staging, 'backup.zip');
                await fs.promises.copyFile(archive, stagedArchive);
                await fs.promises.rename(stagedArchive, destination);

                await dialog.showMessageBox(parent, {
                    type: 'info',
                    title: 'Respaldo guardado',
                    message: 'El respaldo se guardó correctamente.',
                    detail: filePath,
                });
            } catch {
                dialog.showErrorBox(
                    'No se guardó el respaldo',
                    'Comprueba que puedas escribir en la carpeta elegida y que haya espacio disponible.',
                );
            } finally {
                savingBackup = false;
                for (const folder of [staging, temporary]) {
                    if (folder) {
                        await fs.promises
                            .rm(folder, { recursive: true, force: true })
                            .catch(console.error);
                    }
                }
            }
        });
    });
    Menu.setApplicationMenu(null);
    window = new BrowserWindow({
        width: 1250,
        height: 800,
        minWidth: 360,
        minHeight: 520,
        show: false,
        icon: path.join(
            webRoot,
            'public',
            windows ? 'favicon.ico' : 'apple-touch-icon.png',
        ),
        webPreferences: {
            nodeIntegration: false,
            contextIsolation: true,
            sandbox: true,
            devTools: false,
        },
    });
    window.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));
    window.webContents.on('will-navigate', (event, url) => {
        if (new URL(url).origin !== origin) event.preventDefault();
    });
    await showStep(4);
    await window.loadURL(origin);
    window.show();
    splash?.destroy();
    splash = undefined;
}

if (!packaged) {
    const developmentData = path.join(
        app.getPath('appData'),
        'Hostal Rincon Desarrollo',
    );
    fs.mkdirSync(developmentData, { recursive: true });
    app.setPath('userData', developmentData);
}

if (!app.requestSingleInstanceLock()) {
    app.quit();
} else {
    app.on('second-instance', () => {
        const activeWindow = splash ?? window;
        if (activeWindow?.isMinimized()) activeWindow.restore();
        activeWindow?.focus();
    });
    app.on('before-quit', () => {
        leaving = true;
        server?.kill();
    });
    app.on('window-all-closed', () => app.quit());
    app.whenReady()
        .then(start)
        .catch((error) => {
            const detail = error.stderr?.trim() || error.message;
            dialog.showErrorBox('No se pudo abrir Hostal Rincón', detail);
            app.quit();
        });
}
