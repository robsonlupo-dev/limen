// Rótulos dos campos "Sobre mim" da performer (Sprint 9).
//
// Espelha as constantes de PerformerProfile: TAG_GROUPS, LANGUAGES, DRINKS,
// SMOKES e a faixa de altura. O servidor é quem valida (Rule::in no
// UpdatePerformerProfileRequest) — isto aqui é só a tradução slug → rótulo,
// e existe num arquivo só porque a tela de edição e as telas de exibição
// (perfil, cards) precisam da mesma tabela.
//
// Ao acrescentar uma tag: ela entra em PerformerProfile::TAG_GROUPS (validação)
// E aqui (rótulo). Sem o rótulo o chip renderiza o slug cru.

export const TAG_GROUPS = [
    {
        key: 'estilo_de_vida',
        label: 'Estilo de vida',
        tags: [
            { value: 'viajante', label: 'Viajante' },
            { value: 'fitness', label: 'Fitness' },
            { value: 'gourmet', label: 'Gourmet' },
            { value: 'praia', label: 'Praia' },
            { value: 'arte', label: 'Arte' },
            { value: 'musica', label: 'Música' },
            { value: 'moda', label: 'Moda' },
            { value: 'yoga', label: 'Yoga' },
            { value: 'games', label: 'Games' },
            { value: 'aventura', label: 'Aventura' },
            { value: 'festa', label: 'Festa' },
            { value: 'luxo', label: 'Luxo' },
        ],
    },
    {
        key: 'personalidade',
        label: 'Personalidade',
        tags: [
            { value: 'extrovertida', label: 'Extrovertida' },
            { value: 'misteriosa', label: 'Misteriosa' },
            { value: 'divertida', label: 'Divertida' },
            { value: 'intelectual', label: 'Intelectual' },
            { value: 'carinhosa', label: 'Carinhosa' },
            { value: 'discreta', label: 'Discreta' },
            { value: 'apaixonada', label: 'Apaixonada' },
            { value: 'dominante', label: 'Dominante' },
            { value: 'submissa', label: 'Submissa' },
        ],
    },
    {
        key: 'oferece',
        label: 'O que ofereço',
        tags: [
            { value: 'conversa', label: 'Conversa' },
            { value: 'companhia', label: 'Companhia' },
            { value: 'conteudo_exclusivo', label: 'Conteúdo exclusivo' },
            { value: 'live', label: 'Live' },
            { value: 'fantasia', label: 'Fantasia' },
            { value: 'roleplay', label: 'Roleplay' },
            { value: 'danca', label: 'Dança' },
            { value: 'striptease', label: 'Striptease' },
        ],
    },
]

// Espelha PerformerProfile::MAX_TAGS.
export const MAX_TAGS = 8

export const LANGUAGES = [
    { value: 'portugues', label: 'Português' },
    { value: 'ingles', label: 'Inglês' },
    { value: 'espanhol', label: 'Espanhol' },
    { value: 'frances', label: 'Francês' },
    { value: 'italiano', label: 'Italiano' },
    { value: 'alemao', label: 'Alemão' },
    { value: 'japones', label: 'Japonês' },
]

export const DRINKS = [
    { value: 'nao_bebe', label: 'Não bebe' },
    { value: 'bebe_socialmente', label: 'Bebe socialmente' },
    { value: 'bebe_frequentemente', label: 'Bebe frequentemente' },
]

export const SMOKES = [
    { value: 'nao_fuma', label: 'Não fuma' },
    { value: 'fuma_socialmente', label: 'Fuma socialmente' },
    { value: 'fuma', label: 'Fuma' },
]

// Slug → rótulo para os campos de escolha do "Sobre mim". A tela de edição
// consome as listas acima (LANGUAGES/DRINKS/SMOKES) para desenhar os controles;
// as telas de EXIBIÇÃO só têm o slug guardado e precisam traduzi-lo — mesma
// tabela, mesma razão do `tagLabel`. Sem rótulo, cai no slug cru.
const LANGUAGE_LABELS = Object.fromEntries(LANGUAGES.map((l) => [l.value, l.label]))
const DRINK_LABELS = Object.fromEntries(DRINKS.map((d) => [d.value, d.label]))
const SMOKE_LABELS = Object.fromEntries(SMOKES.map((s) => [s.value, s.label]))

export function languageLabel(slug) {
    return LANGUAGE_LABELS[slug] ?? slug
}

export function drinkLabel(slug) {
    return DRINK_LABELS[slug] ?? slug
}

export function smokeLabel(slug) {
    return SMOKE_LABELS[slug] ?? slug
}

// Ícone por escolha, só para a EXIBIÇÃO — a tela de edição não usa emoji. Fica
// aqui, junto do rótulo, para os dois perfis públicos não divergirem no primeiro
// valor novo. Fallback neutro para slug desconhecido.
const DRINK_ICONS = {
    nao_bebe: '🚫',
    bebe_socialmente: '🍷',
    bebe_frequentemente: '🍸',
}

const SMOKE_ICONS = {
    nao_fuma: '🚭',
    fuma_socialmente: '🚬',
    fuma: '🚬',
}

export function drinkIcon(slug) {
    return DRINK_ICONS[slug] ?? '🥂'
}

export function smokeIcon(slug) {
    return SMOKE_ICONS[slug] ?? '🚬'
}

/**
 * Agrupa os slugs de tag da performer pela mesma estrutura de TAG_GROUPS que a
 * tela de edição usa, para o perfil público exibir as pílulas na mesma ordem e
 * sob os mesmos títulos. Grupo sem nenhuma tag selecionada é omitido; slug
 * desconhecido (tag removida do catálogo) é ignorado, não vira chip cru.
 */
export function groupTags(slugs) {
    const selected = new Set(slugs ?? [])
    return TAG_GROUPS.map((group) => ({
        key: group.key,
        label: group.label,
        tags: group.tags.filter((tag) => selected.has(tag.value)),
    })).filter((group) => group.tags.length > 0)
}

// Espelha PerformerProfile::HEIGHT_MIN_CM / HEIGHT_MAX_CM.
export const HEIGHT_MIN_CM = 140
export const HEIGHT_MAX_CM = 190

// As 26 UFs + DF. Espelha PerformerProfile::STATES, que é quem valida.
//
// O `value` (UF) é o que trafega e o que fica no banco; o `label` só existe
// porque o PERFIL exibe o nome por extenso ("Estado: São Paulo") enquanto o
// CARD exibe a sigla. Duas grafias da mesma coisa, uma tabela só.
//
// Não existe lista de cidades aqui, e não deve passar a existir: `city` é campo
// interno e não chega ao frontend (ver PerformerPublicResource).
export const STATES = [
    { value: 'AC', label: 'Acre' },
    { value: 'AL', label: 'Alagoas' },
    { value: 'AM', label: 'Amazonas' },
    { value: 'AP', label: 'Amapá' },
    { value: 'BA', label: 'Bahia' },
    { value: 'CE', label: 'Ceará' },
    { value: 'DF', label: 'Distrito Federal' },
    { value: 'ES', label: 'Espírito Santo' },
    { value: 'GO', label: 'Goiás' },
    { value: 'MA', label: 'Maranhão' },
    { value: 'MG', label: 'Minas Gerais' },
    { value: 'MS', label: 'Mato Grosso do Sul' },
    { value: 'MT', label: 'Mato Grosso' },
    { value: 'PA', label: 'Pará' },
    { value: 'PB', label: 'Paraíba' },
    { value: 'PE', label: 'Pernambuco' },
    { value: 'PI', label: 'Piauí' },
    { value: 'PR', label: 'Paraná' },
    { value: 'RJ', label: 'Rio de Janeiro' },
    { value: 'RN', label: 'Rio Grande do Norte' },
    { value: 'RO', label: 'Rondônia' },
    { value: 'RR', label: 'Roraima' },
    { value: 'RS', label: 'Rio Grande do Sul' },
    { value: 'SC', label: 'Santa Catarina' },
    { value: 'SE', label: 'Sergipe' },
    { value: 'SP', label: 'São Paulo' },
    { value: 'TO', label: 'Tocantins' },
]

const STATE_LABELS = Object.fromEntries(STATES.map((state) => [state.value, state.label]))

/** Nome do estado por extenso; cai na própria UF se ela for desconhecida. */
export function stateLabel(uf) {
    return STATE_LABELS[uf] ?? uf
}

// Espelha PerformerProfile::TIERS. Curadoria concedida por admin — a performer
// não escolhe o seu. Aparece aqui porque o filtro do catálogo (Sprint 9) a
// oferece como faceta; o rótulo é o mesmo vocabulário que o PO usa no produto.
export const TIERS = [
    { value: 'verificada', label: 'Verificada' },
    { value: 'select', label: 'Select' },
    { value: 'maison', label: 'Maison' },
]

const TIER_LABELS = Object.fromEntries(TIERS.map((tier) => [tier.value, tier.label]))

/**
 * Os tiers que ganham SELO no card e no perfil.
 *
 * `verificada` fica de fora de propósito: é o tier base, e o selo dourado ao
 * lado do nome mais a pílula verde "Verificada" abaixo já dizem exatamente
 * isso. Um terceiro sinal para a mesma informação é ruído — é a mesma razão
 * que tirou a pílula dourada solta do card público (ver VerificationBadges).
 *
 * O FILTRO continua oferecendo os três (TIERS): filtrar por "Verificada" é
 * pedido legítimo, e é diferente de carimbar o card de todo mundo.
 */
export const BADGED_TIERS = ['select', 'maison']

/** Rótulo do tier, ou null quando ele não deve virar selo. */
export function tierBadgeLabel(slug) {
    return BADGED_TIERS.includes(slug) ? (TIER_LABELS[slug] ?? null) : null
}

// Os grupos de tag que o FILTRO do catálogo oferece. É um subconjunto
// deliberado (decisão do PO): personalidade descreve quem a performer é, e
// filtrar por isso empurra o membro a tratar traço de pessoa como faceta de
// busca. Estilo de vida e o que ela oferece são o que se procura.
//
// A validação do servidor aceita QUALQUER tag do catálogo (allTags), então
// abrir personalidade depois é acrescentar a chave aqui — não mexe no backend.
export const FILTERABLE_TAG_GROUP_KEYS = ['estilo_de_vida', 'oferece']

export const FILTERABLE_TAG_GROUPS = TAG_GROUPS.filter((group) =>
    FILTERABLE_TAG_GROUP_KEYS.includes(group.key),
)

// Slug → rótulo, achatado. Para as telas que exibem tags sem se importar com o
// grupo (perfil público, chips do card).
const TAG_LABELS = Object.fromEntries(
    TAG_GROUPS.flatMap((group) => group.tags).map((tag) => [tag.value, tag.label]),
)

export function tagLabel(slug) {
    return TAG_LABELS[slug] ?? slug
}
