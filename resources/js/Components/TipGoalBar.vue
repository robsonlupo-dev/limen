<script setup>
/**
 * Barra de meta de gorjeta (roadmap social, Onda 4 §4.2). Leitura AGREGADA: título,
 * arrecadado / alvo em tokens e uma barra (%). NUNCA "quem deu" — só soma + alvo.
 *
 * Serve as duas superfícies (perfil e overlay da live), que são escuras. `compact`
 * encolhe para o overlay sobre o vídeo. Os números já vêm do servidor (tokens de
 * gorjeta arrecadados desde o início da meta); aqui é só apresentação.
 */
import { computed } from 'vue'
import { formatTokens } from '@/lib/tokens'

const props = defineProps({
    // { title, target, raised, pct } — ou null quando não há meta ativa.
    goal: { type: Object, default: null },
    compact: { type: Boolean, default: false },
})

const pct = computed(() => Math.max(0, Math.min(100, Number(props.goal?.pct ?? 0))))
const reached = computed(() => pct.value >= 100)
</script>

<template>
    <div
        v-if="goal"
        class="rounded-2xl border border-gold/40 bg-background/70 backdrop-blur"
        :class="compact ? 'px-3 py-2' : 'px-4 py-3'"
    >
        <div class="flex items-baseline justify-between gap-2">
            <span class="flex items-center gap-1.5 truncate text-gold" :class="compact ? 'text-xs' : 'text-sm'">
                <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 8v4l3 2" /></svg>
                <span class="truncate">{{ goal.title }}</span>
            </span>
            <span class="shrink-0 tabular-nums text-cream" :class="compact ? 'text-[11px]' : 'text-xs'">
                {{ formatTokens(goal.raised) }} / {{ formatTokens(goal.target) }}
            </span>
        </div>
        <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-surface-2">
            <div
                class="h-full rounded-full transition-all duration-500"
                :class="reached ? 'bg-gold' : 'bg-gold/70'"
                :style="{ width: pct + '%' }"
            />
        </div>
        <p v-if="reached" class="mt-1 text-[11px] text-gold">Meta batida! 🎉</p>
    </div>
</template>
