<?php

namespace App\Support;

use App\Models\User;

/**
 * "Estilo de Vida" do membro — escala ordenada, auto-declarada, opcional
 * (Sprint 10).
 *
 * Dona única do conjunto de faixas, dos rótulos e da regra de EXIBIÇÃO. Existe
 * como classe e não como config solto porque o dado tem duas pontas com
 * públicos opostos — o formulário do membro e o painel da performer — e as duas
 * precisam ler a mesma tabela. Uma lista copiada no Vue divergiria no primeiro
 * rótulo novo, e divergiria justo no lado que a performer lê.
 *
 * ── O que este campo é, e o que ele NÃO é ────────────────────────────────────
 * Diferente de `interests`/`seeking` (a outra metade da mesma tela), este campo
 * VOLTA para a performer — decisão do PO. Isso o coloca na mesma família do
 * FanAlias, e com uma ressalva que precisa estar escrita:
 *
 * **A faixa é GLOBAL, o pseudônimo é POR PAR.** O FanAlias deriva um apelido
 * diferente para cada (perfil, membro) exatamente para que duas performers não
 * consigam casar suas listas. A faixa não: o mesmo membro sai "Premium" para
 * todas elas. Duas performers comparando listas fora da plataforma ganham uma
 * chave de join de baixa entropia — 7 valores, sendo o mais comum "não
 * declarou" — que sozinha não identifica ninguém, mas ESTREITA o conjunto de
 * candidatos quando combinada com o que as duas telas já dão (faixa de horário
 * da visita, data de follow). É a mesma classe de problema do rosto na Foto
 * Efêmera (§ 1.1 do CLAUDE.md), várias ordens de grandeza mais fraca.
 *
 * Consequências práticas, e nenhuma delas é opcional:
 *  - O campo é OPCIONAL e o padrão é não declarar. Quem não declara não entra
 *    no join — por isso o default nunca pode virar uma faixa real.
 *  - A tela do membro DIZ que a performer vê, no momento do preenchimento e não
 *    nos Termos (mesma disciplina da Foto Efêmera).
 *  - Não vira filtro nem ordenação do catálogo (decisão do PO). Filtrar por
 *    faixa transformaria o campo em consulta — "me mostre os Patronos" — e uma
 *    consulta que devolve conjunto pequeno identifica muito mais do que um
 *    rótulo ao lado de um apelido.
 *  - Nunca sai numa superfície PÚBLICA. Ali não há sequer o piso de anonimato
 *    para segurar o join.
 *
 * ── Uma representação só para "não declarou" ─────────────────────────────────
 * `prefer_not_to_say` é o valor que o RADIO manda (o formulário precisa de algo
 * marcável por padrão), mas o que a coluna guarda é `null`. É o precedente do
 * `seeking` no ProfileController: duas representações do mesmo estado fazem
 * todo consumidor futuro tratar as duas, e uma delas seria esquecida — aqui a
 * esquecida vazaria "Prefiro não dizer" como se fosse faixa. A normalização
 * mora em normalize(), e labelFor() devolve null para os dois de qualquer
 * forma, que é o cinto e as suspensórias.
 */
final class LifestyleTier
{
    /** O valor que o formulário usa para "não quero declarar". Nunca é gravado. */
    public const NOT_DISCLOSED = 'prefer_not_to_say';

    /**
     * A escala, em ordem. A ORDEM é a semântica do campo: é escala, não
     * conjunto de tags combináveis (decisão do PO), e é ela que o formulário
     * renderiza de cima para baixo.
     *
     * @var array<string, array{label: string, description: string}>
     */
    private const SCALE = [
        'essencial' => [
            'label' => 'Essencial',
            'description' => 'Estou começando, valorizo conexão',
        ],
        'confortavel' => [
            'label' => 'Confortável',
            'description' => 'Vida estável, sem ostentação',
        ],
        'premium' => [
            'label' => 'Premium',
            'description' => 'Viajo, janto bem, invisto em experiências',
        ],
        'luxo' => [
            'label' => 'Luxo',
            'description' => 'Dinheiro não é limitação',
        ],
        'elite' => [
            'label' => 'Elite',
            'description' => 'Jato, iate, suíte presidencial',
        ],
        'patrono' => [
            'label' => 'Patrono',
            'description' => 'Mecenas — invisto em pessoas e projetos',
        ],
    ];

    /**
     * Os slugs que a COLUNA aceita — a escala mais o opt-out.
     *
     * `prefer_not_to_say` entra no enum do banco porque é o valor que o
     * formulário posta e a validação aceita; a aplicação normaliza para null
     * antes de gravar (ver normalize()), então na prática ele não aparece em
     * nenhuma linha. Está no enum para que a coluna não seja mais estreita do
     * que o vocabulário do produto — e para que uma escrita direta em SQL, num
     * fix de produção, não estoure com um valor que o app considera legítimo.
     *
     * @return array<int, string>
     */
    public static function storableValues(): array
    {
        return [self::NOT_DISCLOSED, ...array_keys(self::SCALE)];
    }

    /**
     * O que a VALIDAÇÃO aceita do request. É o mesmo conjunto do enum: o
     * formulário precisa poder voltar para "Prefiro não dizer" depois de ter
     * declarado uma faixa, e essa é a única operação que o titular não consegue
     * refazer por outro caminho.
     *
     * @return array<int, string>
     */
    public static function acceptedValues(): array
    {
        return self::storableValues();
    }

    /**
     * Valor do request → valor da coluna. `null` para tudo que signifique "não
     * declarou": ausente, vazio, ou o slug de opt-out.
     */
    public static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || $value === self::NOT_DISCLOSED) {
            return null;
        }

        return array_key_exists($value, self::SCALE) ? $value : null;
    }

    /**
     * Rótulo para a performer, ou `null` quando não há o que exibir.
     *
     * `null` é o contrato, e a tela nunca substitui por placeholder: "não
     * declarou" ao lado do apelido reporia o sinal que o campo opcional existe
     * para não dar — a performer saberia que aquele membro VIU o formulário e
     * recusou, que é informação sobre a pessoa. Ausência de rótulo cobre "nunca
     * abriu a tela" e "abriu e não quis dizer" com a mesma cara, pelo mesmo
     * motivo da copy ambígua do painel de visitantes (item 14 do CLAUDE.md).
     */
    public static function labelFor(?string $value): ?string
    {
        $value = self::normalize($value);

        return $value === null ? null : self::SCALE[$value]['label'];
    }

    /**
     * Rótulos de vários membros de uma vez: [user_id => label|null].
     *
     * Existe para as três superfícies da performer (seguidores, gorjetas,
     * visitantes) não fazerem N+1 nem, pior, cada uma resolver o rótulo do seu
     * jeito. Só os ids pedidos saem daqui; a chave continua sendo interna à
     * chamada — quem monta a linha da tela é o chamador, e é ele que troca o id
     * pelo FanAlias.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, string|null>
     */
    public static function labelsFor(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter($userIds)));

        if ($userIds === []) {
            return [];
        }

        // Query builder direto e não o model: `lifestyle_tier` é `$hidden` no
        // User, e o ponto do $hidden é que ele nunca saia por serialização
        // automática. Aqui a leitura é explícita e o rótulo — não o slug — é o
        // que segue para a tela.
        return User::query()
            ->whereIn('id', $userIds)
            ->pluck('lifestyle_tier', 'id')
            ->map(fn (?string $tier) => self::labelFor($tier))
            ->all();
    }

    /**
     * As opções do formulário, na ordem da escala, com o opt-out PRIMEIRO — ele
     * é o padrão, e um padrão que aparece no fim da lista é um padrão que o
     * membro descobre depois de já ter escolhido outra coisa.
     *
     * A descrição vai junto por decisão do PO: sem ela "Confortável" e
     * "Premium" são o mesmo adjetivo vago, e o membro escolhe pelo que a
     * palavra sugere em vez do que a faixa significa aqui.
     *
     * @return array<int, array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        $options = [[
            'value' => self::NOT_DISCLOSED,
            'label' => 'Prefiro não dizer',
            'description' => 'Nenhuma faixa aparece para as performers.',
        ]];

        foreach (self::SCALE as $value => $meta) {
            $options[] = ['value' => $value, ...$meta];
        }

        return $options;
    }

    /**
     * Valor da coluna → valor do formulário. O inverso de normalize(): a tela
     * precisa de um radio marcado, e `null` não marca nada.
     */
    public static function forForm(?string $value): string
    {
        return self::normalize($value) ?? self::NOT_DISCLOSED;
    }
}
