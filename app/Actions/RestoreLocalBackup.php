<?php

namespace App\Actions;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PharData;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class RestoreLocalBackup
{
    private const CANDIDATE = 'desktop_backup_candidate';

    public function handle(UploadedFile $upload, bool $review = false): void
    {
        $workspace = storage_path('hostal-restauracion-'.Str::uuid());
        $archivePath = $workspace.DIRECTORY_SEPARATOR.'backup.zip';
        $snapshot = $workspace.DIRECTORY_SEPARATOR.'database.sqlite';

        try {
            if (! File::makeDirectory($workspace, 0700) || ! File::copy($upload->getPathname(), $archivePath)) {
                throw new RuntimeException('No se pudo preparar la restauración temporal.');
            }

            $this->extractVerifiedSnapshot($archivePath, $workspace, $snapshot);
            $this->migrateSnapshot($snapshot);
            if (! $review) {
                $this->importSnapshot(DB::connection(), $snapshot);
            }
        } catch (ValidationException|HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'backup' => 'No pudimos restaurar el respaldo. Verifica el archivo y el espacio disponible, y vuelve a intentarlo.',
            ]);
        } finally {
            DB::purge(self::CANDIDATE);
            config(['database.connections.'.self::CANDIDATE => null]);
            File::deleteDirectory($workspace);
        }
    }

    private function extractVerifiedSnapshot(string $archivePath, string $workspace, string $snapshot): void
    {
        try {
            $archive = new PharData($archivePath);
            if (count($archive) !== 2 || ! isset($archive['manifest.json'], $archive['database.sqlite'])) {
                throw new RuntimeException('Faltan entradas del respaldo.');
            }

            $manifestEntry = $archive['manifest.json'];
            $databaseEntry = $archive['database.sqlite'];
            if (! $manifestEntry->isFile() || $manifestEntry->getSize() > 32768
                || ! $databaseEntry->isFile() || $databaseEntry->getSize() > 268435456) {
                throw new RuntimeException('Los archivos internos no tienen el tamaño esperado.');
            }

            $manifest = json_decode($manifestEntry->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages(['backup' => 'Selecciona un ZIP de respaldo generado por Hostal Rincón.']);
        }

        $version = $manifest['app_version'] ?? null;
        $sha256 = $manifest['sha256'] ?? null;

        if (($manifest['format_version'] ?? null) !== 1 || ($manifest['database'] ?? null) !== 'database.sqlite'
            || ! is_string($version) || ! preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)*$/D', $version)
            || ! is_string($sha256) || ! preg_match('/^[0-9a-f]{64}$/D', $sha256)) {
            throw ValidationException::withMessages(['backup' => 'El manifiesto del respaldo no tiene un formato compatible.']);
        }

        $installed = config('app.desktop_version');
        if (! is_string($installed) || version_compare($version, $installed, '>')) {
            throw ValidationException::withMessages(['backup' => 'Este respaldo requiere Hostal Rincón '.$version.' o una versión posterior.']);
        }

        $archive->extractTo($workspace, 'database.sqlite');
        $actual = hash_file('sha256', $snapshot);
        if ($actual === false || ! hash_equals($sha256, $actual)) {
            throw ValidationException::withMessages(['backup' => 'El archivo de datos del respaldo está dañado o fue modificado.']);
        }
    }

    private function migrateSnapshot(string $snapshot): void
    {
        config(['database.connections.'.self::CANDIDATE => [
            ...config('database.connections.sqlite'),
            'url' => null,
            'database' => $snapshot,
            'journal_mode' => 'DELETE',
            'pragmas' => ['trusted_schema' => 'OFF'],
        ]]);

        $candidate = DB::connection(self::CANDIDATE);
        $check = $candidate->scalar('PRAGMA integrity_check');
        if ($check !== 'ok' || ! $candidate->getSchemaBuilder()->hasTable('migrations')) {
            throw ValidationException::withMessages(['backup' => 'La base del respaldo no es válida o está dañada.']);
        }

        $migrations = $candidate->table('migrations')->pluck('migration')->all();
        $known = array_keys(app(Migrator::class)->getMigrationFiles(database_path('migrations')));
        if ($migrations === [] || array_diff($migrations, $known) !== []) {
            throw ValidationException::withMessages(['backup' => 'La base del respaldo requiere una versión compatible de Hostal Rincón.']);
        }

        if (Artisan::call('migrate', ['--database' => self::CANDIDATE, '--force' => true]) !== 0) {
            throw new RuntimeException('No se pudo migrar la base del respaldo.');
        }

        if (! $candidate->table('users')->where('is_admin', true)->whereNotNull('username')->exists()) {
            throw ValidationException::withMessages(['backup' => 'El respaldo no contiene una cuenta administradora que pueda iniciar sesión.']);
        }

        if ($candidate->selectOne('PRAGMA foreign_key_check') !== null) {
            throw ValidationException::withMessages(['backup' => 'El respaldo contiene registros relacionados que no se pueden recuperar.']);
        }

        DB::disconnect(self::CANDIDATE);
    }

    private function importSnapshot(Connection $live, string $snapshot): void
    {
        $pdo = $live->getPdo();
        $previousTrust = $live->scalar('PRAGMA trusted_schema');
        $pdo->exec('PRAGMA trusted_schema = OFF');
        $attached = false;

        try {
            $pdo->prepare('ATTACH DATABASE ? AS backup_snapshot')->execute([$snapshot]);
            $attached = true;

            $live->transaction(function () use ($live): void {
                abort_if($live->table('users')->exists()
                    || $live->table('bank_accounts')->exists()
                    || $live->table('cash_openings')->whereNotNull('opening_balance_cents')->exists(),
                    409, 'La instalación ya está configurada.');

                $live->statement('PRAGMA defer_foreign_keys = ON');

                // Copy into the migrated schema under one write lock, without replacing the active WAL database files.
                $tables = $live->select("SELECT name FROM main.sqlite_schema
                    WHERE type = 'table' AND (name NOT GLOB 'sqlite_*' OR name = 'sqlite_sequence')
                    ORDER BY name = 'sqlite_sequence', name");

                foreach ($tables as $entry) {
                    $table = $live->getQueryGrammar()->wrap($entry->name);
                    $columns = array_column($live->select("PRAGMA main.table_info({$table})"), 'name');
                    $fields = implode(', ', array_map(fn (string $column): string => $live->getQueryGrammar()->wrap($column), $columns));
                    $values = implode(', ', array_map(fn (string $column): string => $live->getQueryGrammar()->wrap('source.'.$column), $columns));
                    $live->delete("DELETE FROM main.{$table}");
                    $live->insert("INSERT INTO main.{$table} ({$fields}) SELECT {$values} FROM backup_snapshot.{$table} AS source");
                }

                if ($live->selectOne('PRAGMA main.foreign_key_check') !== null) {
                    throw ValidationException::withMessages(['backup' => 'El respaldo contiene registros relacionados que no se pueden recuperar.']);
                }
            });
        } finally {
            if ($attached) {
                $pdo->exec('DETACH DATABASE backup_snapshot');
            }
            $pdo->exec($previousTrust ? 'PRAGMA trusted_schema = ON' : 'PRAGMA trusted_schema = OFF');
        }
    }
}
