<script setup>
import { ref, onBeforeUnmount } from 'vue'

// Player discreto da intro de voz da performer (feat/voice-intro). Botão dourado
// de play/pause ao lado do nome, com um <audio> escondido. A `url` já vem
// resolvida do servidor (só quando há intro APROVADA) — o componente não decide
// visibilidade, o chamador desenha o botão só quando url existe.
const props = defineProps({
    url: { type: String, required: true },
    // Rótulo acessível ("Ouvir apresentação de Fulana").
    label: { type: String, default: 'apresentação de voz' },
})

const audio = ref(null)
const playing = ref(false)
const loading = ref(false)

function toggle() {
    const el = audio.value
    if (!el) return

    if (playing.value) {
        el.pause()
        return
    }

    loading.value = true
    el.play()
        .then(() => { loading.value = false })
        .catch(() => { loading.value = false }) // autoplay bloqueado / rede
}

function onPlay() { playing.value = true }
function onPauseOrEnd() { playing.value = false }

onBeforeUnmount(() => {
    // Para o áudio ao sair da tela (evita som órfão numa navegação Inertia).
    if (audio.value) {
        audio.value.pause()
    }
})
</script>

<template>
    <button
        type="button"
        :aria-label="playing ? `Pausar ${label}` : `Ouvir ${label}`"
        :aria-pressed="playing"
        class="mi-press inline-flex items-center gap-2 rounded-full border border-limen-gold/50 bg-limen-gold/10 px-3 py-1.5 text-sm text-limen-gold transition-colors hover:bg-limen-gold/20"
        @click="toggle"
    >
        <!-- Ícone: barra dourada de loading, senão pause/play em SVG inline
             (zero asset externo). -->
        <span v-if="loading" class="mi-loading-bar h-1 w-4 rounded-full" aria-hidden="true" />
        <svg v-else-if="playing" class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <rect x="6" y="5" width="4" height="14" rx="1" />
            <rect x="14" y="5" width="4" height="14" rx="1" />
        </svg>
        <svg v-else class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M8 5v14l11-7z" />
        </svg>
        <span class="font-medium">Ouvir voz</span>

        <audio
            ref="audio"
            :src="url"
            preload="none"
            class="hidden"
            @play="onPlay"
            @pause="onPauseOrEnd"
            @ended="onPauseOrEnd"
        />
    </button>
</template>
