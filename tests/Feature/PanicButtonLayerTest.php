<?php

use Illuminate\Support\Facades\File;

// Guarda das SAÍDAS do PanicButton — as três vias e seus invariantes.
//
// A saída rápida é feature de segurança física: tira a Limen da tela quando
// alguém entra na sala. Ela tem três vias, e este arquivo trava o invariante de
// cada uma:
//   1. DISCO flutuante na CAMADA DE TOPO — nada pode cobri-lo. "Nada" é uma
//      afirmação sobre o projeto inteiro, não sobre um componente: já foi violada
//      em silêncio (Modal.vue empatando em z-50, overlay de onboarding em z-9000).
//   2. TECLADO (duplo-Escape) — dispara mesmo sob overlay, sem depender de o disco
//      ou o link estarem visíveis. É a via que nenhuma tela cobre.
//   3. (o link rotulado do header é cobrado por PanicButtonVisibilityTest.)
//
// O projeto não tem Vitest/Jest, então o teste roda pela fonte. A parte de camada
// é grosseira de propósito: casa a DECLARAÇÃO de z-index (CSS `z-index: N` e a
// classe arbitrária `z-[N]` do Tailwind), nunca prosa de comentário.

// 10001 e não 10000: o teto anterior do projeto já era 10000 (IntroAnimation,
// com AgeGateModal logo abaixo em 9999, nessa ordem de propósito — a splash
// cobre o gate 18+ até terminar). O disco entra UM acima desse teto, em vez de
// empurrar os dois para baixo e mexer no gate de idade sem necessidade.
// Consequência: 10000 e 9999 seguem permitidos e ficam logo abaixo do disco.
const PANIC_LAYER = 10001;
const PANIC_COMPONENT = 'resources/js/Components/PanicButton.vue';

/**
 * Todos os z-index declarados num arquivo .vue, das duas sintaxes usadas no
 * projeto. Comentário não casa: `z-index:` exige os dois-pontos e `z-[N]` exige
 * os colchetes.
 *
 * @return array<int, int>
 */
function declaredZIndexes(string $source): array
{
    preg_match_all('/z-index:\s*(\d+)/', $source, $css);
    preg_match_all('/\bz-\[(\d+)\]/', $source, $tailwind);

    return array_map('intval', array_merge($css[1], $tailwind[1]));
}

// ─── Via 1: o disco na camada de topo ────────────────────────────────────────

it('keeps the panic disc on the top layer', function () {
    // Casa o ATRIBUTO class, não a fonte inteira: o comentário do componente
    // cita `z-[10000]` em prosa, e um `toContain` cru continuaria verde se
    // alguém apagasse a classe e deixasse o comentário para trás.
    expect(File::get(base_path(PANIC_COMPONENT)))
        ->toMatch('/class="[^"]*\bz-\['.PANIC_LAYER.'\]/');
});

it('teleports the panic disc out of any ancestor stacking context', function () {
    // z-index alto não basta: um `transform`/`filter` num ancestral cria
    // stacking context e prende o disco lá dentro, por mais alto que seja o
    // número. O Teleport é o que garante que ele vive na raiz.
    expect(File::get(base_path(PANIC_COMPONENT)))->toContain('<Teleport to="body">');
});

it('reserves the panic layer and above for the panic disc alone', function () {
    $offenders = [];

    foreach (File::allFiles(base_path('resources/js')) as $file) {
        if ($file->getExtension() !== 'vue') {
            continue;
        }

        $relative = str_replace(base_path().'/', '', $file->getPathname());

        if ($relative === PANIC_COMPONENT) {
            continue;
        }

        foreach (declaredZIndexes($file->getContents()) as $z) {
            if ($z >= PANIC_LAYER) {
                $offenders[] = "{$relative} (z-index {$z})";
            }
        }
    }

    // Se este teste quebrou por causa de um overlay novo: baixe o overlay, não
    // suba o disco. Do outro lado do empate está a segurança física do usuário.
    expect($offenders)->toBe([]);
});

// ─── Via 2: o teclado (duplo-Escape), que nenhuma tela cobre ──────────────────

it('keeps the keyboard exit always active (global keydown listener)', function () {
    // O listener vive em `window`, não num elemento do fluxo: dispara mesmo com
    // um modal cobrindo header E disco. Registrado no mount e limpo no unmount.
    $src = File::get(base_path(PANIC_COMPONENT));

    expect($src)->toContain("window.addEventListener('keydown', onKeydown)")
        ->and($src)->toContain("window.removeEventListener('keydown', onKeydown)");
});

it('requires a double-escape to exit, never a lone escape', function () {
    // Um Escape sozinho fecha modal e não pode virar evasão acidental. A saída só
    // dispara com dois Escapes dentro de DOUBLE_ESCAPE_MS.
    $src = File::get(base_path(PANIC_COMPONENT));

    expect($src)->toContain('DOUBLE_ESCAPE_MS')
        ->and($src)->toMatch("/event\.key\s*!==\s*'Escape'/");
});

it('exits without awaiting logout, with a non-restorable replace', function () {
    // A saída não prende o membro: logout fire-and-forget com keepalive (sobrevive
    // à navegação) e `location.replace` (o Voltar não devolve a Limen).
    $src = File::get(base_path(PANIC_COMPONENT));

    expect($src)->toContain('keepalive: true')
        ->and($src)->toContain('window.location.replace(target.value)');
});
