<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('document_type', ['cpf', 'rg', 'cnh']);
            $table->text('document_number');
            $table->text('full_legal_name');
            $table->text('date_of_birth');
            $table->string('document_front_path')->nullable();
            $table->string('document_back_path')->nullable();
            $table->string('selfie_path')->nullable();
            $table->string('provider')->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('provider_status')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'review'])->default('pending');
            $table->boolean('age_confirmed')->default(false);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_verifications');
    }
};
