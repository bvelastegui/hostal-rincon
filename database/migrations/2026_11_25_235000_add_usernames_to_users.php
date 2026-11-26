<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
            $table->string('username', 64)->nullable()->unique();
        });

        DB::table('users')->select('id', 'email')->chunkById(200, function ($users): void {
            foreach ($users as $user) {
                $prefix = Str::lower(Str::ascii(Str::before($user->email ?? '', '@')));
                $prefix = trim(preg_replace('/[^a-z0-9._-]+/', '-', $prefix) ?? '', '._-');
                $base = strlen($prefix) >= 3 ? substr($prefix, 0, 64) : 'usuario-'.$user->id;
                $username = $base;

                for ($suffix = 1; DB::table('users')->where('username', $username)->exists(); $suffix++) {
                    $ending = '-'.$user->id.($suffix === 1 ? '' : '-'.$suffix);
                    $username = substr($base, 0, 64 - strlen($ending)).$ending;
                }

                DB::table('users')->where('id', $user->id)->update(['username' => $username]);
            }
        });
    }

    public function down(): void
    {
        if (DB::table('users')->whereNull('email')->exists()) {
            throw new RuntimeException('Asigna un correo a cada cuenta antes de revertir el inicio con nombre de usuario.');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
            $table->string('email')->nullable(false)->change();
        });
    }
};
