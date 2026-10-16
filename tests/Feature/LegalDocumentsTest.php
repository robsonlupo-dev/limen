<?php

use App\Models\DocumentAcceptance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Textos jurídicos públicos (resources/legal/*.md). Prefixo `ld` nos helpers:
 * funções de arquivo no Pest são globais em PHP.
 *
 * Os textos são MINUTAS pré-lançamento (config legal.draft). O que se testa aqui
 * é a MECÂNICA (rota pública, render, token de contato, versão) e algumas
 * AFIRMAÇÕES que os textos fazem sobre o produto e que não podem divergir dele —
 * não a qualidade jurídica, que é do advogado.
 */

/** @return array<string, array{0: string, 1: string}> chave => [rota, chave da versão em config/documents.php] */
function ldDocs(): array
{
    return [
        'terms' => ['legal.terms', 'terms'],
        'privacy' => ['legal.privacy', 'privacy'],
        'content_policy' => ['legal.content-policy', DocumentAcceptance::TYPE_CONTENT_POLICY],
        'performance_contract' => ['legal.performance-contract', DocumentAcceptance::TYPE_PERFORMANCE_CONTRACT],
        'age_verification' => ['legal.age-verification', 'age_verification'],
    ];
}

it('serve cada texto jurídico em rota pública, renderizado do Markdown', function (string $key) {
    [$route, $versionKey] = ldDocs()[$key];

    $this->get(route($route))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('Legal/Document')
            ->where('document.key', $key)
            ->where('document.version', config("documents.versions.{$versionKey}"))
            ->has('document.title')
            ->has('document.html')
            ->has('related', 5)
            ->where('draft', true));
})->with(array_keys(ldDocs()));

it('não resta placeholder do escritório nem token de template sem substituir', function (string $key) {
    $response = $this->get(route(ldDocs()[$key][0]))->assertOk();

    $html = $response->viewData('page')['props']['document']['html'];

    expect($html)->not->toContain('aguardando Opice Blum')
        ->and($html)->not->toContain('{{')
        ->and(strlen($html))->toBeGreaterThan(1500);
})->with(array_keys(ldDocs()));

it('o corpo traz seções reais (h2) e nenhum HTML cru passa', function () {
    $html = $this->get(route('legal.terms'))->viewData('page')['props']['document']['html'];

    expect(substr_count($html, '<h2'))->toBeGreaterThan(8)
        ->and($html)->not->toContain('<script');
});

it('os .md só usam o token {{CONTATO}} (qualquer outro ficaria cru na página)', function () {
    foreach (File::files(resource_path('legal')) as $file) {
        preg_match_all('/\{\{\s*([A-Za-z_]+)\s*\}\}/', $file->getContents(), $m);

        expect(array_unique($m[1]))->each->toBe('CONTATO');
    }
});

it('sem LEGAL_CONTACT_EMAIL o texto usa frase neutra, nunca um e-mail inventado', function () {
    config()->set('legal.contact_email', null);

    $html = $this->get(route('legal.privacy'))->viewData('page')['props']['document']['html'];

    expect($html)->toContain('o canal de contato da plataforma')
        ->and($html)->not->toContain('mailto:');
});

it('com LEGAL_CONTACT_EMAIL o contato vira link mailto nos textos', function () {
    config()->set('legal.contact_email', 'privacidade@example.com');

    $html = $this->get(route('legal.privacy'))->viewData('page')['props']['document']['html'];

    expect($html)->toContain('mailto:privacidade@example.com');
});

it('ignora e-mail de contato inválido em vez de quebrar o link', function () {
    config()->set('legal.contact_email', 'isto não é e-mail');

    $html = $this->get(route('legal.terms'))->viewData('page')['props']['document']['html'];

    expect($html)->toContain('o canal de contato da plataforma')
        ->and($html)->not->toContain('mailto:');
});

it('a faixa de minuta desliga com LEGAL_DRAFT=false', function () {
    config()->set('legal.draft', false);

    $this->get(route('legal.terms'))
        ->assertInertia(fn (Assert $p) => $p->where('draft', false));
});

it('só os dois documentos da Performer exigem aceite; os demais não derrubam ninguém', function () {
    expect(DocumentAcceptance::REQUIRED)->toBe([
        DocumentAcceptance::TYPE_CONTENT_POLICY,
        DocumentAcceptance::TYPE_PERFORMANCE_CONTRACT,
    ]);

    foreach (['terms', 'privacy', 'age_verification'] as $key) {
        expect(DocumentAcceptance::currentVersion($key))->not->toBe('');
    }
});

// ── Afirmações dos textos que precisam bater com o produto ───────────────────

it('o contrato diz o que o produto faz: 80/70, R$ 0,60 por token, mínimo de 100 tokens', function () {
    $html = $this->get(route('legal.performance-contract'))->viewData('page')['props']['document']['html'];

    expect($html)->toContain('80%')
        ->and($html)->toContain('70%')
        ->and($html)->toContain('R$ 0,60')
        ->and($html)->toContain('100 tokens')
        ->and($html)->toContain('três avisos')
        ->and($html)->toContain('Somente ganhos');
});

it('os Termos não prometem o que não existe: nada de "dinheiro eletrônico" garantido nem rendimento', function () {
    $html = $this->get(route('legal.terms'))->viewData('page')['props']['document']['html'];

    expect($html)->toContain('não são moeda')
        ->and($html)->toContain('não rendem juros')
        ->and($html)->toContain('7 (sete) dias');
});

it('os textos públicos não afirmam geobloqueio nem CSAM por base oficial (hoje inertes/MVP)', function () {
    foreach (File::files(resource_path('legal')) as $file) {
        $text = mb_strtolower($file->getContents());

        expect($text)->not->toContain('photodna')
            ->and($text)->not->toContain('ncmec')
            ->and($text)->not->toContain('usuários dos eua não')
            ->and($text)->not->toContain('serpro');
    }
});

it('a landing e o cadastro continuam renderizando com os links legais', function () {
    $this->get(route('landing'))->assertOk();
    $this->get(route('register'))->assertOk();
});

it('o HTML raiz sinaliza conteúdo adulto (RTA) para controle parental', function () {
    $this->get(route('landing'))
        ->assertOk()
        ->assertSee('RTA-5042-1996-1400-1577-RTA', false)
        ->assertSee('name="rating" content="adult"', false);
});
