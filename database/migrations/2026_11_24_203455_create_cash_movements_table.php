<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->string('request_fingerprint', 64);
            $table->string('kind', 10);
            $table->string('payment_method', 10);
            $table->unsignedBigInteger('amount_cents');
            $table->string('description');
            $table->date('occurred_on')->index();
            $table->foreignId('cash_opening_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('bank_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('voucher', 100)->nullable();
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('responsible_name')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recorder_name');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->index();
            $table->timestamps();

            // The account determines its own bank. This key uses the bank that issued the voucher.
            $table->unique(['bank_id', 'voucher']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};
