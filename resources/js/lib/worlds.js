// The four public "worlds" surfaced on the unauthenticated catalog. Kept in one
// place so labels/icons stay consistent across the grid, filters and profile.
export const PUBLIC_WORLDS = ['mulheres', 'homens', 'casais', 'trans']

export const WORLD_LABELS = {
    mulheres: 'Mulheres',
    homens: 'Homens',
    casais: 'Casais',
    trans: 'Trans',
}

export const WORLD_ICONS = {
    mulheres: '♀',
    homens: '♂',
    casais: '⚭',
    trans: '⚧',
}

// Filter pills for the public catalog: "Todos" (no filter) + one per world.
export const WORLD_FILTERS = [
    { value: null, label: 'Todos', icon: '✦' },
    ...PUBLIC_WORLDS.map((value) => ({
        value,
        label: WORLD_LABELS[value],
        icon: WORLD_ICONS[value],
    })),
]
