<script setup>
/**
 * Ícone de reação a story (roadmap social, Onda 1b). SVG, NUNCA emoji — regra do
 * PO: emoji renderiza diferente (ou vira quadrado) entre aparelhos. Fonte única
 * dos glifos das reações; usado no viewer (botoeira) e no painel da performer.
 *
 * `filled` pinta o interior (estado ativo/selecionado); senão é só contorno.
 * Slug desconhecido não desenha nada (fail-safe: o conjunto vem do config).
 */
defineProps({
    slug: { type: String, required: true },
    filled: { type: Boolean, default: false },
})
</script>

<template>
    <svg
        class="h-5 w-5"
        viewBox="0 0 24 24"
        :fill="filled ? 'currentColor' : 'none'"
        stroke="currentColor"
        stroke-width="1.8"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
    >
        <path v-if="slug === 'love'" d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z" />
        <path v-else-if="slug === 'fire'" d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5Z" />
        <path v-else-if="slug === 'wow'" d="M11.5 2.6a.5.5 0 0 1 .95 0l2.2 4.46a.5.5 0 0 0 .38.27l4.92.72a.5.5 0 0 1 .28.85l-3.56 3.47a.5.5 0 0 0-.15.44l.84 4.9a.5.5 0 0 1-.73.53l-4.4-2.31a.5.5 0 0 0-.46 0l-4.4 2.31a.5.5 0 0 1-.72-.53l.84-4.9a.5.5 0 0 0-.15-.44L3.02 9.4a.5.5 0 0 1 .28-.85l4.92-.72a.5.5 0 0 0 .38-.27Z" />
        <template v-else-if="slug === 'celebrate'">
            <path d="M9.9 15.5A2 2 0 0 0 8.5 14.1l-6.1-1.6a.5.5 0 0 1 0-.96L8.5 9.9A2 2 0 0 0 9.9 8.5l1.6-6.1a.5.5 0 0 1 .96 0L14.1 8.5A2 2 0 0 0 15.5 9.9l6.1 1.6a.5.5 0 0 1 0 .96L15.5 14.1a2 2 0 0 0-1.4 1.4l-1.6 6.1a.5.5 0 0 1-.96 0Z" />
            <path d="M19 3v3" />
            <path d="M20.5 4.5h-3" />
        </template>
    </svg>
</template>
