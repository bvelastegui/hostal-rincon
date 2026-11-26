const { build } = require('../package.json');

module.exports = {
    ...build,
    appId: 'com.nexodigital.hostalrincon.frankenphp.qa',
    productName: 'Hostal Rincón',
    extraMetadata: { name: 'hostal-rincon-frankenphp-qa' },
    directories: { output: 'dist/frankenphp-entrega' },
    files: [...build.files, 'frankenphp/splash.html'],
    extraResources: [
        {
            ...build.extraResources[0],
            from: 'runtime/octane-web',
            filter: [...build.extraResources[0].filter, 'composer.json'],
        },
        build.extraResources[1],
        { from: 'frankenphp/Caddyfile', to: 'Caddyfile' },
    ],
    nsis: {
        ...build.nsis,
        include: 'frankenphp/installer.nsh',
        differentialPackage: false,
    },
    win: {
        ...build.win,
        artifactName:
            'Hostal-Rincon-FrankenPHP-${version}-Windows-${arch}-Setup.${ext}',
        extraResources: [
            {
                ...build.win.extraResources[0],
                from: 'runtime/frankenphp',
                to: 'frankenphp',
                filter: [
                    'frankenphp.exe',
                    ...build.win.extraResources[0].filter,
                    'README*',
                    'LICENSE*',
                ],
            },
            build.win.extraResources[1],
            { from: 'frankenphp/php.ini', to: 'frankenphp/php.ini' },
        ],
    },
};
