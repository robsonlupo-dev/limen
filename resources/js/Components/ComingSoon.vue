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

const glyph = computed(() => ({ camera: '📹', phone: '📞' }[props.icon] ?? '📹'))
</script>

<template>
    <div
        :title="tooltip"
        role="note"
        :aria-label="`${label} — em breve`"
        class="flex cursor-not-allowed items-center gap-3 rounded-lg border border-gold/30 bg-surface px-4 py-2.5 opacity-50 select-none"
    >
        <span aria-hidden="true" class="text-xl">{{ glyph }}</span>
        <span class="flex flex-col">
            <span class="text-sm font-medium text-cream">{{ label }}</span>
            <span class="text-[11px] uppercase tracking-wide text-gold">Em breve<template v-if="hint"> — {{ hint }}</template></span>
        </span>
    </div>
</template>
