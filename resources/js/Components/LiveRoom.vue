<script setup>
import { ref, nextTick, onBeforeUnmount } from 'vue'
import { Room, Track } from 'livekit-client'
import { postJson, getJson, errorMessage } from '@/lib/http'
import LiveChat from '@/Components/LiveChat.vue'
import LiveReactionFeed from '@/Components/LiveReactionFeed.vue'

/**
 * Console da PERFORMER (feat/live-room-console). Antes ela transmitia "no escuro":
 * via só o próprio vídeo e "Encerrar live". Agora a informação está do lado dela —
 * espectadores ao vivo, ganho acumulado da transmissão, feed de gorjeta/presente e
 * o chat da sala no centro (ela lê e responde) — e o vídeo vira uma prévia pequena.
 *
 * Publica câmera+mic via LiveKit (o room_name nunca chega ao cliente — vem no JWT).
 * Dados ao vivo por POLL (performer.live.console, ~15s) + a cada reação; o chat e o
 * feed andam pelo Reverb no canal `live.{slug}`.
 */
const props = defineProps({
    performerSlug: { type: String, required: true },
    initialChat: { type: Array, default: () => [] },
})

const videoEl = ref(null)
const status = ref('idle') // idle | starting | live | stopping | error
const error = ref('')
const cameraError = ref('')
const expanded = ref(false)
const viewers = ref(0)
const earned = ref(0)
const mobileTab = ref('chat')
const confirmingEnd = ref(false)

let room = null
let previewTimer = null
let consoleTimer = null
let confirmTimer = null

async function goLive() {
    status.value = 'starting'
    error.value = ''
    cameraError.value = ''
    try {
        const { token, wsUrl } = await postJson(route('performer.live.start'))

        room = new Room()
        await room.connect(wsUrl, token)

        // Câmera e microfone separados: se a CÂMERA falhar (permissão negada, sem
        // dispositivo), mostramos o motivo na prévia em vez de um quadro preto mudo,
        // mas a transmissão ainda sobe (áudio).
        try {
            await room.localParticipant.setCameraEnabled(true)
        } catch (camErr) {
            cameraError.value = 'Não foi possível acessar a câmera. Verifique as permissões do navegador e recarregue.'
        }
        await room.localParticipant.setMicrophoneEnabled(true).catch(() => {})

        // status='live' PRIMEIRO, DEPOIS anexar: o <video> do console (v-else) só
        // MONTA quando status vira 'live'. Anexar antes prendia a faixa ao <video> do
        // estado ocioso, que o Vue então desmonta — a prévia do console nascia preta.
        status.value = 'live'
        await nextTick()
        attachLocalCamera()

        startPreviewCapture()
        startConsolePolling()
    } catch (e) {
        error.value = errorMessage(e, 'Não foi possível iniciar a live.')
        status.value = 'error'
        await disconnectRoom()
    }
}

// Anexa a faixa de vídeo LOCAL ao elemento de prévia do console (já montado).
function attachLocalCamera() {
    if (!room || !videoEl.value) return
    const camPub = room.localParticipant.getTrackPublication(Track.Source.Camera)
    if (camPub?.track) {
        camPub.track.attach(videoEl.value)
        cameraError.value = ''
    } else if (!cameraError.value) {
        cameraError.value = 'Câmera não detectada.'
    }
}

async function endLive() {
    status.value = 'stopping'
    try {
        await postJson(route('performer.live.stop'))
    } finally {
        await disconnectRoom()
        status.value = 'idle'
        viewers.value = 0
        earned.value = 0
    }
}

// Confirmação em dois toques: o primeiro arma, o segundo (em 3s) encerra — o botão
// fica acessível sem virar clique acidental que derruba a transmissão.
function requestEnd() {
    if (!confirmingEnd.value) {
        confirmingEnd.value = true
        confirmTimer = setTimeout(() => { confirmingEnd.value = false }, 3000)
        return
    }
    clearTimeout(confirmTimer)
    confirmingEnd.value = false
    endLive()
}

function startConsolePolling() {
    refreshConsole()
    // Mesma fonte cacheada (~12s) que o membro pola — contagem igual dos dois lados.
    consoleTimer = setInterval(refreshConsole, 12000)
}

// Dados ao vivo. O ganho vem EXATO do ledger (nunca somado no cliente): cada reação
// dispara um refresh, e o poll cobre o que o broadcast perder.
async function refreshConsole() {
    if (status.value !== 'live') return
    try {
        const data = await getJson(route('performer.live.console'))
        if (data.is_live) {
            viewers.value = data.viewers
            earned.value = data.earned
        }
    } catch (e) {
        // Um poll perdido é inócuo — o próximo chega em 15s.
    }
}

// Preview do catálogo (PR #143): um quadro do vídeo local a cada 10s.
function startPreviewCapture() {
    captureFrame()
    previewTimer = setInterval(captureFrame, 10000)
}

async function captureFrame() {
    const v = videoEl.value
    if (status.value !== 'live' || !v || !v.videoWidth) return

    const w = 320
    const h = Math.round(w * (v.videoHeight / v.videoWidth)) || 240
    const canvas = document.createElement('canvas')
    canvas.width = w
    canvas.height = h
    canvas.getContext('2d').drawImage(v, 0, 0, w, h)

    try {
        await postJson(route('performer.live.preview'), { frame: canvas.toDataURL('image/jpeg', 0.3) })
    } catch (e) {
        // Frame perdido é inofensivo — o próximo sai em 10s.
    }
}

async function disconnectRoom() {
    if (previewTimer) { clearInterval(previewTimer); previewTimer = null }
    if (consoleTimer) { clearInterval(consoleTimer); consoleTimer = null }
    if (room) { await room.disconnect(); room = null }
}

function sendChat(body) {
    return postJson(route('performer.live.chat'), { body })
}

function muteViewer(messageId) {
    return postJson(route('performer.live.mute'), { message_id: messageId })
}

onBeforeUnmount(disconnectRoom)
</script>

<template>
    <!-- Estado ocioso / conectando / erro: a câmera ainda não está no ar. -->
    <div v-if="status !== 'live'" class="space-y-4">
        <div class="relative flex aspect-video items-center justify-center overflow-hidden rounded-xl border border-frame bg-black">
            <video ref="videoEl" autoplay muted playsinline class="h-full w-full object-contain" />
            <div class="absolute inset-0 flex items-center justify-center px-6 text-center text-sm text-cream/70">
                <span v-if="status === 'idle'">Sua câmera aparece aqui quando você entra ao vivo.</span>
                <span v-else-if="status === 'starting'">Iniciando a transmissão…</span>
                <span v-else-if="status === 'error'" class="text-danger">{{ error }}</span>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <button
                type="button"
                :disabled="status === 'starting'"
                class="mi-press rounded-lg bg-gold px-5 py-2.5 text-sm font-semibold text-background hover:bg-gold/90 disabled:opacity-50"
                @click="goLive"
            >
                Entrar ao vivo
            </button>
            <p class="text-xs text-muted">A live é gratuita para os membros. Você recebe gorjetas e presentes durante a transmissão.</p>
        </div>
    </div>

    <!-- Console ao vivo. -->
    <div v-else class="space-y-4">
        <!-- Barra de status: presença, ganho, prévia pequena e encerrar. -->
        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-frame bg-surface p-3">
            <!-- Prévia do próprio vídeo: espelhada, sem áudio (sem microfonia),
                 ampliável para conferir o enquadramento. O MESMO <video> serve os
                 dois tamanhos (só a classe muda), então a faixa não se solta ao
                 ampliar/reduzir. -->
            <div
                :class="expanded
                    ? 'fixed inset-0 z-30 flex items-center justify-center bg-black/85 p-4'
                    : 'relative aspect-video w-36 shrink-0 overflow-hidden rounded-lg border border-frame bg-black sm:w-52'"
            >
                <video
                    ref="videoEl"
                    autoplay
                    muted
                    playsinline
                    :class="expanded
                        ? 'max-h-full w-auto max-w-4xl -scale-x-100 rounded-lg bg-black'
                        : 'h-full w-full -scale-x-100 object-cover'"
                />

                <!-- Câmera falhou: o motivo, nunca um quadro preto mudo. -->
                <div v-if="cameraError" class="absolute inset-0 flex items-center justify-center bg-black/80 p-3 text-center text-[11px] leading-snug text-cream/90">
                    {{ cameraError }}
                </div>

                <span v-if="!expanded" class="absolute left-1 top-1 rounded bg-limen-live px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-white">Ao vivo</span>

                <button
                    type="button"
                    class="absolute right-1 top-1 rounded bg-black/60 px-1.5 py-0.5 text-[10px] text-cream hover:bg-black/80"
                    :aria-label="expanded ? 'Reduzir prévia' : 'Ampliar prévia para conferir o enquadramento'"
                    @click="expanded = !expanded"
                >
                    {{ expanded ? '✕ Fechar' : '⤢ Ampliar' }}
                </button>

                <span v-if="expanded" class="absolute bottom-4 left-1/2 -translate-x-1/2 rounded-full bg-black/60 px-3 py-1 text-[11px] text-cream/80">Prévia espelhada — só você vê</span>
            </div>

            <div class="flex flex-1 flex-wrap items-center gap-x-6 gap-y-2">
                <div>
                    <p class="text-[11px] uppercase tracking-wide text-muted">Assistindo agora</p>
                    <p class="font-serif text-2xl text-cream" aria-live="polite">👁 {{ viewers }}</p>
                </div>
                <div>
                    <p class="text-[11px] uppercase tracking-wide text-muted">Ganho nesta live</p>
                    <p class="font-serif text-2xl text-gold" aria-live="polite">{{ earned }} <span class="text-sm">🪙</span></p>
                </div>
            </div>

            <button
                type="button"
                class="mi-press shrink-0 rounded-lg border px-4 py-2 text-sm font-semibold transition"
                :class="confirmingEnd ? 'border-danger bg-danger text-white' : 'border-danger/60 text-danger hover:bg-danger/10'"
                @click="requestEnd"
            >
                {{ confirmingEnd ? 'Confirmar encerramento' : 'Encerrar live' }}
            </button>
        </div>

        <!-- Mobile: alterna chat / gorjetas. Desktop: os dois lado a lado. -->
        <div class="flex gap-1 rounded-lg border border-frame bg-surface p-1 lg:hidden">
            <button
                type="button"
                class="flex-1 rounded-md px-3 py-2 text-sm font-medium transition"
                :class="mobileTab === 'chat' ? 'bg-gold text-background' : 'text-muted'"
                @click="mobileTab = 'chat'"
            >
                Chat
            </button>
            <button
                type="button"
                class="flex-1 rounded-md px-3 py-2 text-sm font-medium transition"
                :class="mobileTab === 'feed' ? 'bg-gold text-background' : 'text-muted'"
                @click="mobileTab = 'feed'"
            >
                Gorjetas
            </button>
        </div>

        <div class="grid gap-4 lg:h-[calc(100vh-19rem)] lg:grid-cols-[1fr_340px]">
            <div class="h-[68vh] min-h-0 lg:h-auto" :class="mobileTab === 'chat' ? 'block' : 'hidden lg:block'">
                <LiveChat
                    :performer-slug="performerSlug"
                    :initial-messages="initialChat"
                    :can-moderate="true"
                    placeholder="Responda a sua sala…"
                    :on-send="sendChat"
                    :on-mute="muteViewer"
                    class="h-full"
                />
            </div>

            <div class="h-[68vh] min-h-0 lg:h-auto" :class="mobileTab === 'feed' ? 'block' : 'hidden lg:block'">
                <LiveReactionFeed :performer-slug="performerSlug" class="h-full" @reaction="refreshConsole" />
            </div>
        </div>
    </div>
</template>
