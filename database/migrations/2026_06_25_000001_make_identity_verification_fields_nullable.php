<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('identity_verifications', function (Blueprint $table) {
            $table->text('document_number')->nullable()->change();
            $table->text('full_legal_name')->nullable()->change();
            $table->text('date_of_birth')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('identity_verifications', function (Blueprint $table) {
            $table->text('document_number')->nullable(false)->change();
            $table->text('full_legal_name')->nullable(false)->change();
            $table->text('date_of_birth')->nullable(false)->change();
        });
    }
};
