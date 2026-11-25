<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_shifts', function (Blueprint $table): void {
            $table->unsignedBigInteger('opening_cash_cents')->nullable();
            $table->unsignedBigInteger('delivered_cash_cents')->nullable();
        });

        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->foreignId('cash_shift_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', fn (Blueprint $table) => $table->dropConstrainedForeignId('cash_shift_id'));
        Schema::table('cash_shifts', fn (Blueprint $table) => $table->dropColumn(['opening_cash_cents', 'delivered_cash_cents']));
    }
};
