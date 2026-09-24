<script setup>
/**
 * Ícone do TOKEN (moeda) em SVG — substitui o emoji 🪙 que renderizava diferente
 * entre sistemas (regra do PO: ícone de interface é SVG, nunca emoji). Herda a
 * cor via currentColor; o tamanho vem do `class` do chamador (ex.: h-4 w-4).
 * `aria-hidden` — é decorativo; o número ao lado é o conteúdo.
 */
</script>

<template>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="12" cy="12" r="9" />
        <path d="M12 7.4v9.2" />
        <path d="M14.3 9.3a2.5 2.5 0 0 0-2.3-1.4c-1.35 0-2.3.85-2.3 1.95 0 2.5 4.6 1.3 4.6 3.9 0 1.1-1 1.95-2.35 1.95a2.6 2.6 0 0 1-2.35-1.45" />
    </svg>
</template>
