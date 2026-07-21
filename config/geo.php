<?php

/*
|--------------------------------------------------------------------------
| Geobloqueio por país (FOSTA-SESTA)
|--------------------------------------------------------------------------
| A FOSTA-SESTA (EUA, 2018) responsabiliza a plataforma por conteúdo de
| terceiros ligado a prostituição. O Limen não opera nos EUA e não quer
| usuários americanos — bloquear na borda é a mitigação.
|
| ⚠️ LEIA ANTES DE PROMETER ISTO A ALGUÉM:
|
| 1. **Não funciona com o driver padrão.** `none` é fail-OPEN de propósito:
|    sem fonte de geolocalização, o país é desconhecido e ninguém é barrado.
|    Fail-closed aqui derrubaria o site inteiro no primeiro deploy. Enquanto
|    GEO_DRIVER=none, este middleware é um no-op — está montado e testado,
|    esperando a fonte de dados.
|
| 2. **Geobloqueio por IP não é uma garantia jurídica.** Qualquer VPN ou
|    proxy contorna, e é barato. Isto reduz exposição e demonstra intenção;
|    NÃO descreva como "usuários americanos não conseguem acessar" em
|    política de privacidade, contrato ou auditoria. O mesmo cuidado que
|    CLAUDE.md exige para o painel de visitantes ("não descreva como
|    anônimo") vale aqui.
|
| 3. **O driver `cloudflare` só é seguro atrás do Cloudflare de verdade.**
|    Ver o comentário do driver abaixo — é a diferença entre um gate e um
|    enfeite que qualquer curl derruba.
*/

return [

    /*
    | Fonte da geolocalização.
    |
    | 'none'       — nenhuma. País sempre desconhecido; nada é bloqueado.
    |                É o padrão, e é o estado de hoje: o projeto não tem
    |                biblioteca de geolocalização instalada.
    |
    | 'cloudflare' — lê o header `CF-IPCountry`, que o Cloudflare injeta.
    |
    |                ⚠️ Só use se o nginx do origin ACEITAR APENAS os ranges
    |                do Cloudflare. O header é texto que qualquer cliente
    |                pode mandar: batendo direto no IP do servidor com
    |                `-H 'CF-IPCountry: BR'`, um usuário dos EUA passa. Não
    |                há como o PHP distinguir — quem tem que distinguir é a
    |                camada de rede. Sem essa trava, este driver não bloqueia
    |                nada e ainda dá a impressão de que bloqueia.
    |
    | Próximo driver previsto: 'maxmind' (GeoLite2 free + geoip2/geoip2), que
    | resolve pelo IP e não depende de proxy nenhum. Ver
    | docs/GEOBLOCKING.md.
    */
    'driver' => env('GEO_DRIVER', 'none'),

    /*
    | Países barrados, ISO 3166-1 alfa-2, separados por vírgula no .env.
    | ['US'] é o motivo de o middleware existir (FOSTA-SESTA).
    */
    'blocked_countries' => array_values(array_filter(array_map(
        fn (string $c) => strtoupper(trim($c)),
        explode(',', (string) env('BLOCKED_COUNTRIES', 'US')),
    ))),

    /*
    | O que fazer quando o país não pôde ser determinado.
    |
    | false (padrão) — deixa passar. É o único valor seguro com o driver
    | 'none', e o razoável com 'cloudflare' enquanto o origin ainda aceita
    | tráfego fora do CF: ligar isto ali derrubaria health checks, webhooks
    | do Asaas/Didit e o próprio deploy.
    |
    | true só faz sentido quando a fonte de geolocalização é confiável E o
    | origin está fechado — aí "desconhecido" vira sinal de contorno.
    */
    'block_unknown' => (bool) env('GEO_BLOCK_UNKNOWN', false),

    /*
    | Janela, em minutos, de deduplicação do audit log por IP.
    |
    | Sem isto, um bot americano num laço grava uma linha em `audit_logs` por
    | request e enterra a trilha real — a mesma preocupação que já motivou o
    | throttle "generoso" dos webhooks em routes/api.php. Uma linha por IP por
    | hora conta a história (quem, quando, quantas vezes começou) sem virar
    | vetor de flood de um endpoint não autenticado.
    */
    'audit_dedup_minutes' => (int) env('GEO_AUDIT_DEDUP_MINUTES', 60),

];
