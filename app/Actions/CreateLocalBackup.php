<?php

namespace App\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PDO;
use Phar;
use PharData;
use RuntimeException;

class CreateLocalBackup
{
    public function handle(): string
    {
        $database = DB::connection();
        $version = config('app.desktop_version');
        $deviceId = config('app.desktop_device_id');

        if (! is_file($database->getDatabaseName()) || blank($version) || ! Str::isUuid($deviceId)) {
            throw new RuntimeException('No se encontraron la base de datos o los metadatos de la instalación.');
        }

        $workspace = storage_path('hostal-respaldo-'.Str::uuid());
        $snapshot = $workspace.DIRECTORY_SEPARATOR.'database.sqlite';
        $manifestPath = $workspace.DIRECTORY_SEPARATOR.'manifest.json';
        $archivePath = $workspace.DIRECTORY_SEPARATOR.'backup.zip';
        $output = $workspace.'.zip';

        if (! File::makeDirectory($workspace, 0700)) {
            throw new RuntimeException('No se pudo crear la carpeta temporal del respaldo.');
        }

        try {
            // VACUUM INTO incluye los cambios confirmados del WAL y deja intacta la base activa.
            $database->getPdo()->prepare('VACUUM INTO ?')->execute([$snapshot]);

            if (! is_file($snapshot) || ! filesize($snapshot)) {
                throw new RuntimeException('La copia de SQLite está vacía.');
            }

            $snapshotDatabase = new PDO('sqlite:'.$snapshot);
            $check = $snapshotDatabase->query('PRAGMA integrity_check');
            $integrity = $check === false ? [] : $check->fetchAll(PDO::FETCH_COLUMN);
            unset($check, $snapshotDatabase);

            if ($integrity !== ['ok']) {
                throw new RuntimeException('La copia de SQLite falló la verificación de integridad.');
            }

            $sha256 = hash_file('sha256', $snapshot);

            if ($sha256 === false) {
                throw new RuntimeException('No se pudo verificar el SHA-256 de la copia.');
            }

            $manifest = json_encode([
                'format_version' => 1,
                'app_version' => $version,
                'created_at' => now()->utc()->toISOString(),
                'database' => 'database.sqlite',
                'device_id' => $deviceId,
                'sha256' => $sha256,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if (File::put($manifestPath, $manifest."\n") === false) {
                throw new RuntimeException('No se pudo guardar el manifiesto del respaldo.');
            }

            $archive = new PharData($archivePath);
            $archive->addFile($snapshot, 'database.sqlite');
            $archive->addFile($manifestPath, 'manifest.json');
            $archive->compressFiles(Phar::GZ);
            unset($archive);

            if (! chmod($archivePath, 0600) || ! File::move($archivePath, $output)) {
                throw new RuntimeException('No se pudo preparar el ZIP del respaldo.');
            }

            return $output;
        } finally {
            unset($snapshotDatabase, $archive);
            File::deleteDirectory($workspace);
        }
    }
}
