<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

// Status do dia da performer (roadmap social, Onda 1a). Bolha curta + contagem
// regressiva opcional. `status` vem do PerformerPublicResource (whenLoaded).
const props = defineProps({
    status: { type: Object, required: true }, // { body, countdown_at, countdown_label }
})

// Relógio reativo para a contagem. Atualiza a cada 30s (granularidade de minuto);
// leve o bastante para não pesar, e prefers-reduced-motion não se aplica (é texto,
// sem animação).
const now = ref(Date.now())
let timer = null
onMounted(() => {
    if (props.status.countdown_at) {
        timer = setInterval(() => (now.value = Date.now()), 30_000)
    }
})
onBeforeUnmount(() => timer && clearInterval(timer))

const countdownText = computed(() => {
    if (!props.status.countdown_at) return null
    const target = new Date(props.status.countdown_at).getTime()
    const diff = target - now.value
    if (Number.isNaN(target) || diff <= 0) return null // passou: some a contagem

    const totalMin = Math.floor(diff / 60_000)
    const h = Math.floor(totalMin / 60)
    const m = totalMin % 60
    const tempo = h > 0 ? `${h}h${m > 0 ? ` ${m}min` : ''}` : `${Math.max(1, m)}min`

    const label = props.status.countdown_label
    return label ? `${label} em ${tempo}` : `em ${tempo}`
})
</script>

<template>
    <div
        class="inline-flex max-w-full items-center gap-2 rounded-full border border-limen-gold/30 bg-limen-surface px-3.5 py-1.5"
        role="status"
    >
        <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4 shrink-0 text-limen-gold" aria-hidden="true">
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
            <path d="M12 7.5V12l3 2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        <span class="min-w-0 truncate text-sm text-limen-ink">{{ status.body }}</span>
        <span
            v-if="countdownText"
            class="shrink-0 rounded-full bg-limen-gold/15 px-2 py-0.5 text-xs font-medium text-limen-gold"
        >
            {{ countdownText }}
        </span>
    </div>
</template>
