<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_closings', function (Blueprint $table): void {
            $table->timestamp('shortage_resolved_at')->nullable();
            $table->foreignId('shortage_resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('shortage_resolution_method', 20)->nullable();
            $table->string('shortage_resolution_note', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cash_closings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('shortage_resolved_by');
            $table->dropColumn(['shortage_resolved_at', 'shortage_resolution_method', 'shortage_resolution_note']);
        });
    }
};
