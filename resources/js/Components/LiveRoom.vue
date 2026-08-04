<script setup>
import { ref, onBeforeUnmount } from 'vue'
import { Room, Track } from 'livekit-client'
import { postJson } from '@/lib/http'

/**
 * Live pública GRÁTIS — lado da PERFORMER (estúdio de transmissão). Publica
 * câmera + microfone; view-only para os membros. Início/fim batem em
 * performer.live.start / .stop (o backend cria/deleta a sala e liga/desliga
 * is_live). O room_name nunca chega ao cliente — a sala vem dentro do token.
 */
const videoEl = ref(null)
const status = ref('idle') // idle | starting | live | stopping | error
const error = ref('')

let room = null

async function goLive() {
    status.value = 'starting'
    error.value = ''
    try {
        const { token, wsUrl } = await postJson(route('performer.live.start'))

        room = new Room()
        await room.connect(wsUrl, token)
        await room.localParticipant.setCameraEnabled(true)
        await room.localParticipant.setMicrophoneEnabled(true)

        const camPub = room.localParticipant.getTrackPublication(Track.Source.Camera)
        if (camPub?.track && videoEl.value) camPub.track.attach(videoEl.value)

        status.value = 'live'
    } catch (e) {
        error.value = e?.data?.message ?? 'Não foi possível iniciar a live.'
        status.value = 'error'
        await disconnectRoom()
    }
}

async function endLive() {
    status.value = 'stopping'
    try {
        await postJson(route('performer.live.stop'))
    } finally {
        await disconnectRoom()
        status.value = 'idle'
    }
}

async function disconnectRoom() {
    if (room) { await room.disconnect(); room = null }
}

onBeforeUnmount(disconnectRoom)
</script>

<template>
    <div class="space-y-4">
        <div class="relative overflow-hidden rounded-xl border border-frame bg-black aspect-video">
            <video ref="videoEl" autoplay muted playsinline class="h-full w-full object-contain" />
            <div v-if="status !== 'live'" class="absolute inset-0 flex items-center justify-center text-cream/70 text-sm">
                <span v-if="status === 'idle'">Sua câmera aparece aqui quando você entra ao vivo.</span>
                <span v-else-if="status === 'starting'">Iniciando…</span>
                <span v-else-if="status === 'error'">{{ error }}</span>
            </div>
            <span v-if="status === 'live'" class="absolute left-3 top-3 rounded-full bg-danger px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-white">Ao vivo</span>
        </div>

        <div class="flex items-center gap-3">
            <button
                v-if="status !== 'live'"
                type="button"
                :disabled="status === 'starting'"
                class="rounded-lg bg-gold px-5 py-2 text-sm font-semibold text-background hover:bg-gold/90 disabled:opacity-50"
                @click="goLive"
            >
                Entrar ao vivo
            </button>
            <button
                v-else
                type="button"
                class="rounded-lg border border-danger/60 px-5 py-2 text-sm font-semibold text-danger hover:bg-danger/10"
                @click="endLive"
            >
                Encerrar live
            </button>
            <p class="text-xs text-muted">A live é gratuita para os membros. Você recebe gorjetas e presentes durante a transmissão.</p>
        </div>
    </div>
</template>
