<script setup>
// Fileira de capas de Destaques no perfil (roadmap social, Onda 1a). Vitrine
// pública: cada coleção é um círculo com a capa (1º item) e o título embaixo.
defineProps({
    highlights: { type: Array, default: () => [] }, // [{ id, title, cover_url, items:[{id,url}] }]
})
const emit = defineEmits(['open'])
</script>

<template>
    <div v-if="highlights.length" class="flex gap-4 overflow-x-auto pb-1">
        <button
            v-for="h in highlights"
            :key="h.id"
            type="button"
            class="flex w-16 shrink-0 flex-col items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-limen-gold/60 rounded-lg"
            @click="emit('open', h)"
        >
            <span class="block h-16 w-16 overflow-hidden rounded-full border border-limen-gold/40 p-0.5">
                <img :src="h.cover_url" :alt="h.title" class="h-full w-full rounded-full object-cover" loading="lazy" />
            </span>
            <span class="max-w-full truncate text-xs text-limen-ink-soft">{{ h.title }}</span>
        </button>
    </div>
</template>
