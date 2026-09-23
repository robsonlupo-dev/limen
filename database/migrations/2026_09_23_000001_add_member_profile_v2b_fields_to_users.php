<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil PÚBLICO do membro — 2ª leva de campos (feat/member-profile-v2-fields).
 * Segue EXATAMENTE a disciplina da 1ª leva (2026_09_18_000003): colunas na
 * própria `users`, TODAS nullable/opt-in, FORA do $fillable — a performer lê,
 * então a escrita passa por endpoint dedicado (forceFill de allowlist) com o
 * UpdateMemberPublicProfileRequest como fronteira. Vocabulário e rótulos vivem
 * em App\Support\MemberProfileOptions; aqui só o schema.
 *
 * Nada aqui é dado sensível de LGPD: sem etnia, sem idade exata, sem nome. Peso
 * é guardado como FAIXA (o valor é o piso de 5kg; a exibição é "65–69 kg"),
 * nunca precisão. Bebe/fuma são colunas NOVAS em `users` — as homônimas da
 * performer vivem em `performer_profiles` (tabela separada), sem colisão.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Título/headline: uma frase curta que aparece em itálico sob o
            // apelido. Texto livre curto — o guarda de contato do request cuida
            // de telefone/rede social, como na bio.
            $table->string('headline', 100)->nullable()->after('bio');

            // Peso guardado como FAIXA (piso de 5kg; exibição "65–69 kg"). Nunca
            // precisão. Só aparece se preenchido (preencher = consentir).
            $table->unsignedSmallInteger('weight_kg')->nullable()->after('height_cm');

            // Detalhes — selects controlados (sem texto livre), todos opt-in.
            $table->string('education', 30)->nullable()->after('weight_kg');
            $table->string('occupation_area', 40)->nullable()->after('education');
            $table->string('children', 30)->nullable()->after('occupation_area');
            $table->string('drinks', 20)->nullable()->after('children');
            $table->string('smokes', 20)->nullable()->after('drinks');
            $table->string('availability', 30)->nullable()->after('smokes');

            // 2ª e 3ª localização (cidade/UF), opt-in — para quem mora numa
            // cidade e frequenta outras. SÓ cidade (nunca bairro), como a 1ª.
            $table->string('profile_city_2', 120)->nullable()->after('profile_uf');
            $table->string('profile_uf_2', 2)->nullable()->after('profile_city_2');
            $table->string('profile_city_3', 120)->nullable()->after('profile_uf_2');
            $table->string('profile_uf_3', 2)->nullable()->after('profile_city_3');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'headline',
                'weight_kg',
                'education',
                'occupation_area',
                'children',
                'drinks',
                'smokes',
                'availability',
                'profile_city_2',
                'profile_uf_2',
                'profile_city_3',
                'profile_uf_3',
            ]);
        });
    }
};
