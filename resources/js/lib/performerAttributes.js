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

// Espelha PerformerProfile::HEIGHT_MIN_CM / HEIGHT_MAX_CM.
export const HEIGHT_MIN_CM = 140
export const HEIGHT_MAX_CM = 190

// Slug → rótulo, achatado. Para as telas que exibem tags sem se importar com o
// grupo (perfil público, chips do card).
const TAG_LABELS = Object.fromEntries(
    TAG_GROUPS.flatMap((group) => group.tags).map((tag) => [tag.value, tag.label]),
)

export function tagLabel(slug) {
    return TAG_LABELS[slug] ?? slug
}
