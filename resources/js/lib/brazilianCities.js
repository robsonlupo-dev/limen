// Autocomplete de município (IBGE) — client-side.
//
// A lista dos ~5.570 municípios vive SELF-HOSTED em `public/data/ibge-municipios.json`
// (dado público oficial), servida por fetch RELATIVO — nunca API externa, para
// respeitar o ExternalAssetPolicyTest. O backend lê o MESMO arquivo
// (App\Support\BrazilianCities): uma fonte só, sem cópia para divergir.
//
// Carregado sob demanda (só quando o filtro de cidade é usado) e memoizado, para
// não pesar o bundle nem bater no servidor a cada tecla: a busca é toda client-side.

const DATA_URL = '/data/ibge-municipios.json'

let loadPromise = null
// Índice de busca: [{ name, uf, key }] — `key` é o nome normalizado (sem acento,
// minúsculo), calculado uma vez no load.
let index = null

/**
 * Normalização canônica: sem acento, minúscula, espaços colapsados.
 * TEM que espelhar App\Support\BrazilianCities::normalize (Str::ascii ≈ NFD+strip)
 * — senão "São Paulo" gravado e "sao paulo" buscado não casariam.
 */
export function normalizeCity(value) {
    return String(value ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, "")
        .toLowerCase()
        .trim()
        .replace(/\s+/g, ' ')
}

/**
 * Carrega (uma vez) a base do IBGE e monta o índice de busca. Retorna a promise
 * memoizada — chamadas concorrentes compartilham o mesmo fetch.
 */
export function loadCities() {
    if (loadPromise) return loadPromise

    loadPromise = fetch(DATA_URL, { headers: { Accept: 'application/json' } })
        .then((res) => (res.ok ? res.json() : []))
        .then((rows) => {
            index = (Array.isArray(rows) ? rows : []).map(([name, uf]) => ({
                name,
                uf,
                key: normalizeCity(name),
            }))
            return index
        })
        .catch(() => {
            // Falha de rede não pode quebrar o filtro: cai em lista vazia (o
            // autocomplete não sugere nada, o resto do painel segue funcionando).
            index = []
            return index
        })

    return loadPromise
}

/**
 * Busca acento-insensível por PREFIXO e por CONTÉM, priorizando prefixo.
 * Só busca com `minChars`+ dígitos/letras normalizados. `uf`, quando passado,
 * restringe as sugestões àquela UF (uso no editor de localização, que já escolheu
 * o estado da linha). Retorna até `limit` itens { name, uf }.
 *
 * Requer loadCities() concluído; enquanto o índice não chegou, devolve [].
 */
export function searchCities(query, { uf = null, limit = 10, minChars = 3 } = {}) {
    const q = normalizeCity(query)
    if (q.length < minChars || index === null) return []

    const scoped = uf ? index.filter((c) => c.uf === uf) : index

    const prefix = []
    const contains = []
    for (const city of scoped) {
        const at = city.key.indexOf(q)
        if (at === 0) prefix.push(city)
        else if (at > 0) contains.push(city)
        if (prefix.length >= limit) break
    }

    return [...prefix, ...contains].slice(0, limit).map(({ name, uf: cityUf }) => ({ name, uf: cityUf }))
}
