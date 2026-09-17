<script setup>
import { computed } from 'vue'

/**
 * Placeholder "Em breve" (Sprint 15, PR #144) — ocupa o lugar de uma feature
 * premium em dark launch (live, chamada) enquanto a flag está desligada em
 * produção. Quando o advogado liberar, muda o .env e o componente real aparece no
 * lugar (zero deploy de código).
 *
 * Visual: opacidade 50%, cursor not-allowed, borda dourada sutil (gold/30) para
 * sinalizar "feature premium chegando", tooltip "Disponível em breve". Não é
 * clicável (é um selo, não um botão).
 */
const props = defineProps({
    // Chave do ícone: 'camera' (live) ou 'phone' (chamada).
    icon: { type: String, default: 'camera' },
    // Rótulo da ação que virá (ex.: "Assistir live", "Chamada privada").
    label: { type: String, required: true },
    // Texto complementar opcional abaixo do rótulo.
    hint: { type: String, default: '' },
    tooltip: { type: String, default: 'Disponível em breve' },
})

// Ícone de interface é SVG, nunca emoji (regra do CLAUDE.md — emoji quebra/destoa
// entre sistemas, como o símbolo do token virando quadrado). `path` por chave.
const ICON_PATHS = {
    camera: 'M15.5 10.5 20 8v8l-4.5-2.5M4 6.5h9a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-9a1 1 0 0 1 1-1Z',
    phone: 'M6.5 3.5h3l1.5 4-2 1.5a12 12 0 0 0 5 5l1.5-2 4 1.5v3a2 2 0 0 1-2 2A15.5 15.5 0 0 1 4.5 5.5a2 2 0 0 1 2-2Z',
}
const iconPath = computed(() => ICON_PATHS[props.icon] ?? ICON_PATHS.camera)
</script>

<template>
    <div
        :title="tooltip"
        role="note"
        :aria-label="`${label} — em breve`"
        class="flex cursor-not-allowed items-center gap-3 rounded-lg border border-gold/30 bg-surface px-4 py-2.5 opacity-50 select-none"
    >
        <svg aria-hidden="true" class="h-5 w-5 shrink-0 text-gold" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
            <path stroke-linecap="round" stroke-linejoin="round" :d="iconPath" />
        </svg>
        <span class="flex flex-col">
            <span class="text-sm font-medium text-cream">{{ label }}</span>
            <span class="text-[11px] uppercase tracking-wide text-gold">Em breve<template v-if="hint"> — {{ hint }}</template></span>
        </span>
    </div>
</template>
