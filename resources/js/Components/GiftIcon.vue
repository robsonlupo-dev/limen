<script setup>
import { computed } from 'vue'

/**
 * Ícone de presente — SVG inline, monocromático (herda a cor via currentColor),
 * traço fino. Fonte ÚNICA das seis peças do catálogo da Limen (múltiplos de 4):
 * o catálogo (LiveViewer) e a animação da live (LiveOverlay) desenham daqui,
 * para não divergirem. NENHUM asset externo — só path data própria
 * (ExternalAssetPolicyTest).
 *
 * O tamanho e a cor vêm do PAI (classes de altura/largura e text-); aqui o SVG
 * preenche a caixa.
 * Slug desconhecido cai no genérico (embrulho de presente).
 */
const props = defineProps({
    slug: { type: String, required: true },
})

// Cada peça é uma lista de `d` de <path>. Traço fino, sem preenchimento.
const PATHS = {
    // Rosa: botão + miolo, caule e duas folhas.
    rosa: [
        'M12 4.2c-2 0-3.3 1.5-3.3 3.2 0 1.9 1.5 3.4 3.3 3.4s3.3-1.5 3.3-3.4c0-1.7-1.3-3.2-3.3-3.2z',
        'M12 6.2c1 0 1.7.8 1.7 1.8',
        'M12 10.8V19',
        'M12 14.6c-1.6 0-3-1-3.2-2.6 1.7-.1 3.2.9 3.2 2.6z',
        'M12 16.4c1.6 0 3-1 3.2-2.6-1.7-.1-3.2.9-3.2 2.6z',
    ],
    // Chocolate: bombom (base arredondada, cúpula e fiozinho no topo).
    chocolate: [
        'M5.5 10.6c0-1 .8-1.9 1.8-1.9h9.4c1 0 1.8.9 1.8 1.9v4.1a3 3 0 0 1-3 3H8.5a3 3 0 0 1-3-3z',
        'M7.6 8.7c0-2.1 2-3.5 4.4-3.5s4.4 1.4 4.4 3.5',
        'M10.7 6.5c.7-.7 1.9-.7 2.6 0',
    ],
    // Champagne: taça coupe com pé, base e duas bolhas.
    champagne: [
        'M6.6 4.6h10.8l-1.4 5.1a4.1 4.1 0 0 1-8 0z',
        'M12 14.4V19',
        'M8.9 19h6.2',
        'M12 6.4a.5.5 0 1 0 .01 0z',
        'M10.4 8a.4.4 0 1 0 .01 0z',
    ],
    // Joia: gema lapidada (hexágono), cintura e facetas da coroa.
    joia: [
        'M5.2 9l2.8-4.8h8L18.8 9 12 20z',
        'M5.2 9h13.6',
        'M8 4.2l4 4.8 4-4.8',
    ],
    // Coroa: três pontas, base fechada pelo Z.
    coroa: [
        'M5 17L4 7l4 4 4-5 4 5 4-4-1 10z',
        'M5 17h14',
    ],
    // Diamante: brilhante maior, cintura + facetas de coroa e pavilhão.
    diamante: [
        'M12 3l6 5.5-6 12.5-6-12.5z',
        'M6 8.5h12',
        'M9 8.5l3-5.5 3 5.5',
        'M9 8.5L12 21',
        'M15 8.5L12 21',
    ],
    // Genérico: embrulho de presente (fallback).
    generic: [
        'M4 9h16v10H4z',
        'M4 9h16',
        'M12 9v10',
        'M12 9c-1.5 0-4-.4-4-2.3S10 4.5 12 9z',
        'M12 9c1.5 0 4-.4 4-2.3S14 4.5 12 9z',
    ],
}

const paths = computed(() => PATHS[props.slug] ?? PATHS.generic)
</script>

<template>
    <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.5"
        stroke-linecap="round"
        stroke-linejoin="round"
        class="h-full w-full"
        aria-hidden="true"
    >
        <path v-for="(d, i) in paths" :key="i" :d="d" />
    </svg>
</template>
