<?php

/*
|--------------------------------------------------------------------------
| hCaptcha — anti-bot em login e cadastro
|--------------------------------------------------------------------------
| hCaptcha (não reCAPTCHA) por decisão do PO, mesmo modelo do Seeking.
| Só duas portas o usam: POST /login e POST /cadastro (mais os equivalentes
| da API v1). Reset de senha e verificação de e-mail ficam DE FORA — ver
| item 7 abaixo.
|
| ⚠️ LEIA ANTES DE LIGAR EM PRODUÇÃO:
|
| 1. **hCaptcha é SUBPROCESSADOR e vê o IP de quem entra.** Ligar isto faz
|    o browser de cada pessoa que abre /login ou /cadastro falar direto com
|    hcaptcha.com, entregando IP, User-Agent e horário a um terceiro — numa
|    plataforma de conteúdo adulto, onde "esta pessoa tentou entrar na
|    Limen" já é dado sensível por si só.
|
|    Antes de HCAPTCHA_ENABLED=true em produção:
|      a) hCaptcha entra na POLÍTICA DE PRIVACIDADE (finalidade, dados,
|         base legal — LGPD art. 7º);
|      b) hCaptcha entra no REGISTRO DE SUBPROCESSADORES, ao lado de Asaas
|         e Didit;
|      c) o DPA da Intuition Machines (operadora do hCaptcha) precisa estar
|         assinado e arquivado.
|
|    Isto NÃO é formalidade: é o mesmo perímetro que a auditoria de pixels
|    (docs/PIXEL_AUDIT.md) protege — "zero pixels de terceiros em área
|    logada". O widget respeita esse perímetro porque só é carregado nas
|    duas telas de auth, que são públicas e deslogadas; ele NÃO está no
|    app.blade.php, e não pode entrar lá. Ver docs/HCAPTCHA.md.
|
| 2. **Desligado é o padrão, e desligado é NO-OP total.** Com
|    HCAPTCHA_ENABLED=false a regra de validação nem pede o campo, o
|    verificador não faz requisição nenhuma e o widget não renderiza — logo
|    nenhum byte sai para hcaptcha.com. É o estado de dev, de teste e o
|    valor que vai versionado.
|
| 3. **Falha de REDE não derruba o login.** Token recusado pelo hCaptcha
|    bloqueia (é o ponto). Mas hCaptcha fora do ar, timeout ou 5xx deixam
|    passar, com Log::warning — mesma escolha, e pelo mesmo motivo, do
|    fail-OPEN documentado no GeoBlock (config/geo.php): indisponibilidade
|    de terceiro não pode trancar a plataforma inteira para fora. Quem
|    quiser fail-closed tem que decidir isso conscientemente e aceitar que
|    uma queda do hCaptcha vira uma queda do login.
*/

return [

    /*
    | Chave PÚBLICA do site. Vai para o HTML — não é segredo, e é por isso
    | que ela pode ser compartilhada nas props do Inertia. Só é enviada à
    | tela quando `enabled` é true.
    */
    'sitekey' => env('HCAPTCHA_SITEKEY'),

    /*
    | Chave SECRETA, usada só server-side no siteverify. Nunca vai ao
    | frontend, nunca em log, nunca em URL (CLAUDE.md, princípio 4/5).
    */
    'secret' => env('HCAPTCHA_SECRET'),

    /*
    | Interruptor geral. `false` é NO-OP completo — ver item 2 acima.
    | Ligar sem cumprir o item 1 é problema de conformidade, não de config.
    */
    'enabled' => (bool) env('HCAPTCHA_ENABLED', false),

    /*
    | Endpoint de verificação server-side. Fixo aqui (e não no .env) porque
    | não é ajuste de ambiente: apontar isto para outro host seria mandar o
    | token e o segredo para terceiro não auditado.
    */
    'verify_url' => 'https://api.hcaptcha.com/siteverify',

    /*
    | Timeout do siteverify, em segundos. Curto de propósito: esta chamada
    | está no caminho síncrono do login, então o custo de um provedor lento
    | é medido em gente esperando para entrar. Estourado o prazo, cai no
    | fail-open do item 3.
    */
    'timeout' => 5,

];
