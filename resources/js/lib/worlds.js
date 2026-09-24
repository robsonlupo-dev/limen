// The four public "worlds" surfaced on the unauthenticated catalog. Kept in one
// place so labels/icons stay consistent across the grid, filters and profile.
export const PUBLIC_WORLDS = ['mulheres', 'homens', 'casais', 'trans']

export const WORLD_LABELS = {
    mulheres: 'Mulheres',
    homens: 'Homens',
    casais: 'Casais',
    trans: 'Trans',
}

// Ícones: são SVG, no componente `WorldIcon` (`<WorldIcon :world="value" />`,
// null = "Todos") — não glifos Unicode aqui. ♀ ♂ ⚭ ⚧ faltam em muitas fontes de
// sistema e ⚧ vira emoji em alguns aparelhos (fix/emoji-to-svg-round-2).

// Concordância de gênero/número do selo de verificação, por mundo. Vive aqui e
// não dentro dos componentes porque DOIS deles escrevem a palavra — o selo
// dourado ao lado do nome (VerifiedBadge) e a pílula verde abaixo
// (VerificationBadges). Duas cópias divergiriam no primeiro mundo novo.
//
// Fallback no feminino: é a maioria do catálogo, e mundo desconhecido só chega
// aqui por dado fora de PerformerProfile::WORLDS.
const VERIFIED_LABELS = {
    mulheres: { label: 'Verificada', title: 'Performer verificada' },
    trans: { label: 'Verificada', title: 'Performer verificada' },
    homens: { label: 'Verificado', title: 'Performer verificado' },
    casais: { label: 'Verificados', title: 'Performers verificados' },
}

export function verifiedLabel(category) {
    return VERIFIED_LABELS[category] ?? VERIFIED_LABELS.mulheres
}

// Filter pills for the public catalog: "Todos" (no filter) + one per world.
// The icon is rendered by `WorldIcon` from `value`.
export const WORLD_FILTERS = [
    { value: null, label: 'Todos' },
    ...PUBLIC_WORLDS.map((value) => ({ value, label: WORLD_LABELS[value] })),
]
