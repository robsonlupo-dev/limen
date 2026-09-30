<script setup>
/**
 * Player de mensagem de voz do chat (feat/chat-voice-message). Substitui o
 * `<audio controls>` nativo — que mostrava o controle de velocidade ("1.00") por cima do
 * play e renderizava diferente em cada navegador — por um player próprio, compacto e colado
 * na bolha (estilo WhatsApp): play/pause + barra de progresso arrastável + tempo.
 *
 * O <audio> real fica escondido; só o controlamos por ref. Sem download/velocidade.
 */
import { ref, computed, onBeforeUnmount } from 'vue'

const props = defineProps({
    src: { type: String, required: true },
    // Duração conhecida do servidor (segundos) — mostrada antes de carregar os metadados.
    duration: { type: Number, default: 0 },
    autoplay: { type: Boolean, default: false },
})

const audio = ref(null)
const playing = ref(false)
const current = ref(0)
const total = ref(props.duration || 0)

function fmt(s) {
    if (!Number.isFinite(s) || s < 0) s = 0
    const m = Math.floor(s / 60)
    return `${m}:${String(Math.floor(s % 60)).padStart(2, '0')}`
}

const pct = computed(() => (total.value > 0 ? Math.min(100, (current.value / total.value) * 100) : 0))
const remaining = computed(() => fmt(Math.max(0, (total.value || 0) - current.value)))

function toggle() {
    const el = audio.value
    if (!el) return
    if (el.paused) el.play().catch(() => {})
    else el.pause()
}

function onLoaded() {
    const d = audio.value?.duration
    // Alguns webm vêm com duration = Infinity até tocar; mantém a do servidor nesse caso.
    if (Number.isFinite(d) && d > 0) total.value = d
}
function onTime() { current.value = audio.value?.currentTime ?? 0 }
function onPlay() { playing.value = true }
function onPause() { playing.value = false }
function onEnded() { playing.value = false; current.value = 0 }

// Clique/arraste na barra para buscar.
function seek(e) {
    const el = audio.value
    const bar = e.currentTarget
    if (!el || !bar || !total.value) return
    const rect = bar.getBoundingClientRect()
    const ratio = Math.min(1, Math.max(0, (e.clientX - rect.left) / rect.width))
    el.currentTime = ratio * total.value
    current.value = el.currentTime
}

onBeforeUnmount(() => { try { audio.value?.pause() } catch { /* noop */ } })
</script>

<template>
    <div class="flex items-center gap-2.5 min-w-[12rem] w-56 max-w-full">
        <audio
            ref="audio"
            :src="src"
            :autoplay="autoplay"
            preload="metadata"
            @loadedmetadata="onLoaded"
            @durationchange="onLoaded"
            @timeupdate="onTime"
            @play="onPlay"
            @pause="onPause"
            @ended="onEnded"
        ></audio>

        <button
            type="button"
            :aria-label="playing ? 'Pausar' : 'Reproduzir'"
            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gold text-surface transition-opacity hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-gold"
            @click="toggle"
        >
            <svg v-if="!playing" class="h-4 w-4 translate-x-[1px]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z" /></svg>
            <svg v-else class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6 5h4v14H6zM14 5h4v14h-4z" /></svg>
        </button>

        <div class="flex-1">
            <div
                class="group relative h-1.5 w-full cursor-pointer rounded-full bg-muted/30"
                role="slider"
                :aria-valuenow="Math.round(pct)"
                aria-valuemin="0"
                aria-valuemax="100"
                @click="seek"
            >
                <div class="absolute inset-y-0 left-0 rounded-full bg-gold" :style="{ width: pct + '%' }"></div>
                <div
                    class="absolute top-1/2 h-3 w-3 -translate-y-1/2 -translate-x-1/2 rounded-full bg-gold opacity-0 transition-opacity group-hover:opacity-100"
                    :style="{ left: pct + '%' }"
                ></div>
            </div>
            <div class="pt-1 text-[10px] tabular-nums text-muted">{{ playing || current > 0 ? fmt(current) : remaining }}</div>
        </div>
    </div>
</template>
