<?php

/*
|--------------------------------------------------------------------------
| Captcha — anti-bot em login e cadastro (driver abstrato)
|--------------------------------------------------------------------------
| Uma fonte, um interruptor: `CAPTCHA_PROVIDER` escolhe QUAL provedor roda.
|
|   - `none`      → NO-OP total (padrão de dev/teste/CI): o campo não é
|                   exigido, o widget não monta, nenhum byte sai para
|                   terceiro. É o valor versionado.
|   - `hcaptcha`  → hCaptcha (Intuition Machines). Era o único provedor até
|                   o Sprint 16; segue disponível sem mudança de contrato.
|   - `turnstile` → Cloudflare Turnstile. Gratuito, sem os limites do plano
|                   free do hCaptcha. Acrescentado quando o trial Pro do
|                   hCaptcha venceu (11/08/2026).
|
| A lógica NÃO é duplicada por provedor: `App\Services\Captcha\CaptchaManager`
| resolve o driver a partir daqui e todos compartilham o mesmo POST de
| siteverify (App\Services\Captcha\RemoteCaptchaDriver). Só mudam as chaves, a
| URL de verificação e o SDK do widget.
|
| ⚠️ LEIA ANTES DE LIGAR QUALQUER PROVEDOR EM PRODUÇÃO:
|
| 1. **Todo provedor de captcha é SUBPROCESSADOR e vê o IP de quem entra.**
|    Ligar isto faz o browser de cada pessoa que abre /login ou /cadastro
|    falar direto com o host do provedor (`hcaptcha.com` ou
|    `challenges.cloudflare.com`), entregando IP, User-Agent e horário a um
|    terceiro — numa plataforma de conteúdo adulto, onde "esta pessoa tentou
|    entrar na Limen" já é dado sensível por si só. Vale para hCaptcha E para
|    Turnstile: trocar de provedor troca o subprocessador, não elimina a
|    exposição.
|
|    Antes de `CAPTCHA_PROVIDER` sair de `none` em produção:
|      a) o provedor escolhido entra na POLÍTICA DE PRIVACIDADE (finalidade,
|         dados, base legal — LGPD art. 7º);
|      b) o provedor entra no REGISTRO DE SUBPROCESSADORES, ao lado de Asaas
|         e Didit;
|      c) o DPA do provedor (Intuition Machines para hCaptcha; Cloudflare,
|         Inc. para Turnstile) precisa estar assinado e arquivado.
|
|    Isto NÃO é formalidade: é o mesmo perímetro que a auditoria de pixels
|    (docs/PIXEL_AUDIT.md) protege — "zero pixels de terceiros em área
|    logada". O widget respeita esse perímetro porque só é carregado nas
|    duas telas de auth, que são públicas e deslogadas; ele NÃO está no
|    app.blade.php, e não pode entrar lá. Ver docs/CAPTCHA.md.
|
| 2. **`none` é o padrão, e `none` é NO-OP total.** O campo nem é exigido, o
|    verificador não faz requisição nenhuma e o widget não renderiza — logo
|    nenhum byte sai para terceiro. É o estado de dev, de teste e o valor que
|    vai versionado.
|
| 3. **Falha de REDE não derruba o login.** Token recusado pelo provedor
|    bloqueia (é o ponto). Mas provedor fora do ar, timeout ou 5xx deixam
|    passar, com Log::warning — mesma escolha, e pelo mesmo motivo, do
|    fail-OPEN documentado no GeoBlock (config/geo.php): indisponibilidade
|    de terceiro não pode trancar a plataforma inteira para fora.
*/

return [

    /*
    | Provedor ativo: 'hcaptcha' | 'turnstile' | 'none'.
    |
    | Padrão com PONTE de compatibilidade: instalações anteriores só conheciam
    | `HCAPTCHA_ENABLED`. Se `CAPTCHA_PROVIDER` não estiver definido, um
    | servidor com o hCaptcha já LIGADO continua no hCaptcha (não desliga um
    | gate anti-bot ativo em silêncio no deploy); sem nada definido, cai em
    | 'none'. Servidor novo define `CAPTCHA_PROVIDER` explicitamente.
    */
    'provider' => env('CAPTCHA_PROVIDER', env('HCAPTCHA_ENABLED', false) ? 'hcaptcha' : 'none'),

    /*
    | Nome do campo que o token do captcha ocupa no formulário. Neutro de
    | propósito: o front captura o token pelo callback do widget e o envia
    | neste campo (o Inertia serializa o objeto do useForm, não o input oculto
    | que o SDK injeta), então UM nome serve aos dois provedores. Dona única do
    | valor: App\Rules\CaptchaValid::FIELD, que aponta para cá.
    */
    'field' => 'captcha_token',

    /*
    | Timeout do siteverify, em segundos. Curto de propósito: esta chamada
    | está no caminho síncrono do login, então o custo de um provedor lento é
    | medido em gente esperando para entrar. Estourado o prazo, cai no
    | fail-open do item 3.
    */
    'timeout' => 5,

    'providers' => [

        'hcaptcha' => [
            /*
            | Chave PÚBLICA do site. Vai para o HTML — não é segredo, e é por
            | isso que pode ser compartilhada nas props do Inertia. Só é
            | enviada à tela quando o provedor ativo é este.
            */
            'sitekey' => env('HCAPTCHA_SITEKEY'),

            /*
            | Chave SECRETA, usada só server-side no siteverify. Nunca vai ao
            | frontend, nunca em log, nunca em URL (CLAUDE.md, princípio 4/5).
            */
            'secret' => env('HCAPTCHA_SECRET'),

            /*
            | Endpoint de verificação server-side. Fixo aqui (e não no .env)
            | porque não é ajuste de ambiente: apontar isto para outro host
            | seria mandar o token e o segredo para terceiro não auditado.
            */
            'verify_url' => 'https://api.hcaptcha.com/siteverify',
        ],

        'turnstile' => [
            'sitekey' => env('TURNSTILE_SITE_KEY'),
            'secret' => env('TURNSTILE_SECRET_KEY'),
            'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        ],

    ],

];
