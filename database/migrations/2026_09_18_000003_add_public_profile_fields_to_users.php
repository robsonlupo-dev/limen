<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil do membro v2 (feat/member-profile-v2). Campos PÚBLICOS, opcionais e
 * OPT-IN que o membro preenche em /meu-perfil e que a performer vê no perfil/
 * catálogo — SÓ quando existem (preencher = consentir) e SÓ com `profile_visible`
 * ligado (o mesmo opt-in mestre da galeria).
 *
 * NÃO confundir com os campos PRIVADOS já existentes `seeking` (texto) e a junção
 * `member_interest`: aqueles NUNCA voltam para a performer (afinidade server-side
 * + filtro do catálogo — ver App\Models\MemberInterest). Estes são a superfície
 * PÚBLICA nova, distinta de propósito para não regredir aquele invariante travado.
 *
 * Nada aqui é dado de autenticação nem privilégio, então poderiam ser $fillable;
 * mas, como TUDO isto é VISÍVEL à performer, a escrita passa por um endpoint
 * dedicado com Form Request (forceFill de allowlist), na mesma disciplina de
 * `lifestyle_tier`/`profile_visible` — o dado que a performer lê não entra por
 * mass assignment genérico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // "Sobre mim". Texto curto; passa pelo guarda de contato (telefone/
            // e-mail/@/rede social) reusando ChatContentFilter + a config do
            // apelido (App\Support\ProfileTextGuard). NUNCA em log/URL.
            $table->string('bio', 400)->nullable()->after('seeking');

            // "O que busco" e "Interesses" PÚBLICOS — listas controladas
            // (App\Support\MemberProfileOptions), guardadas como array de slugs.
            // JSON e não junção: são SÓ exibição (nunca filtro/afinidade), então
            // não precisam de índice/consulta como o `member_interest` privado.
            $table->json('public_seeking')->nullable()->after('bio');
            $table->json('public_interests')->nullable()->after('public_seeking');

            // Cidade/UF PÚBLICAS (autocomplete IBGE, reaproveitado da performer).
            // Só aparecem se preenchidas. Distintas de qualquer localização
            // privada — o catálogo NUNCA expôs localização; esta é opt-in.
            $table->string('profile_city', 120)->nullable()->after('public_interests');
            $table->string('profile_uf', 2)->nullable()->after('profile_city');

            // Detalhes (selects controlados, sem texto livre) — estado civil e
            // altura em cm (faixa de 5cm; nunca precisão que identifique).
            $table->string('marital_status', 30)->nullable()->after('profile_uf');
            $table->unsignedSmallInteger('height_cm')->nullable()->after('marital_status');

            // Faixa etária: DERIVADA de `birthdate` (nunca coluna de idade/data
            // exibida), mas só VAI AO AR se o membro optar por mostrá-la. É o que
            // reconcilia "derivar do birthdate" com o invariante de só exibir o
            // que o membro consentiu. Default false (não aparece).
            $table->boolean('show_age_band')->default(false)->after('height_cm');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'bio', 'public_seeking', 'public_interests',
                'profile_city', 'profile_uf', 'marital_status', 'height_cm',
                'show_age_band',
            ]);
        });
    }
};
