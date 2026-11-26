const path = require('node:path');
const { Arch, Platform, build } = require('electron-builder');
const config = require('./electron-builder.cjs');

build({
    config,
    targets: Platform.WINDOWS.createTarget('nsis', Arch.x64),
    publish: 'never',
    async effectiveOptionComputed([defines]) {
        // NSIS reports each file as it writes it with its native File instruction.
        defines.APP_BUILD_DIR = path.join(
            path.dirname(defines.APP_64),
            'win-unpacked',
        );
        delete defines.APP_64;
        return false;
    },
}).catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
