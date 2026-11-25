<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('voided_by_name')->nullable();
            $table->string('void_reason')->nullable();
            $table->string('voided_voucher', 100)->nullable();
            $table->foreignId('replaces_movement_id')->nullable()->unique()->constrained('cash_movements')->restrictOnDelete();
        });

        Schema::table('cash_closings', function (Blueprint $table): void {
            $table->unsignedBigInteger('last_movement_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cash_closings', fn (Blueprint $table) => $table->dropColumn('last_movement_id'));

        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->dropForeign(['replaces_movement_id']);
            $table->dropUnique(['replaces_movement_id']);
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['replaces_movement_id', 'voided_at', 'voided_by_name', 'void_reason', 'voided_voucher']);
        });
    }
};
