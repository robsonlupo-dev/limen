<script setup>
/**
 * SkeletonCard — molde de carregamento com brilho (feat/catalog-skeleton-shimmer).
 *
 * O "esqueleto" que aparece enquanto as fotos do catálogo ainda não chegaram.
 * Substitui o `animate-pulse` (piscar de opacidade) por um brilho dourado que
 * varre o card (classe `.mi-skeleton`, definida em micro-interactions.css). A
 * página parece já estar viva, não travada. Sob `prefers-reduced-motion` o
 * brilho vira um pulsar suave — o guard mora no CSS, não aqui.
 *
 * Duas proporções, para casar com os dois catálogos sem inventar layout:
 *   - `portrait` (padrão): retrato 3/4 puro — catálogo de performers (member) e
 *     de membros (performer).
 *   - `card`: foto 4/3 + duas linhas de texto num card com borda — a grade de
 *     performers com legenda.
 *
 * Puramente decorativo: `aria-hidden`, sem texto, sem foco.
 */
defineProps({
    variant: {
        type: String,
        default: 'portrait',
        validator: (v) => ['portrait', 'card'].includes(v),
    },
})
</script>

<template>
    <!-- Card com legenda -->
    <div
        v-if="variant === 'card'"
        aria-hidden="true"
        class="overflow-hidden rounded-xl border border-limen-line bg-limen-surface"
    >
        <div class="mi-skeleton aspect-[4/3]" />
        <div class="space-y-2 p-4">
            <div class="mi-skeleton h-4 w-3/4 rounded" />
            <div class="mi-skeleton h-3 w-1/2 rounded" />
        </div>
    </div>

    <!-- Retrato puro -->
    <div
        v-else
        aria-hidden="true"
        class="mi-skeleton aspect-[3/4] rounded-xl"
    />
</template>
