<script setup>
/**
 * Ícone SVG de "mundo" do catálogo (mulheres / homens / casais / trans) e o
 * asterisco de "Todos" (fix/emoji-to-svg-round-2).
 *
 * Substitui os glifos Unicode ♀ ♂ ⚭ ⚧ ✦ que viviam em lib/worlds.js e na
 * Landing: ⚭ e ⚧ faltam em muitas fontes de sistema (viram quadrado), e ⚧
 * ganha apresentação de emoji em alguns aparelhos — exatamente a inconsistência
 * que a regra "ícone é SVG, nunca emoji" existe para evitar. Traço em
 * currentColor, então herda a cor do botão (dourado quando ativo).
 */
defineProps({
    // null / undefined → "Todos".
    world: { type: String, default: null },
})
</script>

<template>
    <svg class="inline-block h-[1em] w-[1em] shrink-0 align-[-0.125em]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <!-- Mulheres: Vênus -->
        <template v-if="world === 'mulheres'">
            <circle cx="12" cy="9" r="5" />
            <path d="M12 14v7M9 18h6" />
        </template>
        <!-- Homens: Marte -->
        <template v-else-if="world === 'homens'">
            <circle cx="10" cy="14" r="5" />
            <path d="M13.5 10.5L20 4M14 4h6v6" />
        </template>
        <!-- Casais: dois anéis entrelaçados -->
        <template v-else-if="world === 'casais'">
            <circle cx="9" cy="12" r="5.5" />
            <circle cx="15" cy="12" r="5.5" />
        </template>
        <!-- Trans: círculo com seta (Marte) e cruz (Vênus) -->
        <template v-else-if="world === 'trans'">
            <circle cx="12" cy="12" r="4.5" />
            <path d="M15.2 8.8L20 4M16 4h4v4" />
            <path d="M12 16.5V22M9.5 19.5h5" />
        </template>
        <!-- Todos: asterisco de quatro pontas -->
        <template v-else>
            <path d="M12 3v18M3 12h18M6.5 6.5l11 11M17.5 6.5l-11 11" />
        </template>
    </svg>
</template>
