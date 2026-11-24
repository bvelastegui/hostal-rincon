<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('employee_name');
            $table->uuid('request_id')->unique();
            $table->unsignedInteger('amount_cents');
            $table->date('issued_on');
            $table->string('responsible');
            $table->string('note', 500)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recorder_name');
            $table->timestamp('deducted_at')->nullable();
            $table->foreignId('deducted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deductor_name')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'deducted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advances');
    }
};
