<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Foto de perfil do MEMBRO (fix/member-photo-and-crop). Até aqui o membro era
     * imageless por design — só a performer tinha avatar. Decisão do PO (ago/2026):
     * o membro passa a ter foto de perfil, opcional, servida à performer no catálogo.
     *
     * `avatar_path` = caminho no disco privado `local` (member-media/{user_id}/avatar.jpg).
     * Fica FORA do $fillable e $hidden (disciplina de discrete_mode/2FA): a escrita
     * passa SÓ pelo MemberAvatarService após sanitização + anti-CSAM, via forceFill.
     *
     * `avatar_token` = identificador OPACO (não o user_id) na URL de serving. É o que
     * impede a foto servida à performer de vazar o member_id que o FanAlias existe para
     * esconder — a URL do performer.media usa profile_id "para não expor identificadores
     * internos"; o membro não tem profile, então usa este token aleatório. Rotaciona a
     * cada novo upload (URLs assinadas antigas morrem). $hidden e fora do $fillable.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('asaas_customer_id');
            $table->string('avatar_token', 64)->nullable()->unique()->after('avatar_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['avatar_token']);
            $table->dropColumn(['avatar_path', 'avatar_token']);
        });
    }
};
