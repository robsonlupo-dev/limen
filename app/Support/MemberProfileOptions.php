<?php

namespace App\Support;

/**
 * Listas controladas do perfil PÚBLICO do membro (feat/member-profile-v2): "o que
 * busco", interesses, estado civil e altura. Dona única do vocabulário e dos
 * rótulos — o formulário do membro e a página de perfil vista pela performer leem
 * a MESMA tabela. Uma lista copiada no Vue divergiria no primeiro slug novo, e o
 * chip renderizaria o slug cru justo no lado que a performer vê.
 *
 * NÃO confundir com `PerformerProfile::TAGS` (o conjunto que a performer usa e que
 * o `member_interest` PRIVADO espelha para afinidade). Estes são campos PÚBLICOS,
 * de exibição, distintos de propósito do canal privado de afinidade.
 *
 * Só EXIBIÇÃO: nada aqui vira filtro nem ordenação. Guardado como array de slugs
 * (JSON na coluna); o rótulo vem daqui na hora de montar o payload.
 */
final class MemberProfileOptions
{
    /**
     * "O que busco" — intenção do membro na plataforma. ~10 opções.
     *
     * @var array<string, string>
     */
    private const SEEKING = [
        'conversa' => 'Conversa',
        'conexao' => 'Conexão',
        'amizade' => 'Amizade',
        'companhia' => 'Companhia',
        'presentear' => 'Presentear',
        'chamadas' => 'Chamadas de vídeo',
        'trocar_mensagens' => 'Trocar mensagens',
        'relacionamento_virtual' => 'Relacionamento virtual',
        'exclusividade' => 'Exclusividade',
        'mentoria' => 'Mentoria e conselhos',
    ];

    /**
     * Interesses / estilo de vida. ~18 opções.
     *
     * @var array<string, string>
     */
    private const INTERESTS = [
        'viagens' => 'Viagens',
        'musica' => 'Música',
        'gastronomia' => 'Gastronomia',
        'vinhos' => 'Vinhos & drinks',
        'cinema' => 'Cinema & séries',
        'esportes' => 'Esportes',
        'fitness' => 'Fitness',
        'games' => 'Games',
        'arte' => 'Arte & cultura',
        'moda' => 'Moda',
        'leitura' => 'Leitura',
        'fotografia' => 'Fotografia',
        'tecnologia' => 'Tecnologia',
        'natureza' => 'Natureza',
        'danca' => 'Dança',
        'pets' => 'Animais de estimação',
        'negocios' => 'Negócios',
        'bem_estar' => 'Bem-estar & yoga',
        'praia' => 'Praia',
        'vida_noturna' => 'Vida noturna',
    ];

    /**
     * Estado civil. Sem texto livre — select controlado.
     *
     * @var array<string, string>
     */
    private const MARITAL = [
        'solteiro' => 'Solteiro(a)',
        'namorando' => 'Namorando',
        'casado' => 'Casado(a)',
        'relacao_aberta' => 'Relação aberta',
        'divorciado' => 'Divorciado(a)',
        'viuvo' => 'Viúvo(a)',
    ];

    /** Alturas em cm, em passos de 5 (faixa grossa, nunca precisão que identifique). */
    private const HEIGHTS = [150, 155, 160, 165, 170, 175, 180, 185, 190, 195, 200];

    /** Teto de tags "o que busco" que o membro escolhe. */
    public const MAX_SEEKING = 6;

    /** Teto de interesses. */
    public const MAX_INTERESTS = 10;

    // ─── Slugs aceitos pela validação ────────────────────────────────────────

    /** @return array<int, string> */
    public static function seekingSlugs(): array
    {
        return array_keys(self::SEEKING);
    }

    /** @return array<int, string> */
    public static function interestSlugs(): array
    {
        return array_keys(self::INTERESTS);
    }

    /** @return array<int, string> */
    public static function maritalSlugs(): array
    {
        return array_keys(self::MARITAL);
    }

    /** @return array<int, int> */
    public static function heightValues(): array
    {
        return self::HEIGHTS;
    }

    // ─── Opções para o formulário [{value,label}] ────────────────────────────

    /** @return array<int, array{value: string, label: string}> */
    public static function seekingOptions(): array
    {
        return self::pairs(self::SEEKING);
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function interestOptions(): array
    {
        return self::pairs(self::INTERESTS);
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function maritalOptions(): array
    {
        return self::pairs(self::MARITAL);
    }

    /** @return array<int, array{value: int, label: string}> */
    public static function heightOptions(): array
    {
        return array_map(
            fn (int $cm) => ['value' => $cm, 'label' => self::heightLabel($cm)],
            self::HEIGHTS,
        );
    }

    // ─── Slug → rótulo (montagem do payload da performer) ─────────────────────

    /**
     * Rótulos, na ORDEM canônica da lista (não na ordem que o membro salvou —
     * a lista é apresentação, não ranking), ignorando slugs desconhecidos.
     *
     * @param  array<int, string>|null  $slugs
     * @return array<int, string>
     */
    public static function seekingLabels(?array $slugs): array
    {
        return self::labels(self::SEEKING, $slugs);
    }

    /**
     * @param  array<int, string>|null  $slugs
     * @return array<int, string>
     */
    public static function interestLabels(?array $slugs): array
    {
        return self::labels(self::INTERESTS, $slugs);
    }

    public static function maritalLabel(?string $slug): ?string
    {
        return $slug === null ? null : (self::MARITAL[$slug] ?? null);
    }

    public static function heightLabel(?int $cm): ?string
    {
        if ($cm === null || ! in_array($cm, self::HEIGHTS, true)) {
            return null;
        }

        // "1,75 m" — separador decimal pt-BR.
        return number_format($cm / 100, 2, ',', '.').' m';
    }

    // ─── Internos ─────────────────────────────────────────────────────────────

    /**
     * @param  array<string, string>  $map
     * @return array<int, array{value: string, label: string}>
     */
    private static function pairs(array $map): array
    {
        $out = [];
        foreach ($map as $value => $label) {
            $out[] = ['value' => $value, 'label' => $label];
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $map
     * @param  array<int, string>|null  $slugs
     * @return array<int, string>
     */
    private static function labels(array $map, ?array $slugs): array
    {
        if (empty($slugs)) {
            return [];
        }

        $chosen = array_flip($slugs);
        $out = [];
        foreach ($map as $slug => $label) {
            if (array_key_exists($slug, $chosen)) {
                $out[] = $label;
            }
        }

        return $out;
    }
}
