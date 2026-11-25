<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advances', function (Blueprint $table): void {
            $table->foreignId('cash_movement_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('advances', fn (Blueprint $table) => $table->dropForeign(['cash_movement_id']));
        Schema::table('advances', function (Blueprint $table): void {
            $table->dropUnique(['cash_movement_id']);
            $table->dropColumn('cash_movement_id');
        });
    }
};
