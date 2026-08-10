<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Lista canônica dos municípios brasileiros (IBGE, ~5.570), embutida no
 * projeto em `public/data/ibge-municipios.json` — dado público oficial,
 * self-hosted, NUNCA API externa (respeita o ExternalAssetPolicyTest).
 *
 * O front consome o MESMO arquivo por fetch relativo (`/data/ibge-municipios.json`)
 * para o autocomplete client-side do filtro; esta classe é a porta do BACKEND
 * (validação da cidade escolhida pela performer no editor de localização e
 * normalização acento-insensível). Fonte única do dado dos dois lados — um só
 * arquivo, sem cópia para divergir.
 *
 * A normalização aqui TEM que espelhar a do front (Str::ascii ≈ NFD+strip),
 * senão a cidade gravada e a buscada não casariam. Ver `normalizeCity` em
 * `resources/js/lib/brazilianCities.js`.
 */
final class BrazilianCities
{
    /** @var array<int, array{0:string,1:string}>|null */
    private static ?array $cache = null;

    /** @var array<string, true>|null  chave = "<cidade_normalizada>|<uf>" */
    private static ?array $index = null;

    public const DATA_PATH = 'data/ibge-municipios.json';

    /**
     * @return array<int, array{0:string,1:string}>  [ [nome, uf], ... ]
     */
    public static function all(): array
    {
        if (self::$cache === null) {
            $decoded = json_decode((string) @file_get_contents(public_path(self::DATA_PATH)), true);
            self::$cache = is_array($decoded) ? $decoded : [];
        }

        return self::$cache;
    }

    /**
     * Normalização canônica: sem acento, minúscula, espaços colapsados.
     * "SÃO  PAULO" e "sao paulo" viram a mesma chave. Espelha o JS do front.
     */
    public static function normalize(string $name): string
    {
        return Str::of($name)->ascii()->lower()->squish()->value();
    }

    /**
     * A cidade existe no IBGE? Com UF, exige o par exato (desambigua homônimos
     * — 232 nomes se repetem entre UFs). Sem UF, basta o nome existir em alguma.
     */
    public static function exists(string $name, ?string $uf = null): bool
    {
        if (self::$index === null) {
            self::$index = [];
            foreach (self::all() as [$cityName, $cityUf]) {
                self::$index[self::normalize($cityName).'|'.$cityUf] = true;
            }
        }

        $normalized = self::normalize($name);

        if ($uf !== null) {
            return isset(self::$index[$normalized.'|'.strtoupper($uf)]);
        }

        foreach (self::$index as $key => $_) {
            if (str_starts_with($key, $normalized.'|')) {
                return true;
            }
        }

        return false;
    }
}
