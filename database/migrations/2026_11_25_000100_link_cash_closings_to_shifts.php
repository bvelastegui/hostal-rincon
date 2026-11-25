<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('responsible_id')->constrained('users')->restrictOnDelete();
            $table->string('responsible_name');
            $table->foreignId('started_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::table('cash_closings', function (Blueprint $table): void {
            $table->foreignId('cash_shift_id')->nullable()->constrained()->restrictOnDelete();
            $table->bigInteger('cash_adjustment_cents')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('cash_closings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cash_shift_id');
            $table->dropColumn('cash_adjustment_cents');
        });

        Schema::dropIfExists('cash_shifts');
    }
};
