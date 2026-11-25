<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_closings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->string('type', 20)->default('independent');
            $table->date('closed_on')->index();
            $table->bigInteger('expected_cash_cents');
            $table->unsignedBigInteger('counted_cash_cents');
            $table->bigInteger('difference_cents');
            $table->string('note', 500)->nullable();
            $table->foreignId('closed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_closings');
    }
};
