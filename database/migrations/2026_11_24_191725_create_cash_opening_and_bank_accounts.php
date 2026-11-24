<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_openings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('opening_balance_cents')->nullable();
            $table->timestamp('opening_locked_at')->nullable();
            $table->timestamps();
        });

        DB::table('cash_openings')->insert(['id' => 1]);

        Schema::create('banks', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('name_key', 100)->unique();
            $table->timestamps();
        });

        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bank_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('name_key', 100);
            $table->unsignedBigInteger('opening_balance_cents');
            $table->timestamp('opening_locked_at')->nullable();
            $table->timestamps();

            $table->unique(['bank_id', 'name_key']);
        });

        Schema::table('advances', function (Blueprint $table): void {
            $table->foreignId('cash_opening_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('advances', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cash_opening_id');
        });

        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('banks');
        Schema::dropIfExists('cash_openings');
    }
};
