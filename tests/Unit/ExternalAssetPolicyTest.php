<?php

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Política de origem externa nos Blades
|--------------------------------------------------------------------------
|
| Invariante de "zero terceiros em área logada" (CLAUDE.md, princípio 1).
| A auditoria de 20/07/2026 (docs/PIXEL_AUDIT.md) limpou o Google Fonts do
| app.blade.php — este teste existe para a limpeza não regredir por descuido:
| um <script src> de CDN ou um <img> remoto num template volta a vazar IP e
| User-Agent do membro para um terceiro, e ninguém percebe numa review.
|
| Fica em tests/Unit de propósito: é varredura estática de arquivo, não precisa
| de banco. Em Feature o Pest aplicaria RefreshDatabase e cobraria uma migração
| inteira por nada.
|
| Escopo: só o que o CLIENTE BAIXA — <script src>, <img src>, <link href>,
| <iframe>, url() em CSS inline. Um <a href="https://..."> é navegação que o
| usuário escolhe, não requisição automática, então não conta.
*/

/**
 * Origens externas permitidas. Vazia: o self-host das fontes fechou o último
 * caso legítimo. Só acrescente aqui com decisão explícita do PO — cada entrada
 * é um terceiro vendo o IP de quem abre a página.
 *
 * @var list<string>
 */
const ALLOWED_EXTERNAL_ORIGINS = [];

/** Tags cujo atributo dispara download automático pelo cliente. */
const ASSET_PATTERN = '/<(?:script|img|iframe|source|embed|object|video|audio|link)\b[^>]*?\b(?:src|href|data|poster)\s*=\s*["\'](?<url>(?:https?:)?\/\/[^"\']+)/i';

/** url(...) e @import dentro de <style> inline. */
const CSS_URL_PATTERN = '/(?:url\(\s*["\']?|@import\s+["\'])(?<url>(?:https?:)?\/\/[^"\'\)\s]+)/i';

/** Raiz de views resolvida por caminho: tests/Unit não sobe o app do Laravel. */
function viewsPath(string $path = ''): string
{
    return dirname(__DIR__, 2).'/resources/views'.($path ? "/{$path}" : '');
}

function bladeFiles(): Finder
{
    return Finder::create()->files()->in(viewsPath())->name('*.blade.php');
}

function lineOf(string $contents, int $offset): int
{
    return substr_count(substr($contents, 0, $offset), "\n") + 1;
}

function isAllowed(string $url): bool
{
    $host = parse_url(str_starts_with($url, '//') ? "https:{$url}" : $url, PHP_URL_HOST);

    foreach (ALLOWED_EXTERNAL_ORIGINS as $allowed) {
        if ($host === $allowed || str_ends_with((string) $host, ".{$allowed}")) {
            return true;
        }
    }

    return false;
}

it('nenhum Blade carrega asset de origem externa', function () {
    $violations = [];

    foreach (bladeFiles() as $file) {
        $contents = $file->getContents();
        $relative = 'resources/views/'.$file->getRelativePathname();

        foreach ([ASSET_PATTERN, CSS_URL_PATTERN] as $pattern) {
            preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

            foreach ($matches as $match) {
                [$url, $offset] = $match['url'];

                if (isAllowed($url)) {
                    continue;
                }

                $violations[] = sprintf('%s:%d → %s', $relative, lineOf($contents, $offset), $url);
            }
        }
    }

    expect($violations)->toBe([], sprintf(
        "Asset de origem externa em Blade (%d):\n  %s\n\n".
        'Área logada não fala com terceiro: o request leva IP e User-Agent do membro. '.
        "Self-host o arquivo (ver public/fonts + resources/css/fonts.css) ou, com aval do PO, ".
        'adicione o host em ALLOWED_EXTERNAL_ORIGINS. Ver docs/PIXEL_AUDIT.md.',
        count($violations),
        implode("\n  ", $violations),
    ));
});

it('a view raiz do Inertia não referencia o Google Fonts', function () {
    // Redundante com o teste acima por enquanto, e de propósito: app.blade.php é
    // a raiz de TODA página logada, e o Google Fonts foi o caso concreto que a
    // auditoria encontrou. Se alguém afrouxar a allowlist, este aqui não cede.
    expect(file_get_contents(viewsPath('app.blade.php')))
        ->not->toContain('fonts.googleapis.com')
        ->not->toContain('fonts.gstatic.com');
});
