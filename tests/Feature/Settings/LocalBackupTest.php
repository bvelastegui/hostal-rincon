<?php

use App\Models\BankAccount;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('el respaldo está disponible solo para administración en la aplicación de escritorio', function () {
    $this->withoutVite();
    $admin = User::factory()->create(['is_admin' => true]);
    $operator = User::factory()->create(['is_admin' => false]);

    config(['app.desktop' => true]);
    $this->get(route('backups.download'))->assertRedirectToRoute('login');

    $this->actingAs($operator)->get(route('cash-closings.index'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.canManageLocalBackups', false));
    $this->get(route('backups.download'))->assertRedirectToRoute('cash-closings.index');

    $this->actingAs($admin)->get(route('backups.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Backups')
            ->where('auth.canManageLocalBackups', true));

    config(['app.desktop' => false]);
    $this->get(route('backups.index'))->assertForbidden();
    $this->get(route('backups.download'))->assertForbidden();
});

test('la configuración restaura un ZIP anterior con los cambios confirmados del WAL', function () {
    $source = tempnam(sys_get_temp_dir(), 'hostal-source-');
    $destination = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hostal-destino-'.Str::uuid();
    File::makeDirectory($destination, 0700);
    $package = $destination.DIRECTORY_SEPARATOR.'backup.zip';
    $copy = $destination.DIRECTORY_SEPARATOR.'database.sqlite';
    $deviceId = (string) Str::uuid();
    $previousConnection = DB::getDefaultConnection();
    config([
        'app.desktop' => true,
        'app.desktop_version' => '0.10.5',
        'app.desktop_device_id' => $deviceId,
        'database.connections.backup_fixture' => [
            ...config('database.connections.sqlite'),
            'database' => $source,
            'journal_mode' => 'WAL',
            'busy_timeout' => 5000,
        ],
    ]);

    try {
        $this->artisan('migrate', ['--database' => 'backup_fixture'])->assertSuccessful();
        $this->artisan('migrate:rollback', [
            '--database' => 'backup_fixture',
            '--step' => 1,
        ])->assertSuccessful();
        DB::setDefaultConnection('backup_fixture');

        $admin = User::factory()->create(['is_admin' => true, 'email' => 'administracion@hostal.test']);
        DB::table('cash_openings')->update(['opening_balance_cents' => 12550]);
        $account = BankAccount::factory()->create(['opening_balance_cents' => 37000]);
        $pdo = DB::connection()->getPdo();
        expect($pdo->query('PRAGMA journal_mode')->fetchColumn())->toBe('wal')
            ->and($pdo->query('PRAGMA busy_timeout')->fetchColumn())->toBe(5000);
        $pdo->exec('PRAGMA wal_autocheckpoint = 0');
        $pdo->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetchAll();
        DB::table('users')->where('id', $admin->id)->update(['name' => 'Registro confirmado']);
        expect(filesize($source.'-wal'))->toBeGreaterThan(0);

        $writer = new PDO('sqlite:'.$source);
        $writer->beginTransaction();
        $writer->exec("UPDATE users SET name = 'Registro sin confirmar'");

        $response = $this->actingAs($admin)->get(route('backups.download'))->assertOk();
        expect($response->headers->get('Content-Disposition'))->toContain('.zip')
            ->and($response->headers->get('Content-Type'))->toBe('application/zip')
            ->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
        $temporaryFile = $response->baseResponse->getFile()->getPathname();
        expect(substr($temporaryFile, 0, -4))->not->toBeDirectory();

        ob_start();
        try {
            $response->baseResponse->sendContent();
        } finally {
            $contents = ob_get_clean();
        }

        expect($temporaryFile)->not->toBeFile();
        File::put($package, $contents);
        $archive = new PharData($package);
        expect(count($archive))->toBe(2)
            ->and($archive['database.sqlite']->isCompressed(Phar::GZ))->toBeTrue();
        $manifest = json_decode($archive['manifest.json']->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $archive->extractTo($destination, 'database.sqlite');

        expect($manifest)->toMatchArray([
            'format_version' => 1,
            'app_version' => '0.10.5',
            'database' => 'database.sqlite',
            'device_id' => $deviceId,
            'sha256' => hash_file('sha256', $copy),
        ])->and($manifest['created_at'])->toEndWith('Z');

        $restored = new PDO('sqlite:'.$copy);
        expect($restored->query('PRAGMA integrity_check')->fetchColumn())->toBe('ok')
            ->and($restored->query('SELECT name FROM users')->fetchColumn())->toBe('Registro confirmado');
        unset($restored);

        $writer->rollBack();
        $this->post(route('logout'))->assertRedirect();
        $livePath = $destination.DIRECTORY_SEPARATOR.'hostal.sqlite';
        File::put($livePath, '');
        config([
            'app.desktop_version' => '0.10.6',
            'fortify.username' => 'username',
            'database.connections.restored_fixture' => [
                ...config('database.connections.backup_fixture'),
                'database' => $livePath,
            ],
        ]);
        $this->artisan('migrate', ['--database' => 'restored_fixture'])->assertSuccessful();
        DB::setDefaultConnection('restored_fixture');
        $upload = new UploadedFile($package, 'hostal-respaldo.zip', test: true);

        $this->from(route('initial-setup.create'))->post(route('initial-setup.restore'), [
            'backup' => UploadedFile::fake()->createWithContent('invalido.zip', 'Sin formato de respaldo'),
        ])->assertRedirectToRoute('initial-setup.create')->assertSessionHasErrors('backup');
        expect(User::count())->toBe(0)
            ->and(DB::table('cash_openings')->value('opening_balance_cents'))->toBeNull();

        $damagedPath = $destination.DIRECTORY_SEPARATOR.'dañado.zip';
        $damaged = new PharData($damagedPath);
        $damaged->addFile($copy, 'database.sqlite');
        $damaged->addFromString('manifest.json', json_encode([
            ...$manifest, 'sha256' => str_repeat('0', 64),
        ], JSON_THROW_ON_ERROR));
        unset($damaged);
        $this->post(route('initial-setup.restore'), [
            'backup' => new UploadedFile($damagedPath, 'dañado.zip', test: true),
        ])->assertSessionHasErrors(['backup' => 'El archivo de datos del respaldo está dañado o fue modificado.']);
        expect(User::count())->toBe(0);

        config(['app.desktop_version' => '0.10.4']);
        $this->post(route('initial-setup.restore'), ['backup' => $upload])
            ->assertSessionHasErrors(['backup' => 'Este respaldo requiere Hostal Rincón 0.10.5 o una versión posterior.']);
        expect(User::count())->toBe(0);

        config(['app.desktop_version' => '0.10.6']);
        $orphaned = new PDO('sqlite:'.$copy);
        $orphaned->exec('PRAGMA foreign_keys = OFF');
        $orphaned->exec('UPDATE bank_accounts SET bank_id = 999999');
        unset($orphaned);
        $orphanedPath = $destination.DIRECTORY_SEPARATOR.'inconsistente.zip';
        $orphanedArchive = new PharData($orphanedPath);
        $orphanedArchive->addFile($copy, 'database.sqlite');
        $orphanedArchive->addFromString('manifest.json', json_encode([
            ...$manifest, 'sha256' => hash_file('sha256', $copy),
        ], JSON_THROW_ON_ERROR));
        unset($orphanedArchive);
        $this->post(route('initial-setup.restore'), [
            'backup' => new UploadedFile($orphanedPath, 'inconsistente.zip', test: true),
        ])->assertSessionHasErrors(['backup' => 'El respaldo contiene registros relacionados que no se pueden recuperar.']);
        expect(User::count())->toBe(0)
            ->and(BankAccount::count())->toBe(0)
            ->and(DB::table('cash_openings')->value('opening_balance_cents'))->toBeNull();

        $this->post(route('initial-setup.restore'), ['backup' => $upload])->assertRedirectToRoute('login');
        $this->assertGuest();
        expect(DB::table('cash_openings')->value('opening_balance_cents'))->toBe(12550)
            ->and(BankAccount::findOrFail($account->id)->opening_balance_cents)->toBe(37000)
            ->and(DB::connection()->getPdo()->query('PRAGMA journal_mode')->fetchColumn())->toBe('wal');

        $restoredAdmin = User::findOrFail($admin->id);
        expect($restoredAdmin->username)->toBe('administracion')
            ->and($restoredAdmin->name)->toBe('Registro confirmado')
            ->and(Hash::check('password', $restoredAdmin->password))->toBeTrue();

        $this->post(route('login.store'), ['username' => 'administracion', 'password' => 'password'])
            ->assertRedirectToRoute('dashboard');
        $this->assertAuthenticatedAs($restoredAdmin);
        $this->post(route('initial-setup.restore'), ['backup' => $upload])->assertNotFound();
    } finally {
        unset($archive, $restored, $writer, $pdo);
        DB::setDefaultConnection($previousConnection);
        DB::purge('backup_fixture');
        DB::purge('restored_fixture');
        File::delete([$source, $source.'-wal', $source.'-shm']);
        File::deleteDirectory($destination);
    }
});
