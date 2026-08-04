<?php

/**
 * LiveKit — infra de vídeo em tempo real (Sprint 15, live pública e chamada 1:1).
 * Dona ÚNICA de rooms e tokens: App\Services\LiveKitService (que lê tudo daqui).
 *
 * As CREDENCIAIS ficam no .env (princípio nº 5 — segredo nunca no Git):
 * LIVEKIT_URL, LIVEKIT_API_KEY, LIVEKIT_API_SECRET. O SDK assina o JWT de acesso
 * LOCALMENTE com o api_secret (HMAC HS256) — o secret nunca vai ao cliente nem
 * vira claim; vira a assinatura. Nunca logar/serializar este array em exceção.
 */
return [

    // Endpoint e credenciais do LiveKit (Cloud na Fase 1 — M.11). Só do .env.
    'url' => env('LIVEKIT_URL'),
    'api_key' => env('LIVEKIT_API_KEY'),
    'api_secret' => env('LIVEKIT_API_SECRET'),

    // TTL do token de acesso, em SEGUNDOS. 300 = 5 min: janela curta é a mitigação
    // do §2.5 (serving sem cifra em memória). O token LiveKit é auto-contido — o
    // servidor NÃO reconsulta o backend a cada frame —, então este TTL é o teto da
    // janela em que um viewer que perdeu o direito (perdeu tier / foi banido)
    // continua na sala por expiração passiva. A revogação REAL é ativa
    // (LiveKitService::revokeParticipant) + reautorização na RE-EMISSÃO do token
    // (o PR de serving reconfere tier/ban a cada renovação — precedente
    // "expiração vale na leitura" do ChatAccess/Story). Não é para renovar cego.
    'token_ttl' => (int) env('LIVEKIT_TOKEN_TTL', 300),

    // Ocioso máximo de uma sala vazia antes do LiveKit removê-la (segundos). É a
    // auto-limpeza de uma live abandonada (performer some sem /stop): passado
    // isto, roomExists volta false e o join reconcilia a live_session para ended.
    'empty_timeout' => (int) env('LIVEKIT_EMPTY_TIMEOUT', 300),

    // Tetos de participantes por tipo de sala. Aplicados na criação da sala
    // (LiveKitService::createRoom); o LiveKit recusa o join além do teto.
    'max_participants_live' => (int) env('LIVEKIT_MAX_PARTICIPANTS_LIVE', 50),
    'max_participants_call' => (int) env('LIVEKIT_MAX_PARTICIPANTS_CALL', 2),
    'max_participants_group' => (int) env('LIVEKIT_MAX_PARTICIPANTS_GROUP', 10),

];
