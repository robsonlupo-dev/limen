<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('token_package_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('provider', ['asaas'])->default('asaas');
            $table->string('provider_charge_id')->nullable()->unique();
            $table->enum('method', ['pix'])->default('pix');
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('tokens');
            $table->enum('status', ['pending', 'confirmed', 'failed', 'refunded', 'expired'])->default('pending');
            $table->text('pix_qr_code')->nullable();
            $table->text('pix_copy_paste')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
