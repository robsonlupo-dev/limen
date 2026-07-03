<?php

use Symfony\Component\Finder\Finder;

/**
 * Guarda contra a "tela preta" recorrente (S3.5): se um componente do frontend
 * chama route('x') e 'x' não está no allowlist `only` de config/ziggy.php, o
 * Ziggy lança em runtime e a página inteira renderiza vazia. Este teste varre
 * os arquivos Vue/JS por chamadas route('...') com nome literal e falha se
 * algum nome estiver fora do allowlist — transformando o bug em falha de CI.
 */
it('exposes every route used by the frontend in the Ziggy allowlist', function () {
    $allowlist = require base_path('config/ziggy.php');
    $allowed = $allowlist['only'] ?? [];

    $frontendDir = resource_path('js');

    $used = [];
    foreach (Finder::create()->files()->in($frontendDir)->name(['*.vue', '*.js']) as $file) {
        // Captura route('nome') e route("nome") — só o primeiro argumento literal.
        preg_match_all(
            '/\broute\(\s*[\'"]([a-zA-Z0-9_.\-]+)[\'"]/',
            $file->getContents(),
            $matches,
        );

        foreach ($matches[1] as $name) {
            $used[$name] ??= [];
            $used[$name][] = str_replace($frontendDir . '/', '', $file->getPathname());
        }
    }

    $missing = array_diff(array_keys($used), $allowed);

    $message = "Rotas usadas no frontend fora do allowlist de config/ziggy.php:\n";
    foreach ($missing as $name) {
        $message .= "  - route('{$name}')  em: " . implode(', ', array_unique($used[$name])) . "\n";
    }
    $message .= 'Adicione cada uma ao array only[] de config/ziggy.php.';

    expect($missing)->toBeEmpty($message);
});
