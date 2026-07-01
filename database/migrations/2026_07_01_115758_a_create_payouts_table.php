<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performer_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('tokens');
            $table->decimal('amount_brl', 10, 2);
            $table->text('pix_key');
            $table->enum('pix_key_type', ['cpf', 'email', 'phone', 'random']);
            $table->enum('status', ['pending', 'processing', 'paid', 'failed', 'cancelled'])->default('pending');
            $table->string('asaas_transfer_id')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['performer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
