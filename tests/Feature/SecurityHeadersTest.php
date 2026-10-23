<?php

/**
 * Cabeçalhos de segurança de base (App\Http\Middleware\SecurityHeaders).
 *
 * O `nosniff` já existia; ganhou teste agora porque docs/SECURITY_ISSUES.md
 * § 1.4 o transformou em requisito com uma dependência do outro lado. O
 * re-encode do ImageProcessingService garante que o arquivo servido é um JPEG
 * de verdade; o `nosniff` é o que impede o navegador de OLHAR o conteúdo e
 * decidir tratá-lo como outra coisa. Um sem o outro deixa metade do vetor de
 * polyglot de pé, e nada avisaria se o header saísse do middleware.
 *
 * O middleware é `append` global (bootstrap/app.php), então vale para as duas
 * portas de auth — por isso o teste cobre web e api.
 */
it('manda X-Content-Type-Options: nosniff na resposta web', function () {
    $this->get('/')->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('manda X-Content-Type-Options: nosniff também na api', function () {
    // Rota inexistente serve: o que se mede é o middleware global, que roda
    // mesmo quando nada casa. Se algum dia ele virar `web()` em vez de append,
    // este é o teste que cai.
    $this->getJson('/api/v1/rota-que-nao-existe')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('mantém os demais cabeçalhos de base', function () {
    $this->get('/')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
});

// ─── HSTS por hostname (fix/sec-hsts-hostname) ───────────────────────────────
//
// O gate é o HOST, não o APP_ENV: a instância de produção roda APP_ENV=staging
// (um checkout servindo os dois domínios), então gatear por environment deixava
// o usuário real no thelimen.com.br com o HSTS fraco de 5 min. Estes testes
// travam o comportamento por host — `APP_ENV` de teste é 'testing', então o
// gate antigo reprovaria o host de produção aqui.

it('manda HSTS forte (1 ano) no host de produção, mesmo fora de APP_ENV=production', function () {
    $this->get('https://thelimen.com.br/')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

it('mantém HSTS curto e reversível no apelido de dev', function () {
    $this->get('https://limen.dev.br/')
        ->assertHeader('Strict-Transport-Security', 'max-age=300');
});

it('não manda HSTS sob HTTP (o navegador ignoraria, e evita confundir proxies)', function () {
    $resp = $this->get('http://thelimen.com.br/');
    expect($resp->headers->has('Strict-Transport-Security'))->toBeFalse();
});
