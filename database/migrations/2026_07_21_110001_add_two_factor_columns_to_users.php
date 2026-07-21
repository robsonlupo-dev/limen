<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 2FA TOTP da performer. Uma conta comprometida expõe o KYC (documento +
     * selfie) e deixa um terceiro publicar como se fosse a performer
     * verificada — a senha sozinha não é fator suficiente para esse estrago.
     *
     * `text` e não `string` nos dois segredos porque o valor gravado é o
     * ciphertext do cast `encrypted` (APP_KEY), que é várias vezes maior que o
     * segredo em claro: 32 chars de base32 viram ~200 bytes, e os 8 recovery
     * codes serializados em JSON passam de 255 com folga.
     *
     * `two_factor_confirmed_at` é o que define 2FA LIGADO — não a presença do
     * secret. Entre o enable() e o confirm() a performer tem segredo gravado e
     * ainda não provou que o app autenticador funciona; gatear o login nesse
     * intervalo trancaria a conta para fora com um QR que ela nunca escaneou.
     *
     * As três colunas ficam FORA do $fillable do User de propósito (mesma
     * regra de discrete_mode e das colunas de exclusão): quem escreve o
     * segundo fator é o TwoFactorService, nunca um payload de formulário.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
            ]);
        });
    }
};
