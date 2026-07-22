<?php

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Política de origem externa (Blade + Vue/JS)
|--------------------------------------------------------------------------
|
| Invariante de "zero terceiros em área logada" (CLAUDE.md, princípio 1).
| A auditoria de 20/07/2026 (docs/PIXEL_AUDIT.md) limpou o Google Fonts do
| app.blade.php e um <img> hospedado em laravel.com do header de e-mail — este
| teste existe para a limpeza não regredir por descuido: um <script src> de CDN
| ou um <img> remoto volta a vazar IP e User-Agent do membro para um terceiro,
| e ninguém percebe numa review.
|
| Cobre os dois lados: `resources/views` (Blade) e `resources/js` (Vue/JS). O
| segundo entrou depois — a primeira versão só olhava Blade, e a superfície onde
| a Limen mais cresce é justamente Vue.
|
| Fica em tests/Unit de propósito: é varredura estática de arquivo, não precisa
| de banco. Em Feature o Pest aplicaria RefreshDatabase e cobraria uma migração
| inteira por nada.
|
| Escopo: só o que o CLIENTE BAIXA — <script src>, <img src>, <link href>,
| <iframe>, url() em CSS, import de CDN, fetch/WebSocket para host externo,
| e atribuição a .src (o idioma clássico de pixel: new Image().src = ...).
| Um <a href="https://..."> é navegação que o usuário escolhe, não requisição
| automática, então não conta.
|
| Só pega URL LITERAL no código. Endereço que vem de variável de ambiente —
| como o host do Reverb (`import.meta.env.VITE_REVERB_HOST` em bootstrap.js) —
| nunca aparece aqui, e por isso não precisa de allowlist.
*/

/**
 * Origens externas permitidas em Blade. Vazia: o self-host das fontes fechou o
 * último caso legítimo.
 *
 * @var list<string>
 */
const ALLOWED_BLADE_ORIGINS = [];

/**
 * Origens externas permitidas em Vue/JS. Vazia — e não é omissão: o único
 * terceiro que o front legitimamente contata é o servidor Reverb, que vem de
 * `import.meta.env.VITE_REVERB_*` e portanto não é literal nenhum no código.
 *
 * Listas separadas de propósito: liberar um host no e-mail não é a mesma
 * decisão que liberar no bundle que roda na área logada.
 *
 * @var list<string>
 */
const ALLOWED_JS_ORIGINS = [];

/** Tags cujo atributo dispara download automático pelo cliente. */
const ASSET_PATTERN = '/<(?:script|img|iframe|source|embed|object|video|audio|link)\b[^>]*?\b(?:src|href|data|poster)\s*=\s*["\'](?<url>(?:https?:)?\/\/[^"\']+)/i';

/** url(...) e @import em CSS — <style> inline no Blade, <style scoped> no Vue. */
const CSS_URL_PATTERN = '/(?:url\(\s*["\']?|@import\s+["\'])(?<url>(?:https?:)?\/\/[^"\'\)\s]+)/i';

/** import de CDN: `from 'https://…'`, `import 'https://…'`, `import('https://…')`. */
const JS_IMPORT_PATTERN = '/\b(?:from|import)\s*\(?\s*["\'](?<url>(?:https?:)?\/\/[^"\']+)/i';

/** Requisição a host externo escrito na mão. Relativo (/api/...) não casa. */
const JS_REQUEST_PATTERN = '/\b(?:fetch|axios(?:\.\w+)?|open|WebSocket|EventSource|importScripts|sendBeacon)\s*\(\s*["\'](?<url>(?:wss?:|https?:)?\/\/[^"\']+)/i';

/**
 * `img.src = 'https://…'` — como um pixel de rastreio é disparado por JS.
 *
 * Só `.src`, nunca `.href`: `location.href = '…'` é navegação (o "sair" do
 * gate de idade e o Panic Button fazem exatamente isso), e navegação que o
 * usuário escolhe está fora do escopo desta política. Incluir `.href` fazia o
 * teste acusar AgeGateModal.vue:27, que é comportamento correto.
 */
const JS_ASSIGN_PATTERN = '/\.src\s*=\s*["\'](?<url>(?:https?:)?\/\/[^"\']+)/i';

/** Caminhos resolvidos na unha: tests/Unit não sobe o app do Laravel. */
function projectPath(string $path): string
{
    return dirname(__DIR__, 2).'/'.$path;
}

function viewsPath(string $path = ''): string
{
    return projectPath('resources/views'.($path ? "/{$path}" : ''));
}

function lineOf(string $contents, int $offset): int
{
    return substr_count(substr($contents, 0, $offset), "\n") + 1;
}

/**
 * @param  list<string>  $allowed
 */
function isAllowedOrigin(string $url, array $allowed): bool
{
    $host = parse_url(str_starts_with($url, '//') ? "https:{$url}" : $url, PHP_URL_HOST);

    foreach ($allowed as $origin) {
        if ($host === $origin || str_ends_with((string) $host, ".{$origin}")) {
            return true;
        }
    }

    return false;
}

/**
 * @param  list<string>  $patterns
 * @param  list<string>  $allowed
 * @return list<string> "caminho:linha → url"
 */
function externalAssetViolations(Finder $files, string $prefix, array $patterns, array $allowed): array
{
    $violations = [];

    foreach ($files as $file) {
        $contents = $file->getContents();
        $relative = $prefix.$file->getRelativePathname();

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

            foreach ($matches as $match) {
                [$url, $offset] = $match['url'];

                if (isAllowedOrigin($url, $allowed)) {
                    continue;
                }

                $violations[] = sprintf('%s:%d → %s', $relative, lineOf($contents, $offset), $url);
            }
        }
    }

    return $violations;
}

function assetPolicyMessage(string $scope, array $violations, string $constant): string
{
    return sprintf(
        "Asset de origem externa em %s (%d):\n  %s\n\n".
        'Área logada não fala com terceiro: o request leva IP e User-Agent do membro. '.
        'Self-host o arquivo (o padrão está em public/fonts + resources/css/fonts.css), '.
        'use variável de ambiente se for endpoint de infra, ou — com aval do PO — '.
        'adicione o host em %s. Ver docs/PIXEL_AUDIT.md.',
        $scope,
        count($violations),
        implode("\n  ", $violations),
        $constant,
    );
}

it('nenhum Blade carrega asset de origem externa', function () {
    $violations = externalAssetViolations(
        Finder::create()->files()->in(viewsPath())->name('*.blade.php'),
        'resources/views/',
        [ASSET_PATTERN, CSS_URL_PATTERN],
        ALLOWED_BLADE_ORIGINS,
    );

    expect($violations)->toBe([], assetPolicyMessage('Blade', $violations, 'ALLOWED_BLADE_ORIGINS'));
});

it('nenhum componente Vue ou módulo JS carrega asset de origem externa', function () {
    $violations = externalAssetViolations(
        Finder::create()->files()->in(projectPath('resources/js'))->name(['*.vue', '*.js']),
        'resources/js/',
        [ASSET_PATTERN, CSS_URL_PATTERN, JS_IMPORT_PATTERN, JS_REQUEST_PATTERN, JS_ASSIGN_PATTERN],
        ALLOWED_JS_ORIGINS,
    );

    expect($violations)->toBe([], assetPolicyMessage('Vue/JS', $violations, 'ALLOWED_JS_ORIGINS'));
});

it('a varredura enxerga os arquivos que deveria', function () {
    // Guarda contra passar vazio: se um glob quebrar (rename de diretório,
    // filtro errado), os testes acima ficam verdes sem ter lido nada — o pior
    // modo de falha possível para um teste de invariante.
    $blades = iterator_count(Finder::create()->files()->in(viewsPath())->name('*.blade.php'));
    $scripts = iterator_count(Finder::create()->files()->in(projectPath('resources/js'))->name(['*.vue', '*.js']));

    expect($blades)->toBeGreaterThan(10)
        ->and($scripts)->toBeGreaterThan(40);
});

it('a view raiz do Inertia não referencia o Google Fonts', function () {
    // Redundante com os testes acima por enquanto, e de propósito: app.blade.php
    // é a raiz de TODA página logada, e o Google Fonts foi o caso concreto que a
    // auditoria encontrou. Se alguém afrouxar a allowlist, este aqui não cede.
    expect(file_get_contents(viewsPath('app.blade.php')))
        ->not->toContain('fonts.googleapis.com')
        ->not->toContain('fonts.gstatic.com');
});
