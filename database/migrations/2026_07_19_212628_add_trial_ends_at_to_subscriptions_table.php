<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Fim do trial de 7 dias dos Founding Members. NULL = assinatura sem
            // trial (todo mundo fora da waitlist confirmada). É a data da primeira
            // cobrança de verdade no Asaas — espelha o nextDueDate enviado na
            // criação, não é um controle paralelo de cobrança.
            $table->timestamp('trial_ends_at')->nullable()->after('cancel_at_period_end');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('trial_ends_at');
        });
    }
};
