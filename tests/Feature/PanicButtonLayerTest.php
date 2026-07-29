<?php

use Illuminate\Support\Facades\File;

// Guarda do invariante de camada do PanicButton.
//
// A saída rápida é feature de segurança física: tira a Limen da tela quando
// alguém entra na sala. Ela só funciona se NADA puder cobri-la — e "nada" é uma
// afirmação sobre o projeto inteiro, não sobre um componente. Já foi violada
// duas vezes em silêncio (Modal.vue empatando em z-50 com ordem de DOM a favor,
// e o overlay de onboarding em z-[9000]), porque não existia nada que quebrasse.
//
// O projeto não tem Vitest/Jest, então este teste roda pela fonte. É grosseiro
// de propósito: casa a DECLARAÇÃO de z-index (CSS `z-index: N` e a classe
// arbitrária `z-[N]` do Tailwind), nunca prosa de comentário.

// 10001 e não 10000: o teto anterior do projeto já era 10000 (IntroAnimation,
// com AgeGateModal logo abaixo em 9999, nessa ordem de propósito — a splash
// cobre o gate 18+ até terminar). O botão entra UM acima desse teto, em vez de
// empurrar os dois para baixo e mexer no gate de idade sem necessidade.
// Consequência: 10000 e 9999 seguem permitidos e ficam logo abaixo do botão.
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

it('keeps the panic button on the top layer', function () {
    // Casa o ATRIBUTO class, não a fonte inteira: o comentário do componente
    // cita `z-[10000]` em prosa, e um `toContain` cru continuaria verde se
    // alguém apagasse a classe e deixasse o comentário para trás.
    expect(File::get(base_path(PANIC_COMPONENT)))
        ->toMatch('/class="[^"]*\bz-\['.PANIC_LAYER.'\]/');
});

it('teleports the panic button out of any ancestor stacking context', function () {
    // z-index alto não basta: um `transform`/`filter` num ancestral cria
    // stacking context e prende o botão lá dentro, por mais alto que seja o
    // número. O Teleport é o que garante que ele vive na raiz.
    expect(File::get(base_path(PANIC_COMPONENT)))->toContain('<Teleport to="body">');
});

it('reserves the panic layer and above for the panic button alone', function () {
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
    // suba o botão. Do outro lado do empate está a segurança física do usuário.
    expect($offenders)->toBe([]);
});
