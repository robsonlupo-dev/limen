<script setup>
import { ref, computed, onBeforeUnmount } from 'vue'

// Player da intro de voz da performer (feat/voice-intro). A `url` já vem resolvida
// do servidor (só quando há intro APROVADA) — o componente não decide
// visibilidade, o chamador desenha só quando url existe.
//
// Dois formatos, MESMA lógica de áudio:
//  - `pill` (padrão): botão dourado compacto de play/pause, para caber ao lado de
//    um nome ou num card.
//  - `band` (feat/performer-profile-redesign): FAIXA de largura cheia com onda
//    sonora desenhada, duração e um botão de play integrado — é a peça de
//    identidade do perfil, então ganha peso visual. Reusa este componente em vez
//    de recriar o player.
const props = defineProps({
    url: { type: String, required: true },
    // Rótulo acessível ("Ouvir apresentação de Fulana").
    label: { type: String, default: 'apresentação de voz' },
    variant: { type: String, default: 'pill' }, // 'pill' | 'band'
    // Nome da performer, para a faixa ("Ouça a voz de {nome}"). Só no band.
    performerName: { type: String, default: '' },
})

const audio = ref(null)
const playing = ref(false)
const loading = ref(false)
const duration = ref(0)

const isBand = computed(() => props.variant === 'band')

// Alturas fixas das barras da onda (determinístico — sem Math.random, para a onda
// ser estável entre renders). Desenhado, não é o áudio real: é assinatura visual.
const WAVE = [22, 40, 64, 48, 80, 58, 90, 44, 70, 52, 96, 60, 38, 74, 50, 84, 46, 66, 34, 78, 54, 88, 42, 72, 56, 100, 62, 36, 76, 50, 82, 44, 68, 30, 60, 48]

const durationLabel = computed(() => {
    if (!duration.value || !isFinite(duration.value)) return ''
    const m = Math.floor(duration.value / 60)
    const s = Math.floor(duration.value % 60)
    return `${m}:${String(s).padStart(2, '0')}`
})

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
function onMeta() {
    if (audio.value && isFinite(audio.value.duration)) duration.value = audio.value.duration
}

onBeforeUnmount(() => {
    // Para o áudio ao sair da tela (evita som órfão numa navegação Inertia).
    if (audio.value) {
        audio.value.pause()
    }
})
</script>

<template>
    <!-- FAIXA (assinatura do perfil): onda desenhada + play integrado + duração. -->
    <div
        v-if="isBand"
        class="flex items-center gap-4 rounded-2xl border border-limen-gold/30 bg-gradient-to-r from-limen-gold/[0.07] via-limen-surface to-limen-surface px-4 py-3.5 sm:px-5"
    >
        <button
            type="button"
            :aria-label="playing ? `Pausar ${label}` : `Ouvir ${label}`"
            :aria-pressed="playing"
            class="mi-press flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-limen-gold text-limen-bg shadow-lg shadow-limen-gold/20 transition-transform hover:scale-105 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-limen-gold/60 focus-visible:ring-offset-2 focus-visible:ring-offset-limen-surface"
            @click="toggle"
        >
            <span v-if="loading" class="mi-loading-bar h-1.5 w-6 rounded-full bg-limen-bg/40" aria-hidden="true" />
            <svg v-else-if="playing" class="h-6 w-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <rect x="6" y="5" width="4" height="14" rx="1" />
                <rect x="14" y="5" width="4" height="14" rx="1" />
            </svg>
            <svg v-else class="ml-0.5 h-6 w-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M8 5v14l11-7z" />
            </svg>
        </button>

        <div class="min-w-0 flex-1">
            <p class="text-[11px] uppercase tracking-[0.24em] text-limen-gold/80">Apresentação de voz</p>
            <p class="truncate font-serif text-lg leading-tight text-limen-ink">
                Ouça a voz de {{ performerName }}
            </p>
            <!-- Onda desenhada: barras douradas de altura fixa. Tocando, pulsam
                 num "equalizador" leve — desligado sob prefers-reduced-motion. -->
            <div class="mt-2 flex h-8 items-center gap-[3px]" :class="{ 'voice-wave-playing': playing }" aria-hidden="true">
                <span
                    v-for="(h, i) in WAVE"
                    :key="i"
                    class="voice-wave-bar w-[3px] shrink-0 rounded-full bg-limen-gold/60"
                    :style="{ height: h + '%', '--i': i % 6 }"
                />
            </div>
        </div>

        <span v-if="durationLabel" class="shrink-0 self-end pb-0.5 text-xs tabular-nums text-limen-ink-mute">{{ durationLabel }}</span>

        <audio
            ref="audio"
            :src="url"
            preload="metadata"
            class="hidden"
            @play="onPlay"
            @pause="onPauseOrEnd"
            @ended="onPauseOrEnd"
            @loadedmetadata="onMeta"
        />
    </div>

    <!-- PÍLULA (padrão): compacta, ao lado de um nome ou num card. -->
    <button
        v-else
        type="button"
        :aria-label="playing ? `Pausar ${label}` : `Ouvir ${label}`"
        :aria-pressed="playing"
        class="mi-press inline-flex items-center gap-2 rounded-full border border-limen-gold/50 bg-limen-gold/10 px-3 py-1.5 text-sm text-limen-gold transition-colors hover:bg-limen-gold/20"
        @click="toggle"
    >
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

<style scoped>
/* Equalizador leve só enquanto toca. Cada barra defasa pelo --i. Desligado sob
   prefers-reduced-motion (a onda fica estática e legível). */
.voice-wave-playing .voice-wave-bar {
    animation: voice-wave 1s ease-in-out infinite;
    animation-delay: calc(var(--i) * -0.12s);
    background-color: rgb(214 184 114 / 0.9);
}

@keyframes voice-wave {
    0%, 100% { transform: scaleY(0.55); }
    50% { transform: scaleY(1); }
}

@media (prefers-reduced-motion: reduce) {
    .voice-wave-playing .voice-wave-bar {
        animation: none;
    }
}
</style>
