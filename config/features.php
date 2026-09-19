<?php

/**
 * Feature flags de dark launch. Default FALSE: a infra sobe DESLIGADA e só liga
 * quando o serving daquela feature estiver pronto e revisado.
 *
 * Dois gates, defesa em profundidade:
 *   1. Middleware `feature:live` / `feature:call` — 403 amigável na porta HTTP.
 *   2. App\Services\LiveKitService::createRoom/generateToken — checam a flag
 *      INTERNAMENTE (a fonte de credencial/sala não pode ser emitida com a
 *      feature "desligada" por um command/job/PR futuro fora de rota gateada).
 *
 * ⚠️ KILL-SWITCH: desligar a flag bloqueia NOVAS entradas, mas NÃO esvazia salas
 * já vivas (tokens válidos por até token_ttl + participantes conectados). O kill
 * real de uma sala em incidente é deleteRoom + revokeParticipant — que de
 * propósito NÃO checam a flag, para funcionarem como teardown depois do off.
 */
return [

    'live_enabled' => (bool) env('FEATURE_LIVE_ENABLED', false),
    'call_enabled' => (bool) env('FEATURE_CALL_ENABLED', false),

    /*
     * Pré-lançamento da landing. Default TRUE (ao contrário das flags de dark
     * launch acima): o site está em pré-lançamento HOJE, então a raiz pública só
     * captura e-mail — a landing esconde os botões de conta do header (Entrar /
     * Criar conta) e não oferece cadastro. No lançamento, `LANDING_PRELAUNCH=false`
     * traz os botões de volta (só o .env muda, sem rebuild do front). Afeta SÓ a
     * landing: as outras telas guest sempre mostram os botões.
     */
    'landing_prelaunch' => (bool) env('LANDING_PRELAUNCH', true),

    /*
     * "Quebrar o vidro": revelação auditada de nome/e-mail de UM membro no painel
     * admin (feat/admin-members). Default FALSE (dark launch) — o mecanismo (com
     * trilha de auditoria) já existe, mas só deve ser LIGADO após o parecer do
     * jurídico sobre LGPD (ver docs/PENDENCIAS_JURIDICAS.md, item "(e)"). Ligar em
     * staging/UAT para avaliar: FEATURE_MEMBER_IDENTITY_REVEAL=true no .env.
     */
    'member_identity_reveal' => (bool) env('FEATURE_MEMBER_IDENTITY_REVEAL', false),

];
