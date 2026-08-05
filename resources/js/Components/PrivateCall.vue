<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { Room, RoomEvent, Track } from 'livekit-client'
import { postJson } from '@/lib/http'

/**
 * Chamada privada 1:1 (Sprint 15) — sala de vídeo bidirecional. Os dois lados
 * publicam e assinam. O membro dirige a cobrança pelo heartbeat (a cada 60s) e
 * renova o JWT antes do TTL de 5min. O room_name nunca chega aqui — a sala vem
 * DENTRO do token.
 *
 * Encerramento por saldo (UX do § 3): banner discreto ≤3min, amarelo ≤1min, e
 * quando o heartbeat devolve can_continue:false a sessão já foi encerrada no
 * servidor — mostra a despedida por 10s e desconecta. A performer NUNCA vê o
 * financeiro (M.13.10): para ela o componente é o mesmo, sem banners de saldo.
 */
const props = defineProps({
    callId: { type: Number, required: true },
    token: { type: String, required: true },
    wsUrl: { type: String, required: true },
    // Só o lado do MEMBRO recebe estes — a performer não vê saldo/preço.
    role: { type: String, default: 'member' }, // 'member' | 'performer'
    pricePerMinute: { type: Number, default: 0 },
    initialBalance: { type: Number, default: 0 },
})

const emit = defineEmits(['ended', 'recharge'])

const localVideo = ref(null)
const remoteVideo = ref(null)
const remoteAudio = ref(null)
const status = ref('connecting') // connecting | live | ending | ended
const elapsedSeconds = ref(0)
const balance = ref(props.initialBalance)
const minutesLeft = ref(props.pricePerMinute > 0 ? Math.floor(props.initialBalance / props.pricePerMinute) : 0)
const endedNotice = ref('')

const isMember = computed(() => props.role === 'member')

let room = null
let heartbeatTimer = null
let refreshTimer = null
let clockTimer = null
let goodbyeTimer = null

const timerLabel = computed(() => {
    const m = Math.floor(elapsedSeconds.value / 60)
    const s = elapsedSeconds.value % 60
    return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
})

// Banner de saldo — só o membro. ≤1min amarelo, ≤3min discreto (§ 3).
const balanceBanner = computed(() => {
    if (!isMember.value || status.value !== 'live') return null
    if (minutesLeft.value <= 0) return null
    if (minutesLeft.value <= 1) {
        return { level: 'warn', text: 'Último minuto. Recarregue para continuar.' }
    }
    if (minutesLeft.value <= 3) {
        return { level: 'soft', text: `Seu saldo cobre mais ${minutesLeft.value} minutos.` }
    }
    return null
})

function attach(track, participantIsLocal) {
    if (track.kind === 'video') {
        const el = participantIsLocal ? localVideo.value : remoteVideo.value
        if (el) track.attach(el)
    }
    if (track.kind === 'audio' && !participantIsLocal && remoteAudio.value) {
        track.attach(remoteAudio.value)
    }
}

async function connect() {
    room = new Room()
    room.on(RoomEvent.TrackSubscribed, (track) => attach(track, false))
    room.on(RoomEvent.Disconnected, () => {
        if (status.value === 'live') status.value = 'ended'
    })

    await room.connect(props.wsUrl, props.token)
    await room.localParticipant.setCameraEnabled(true)
    await room.localParticipant.setMicrophoneEnabled(true)

    const camPub = room.localParticipant.getTrackPublication(Track.Source.Camera)
    if (camPub?.track) attach(camPub.track, true)

    room.remoteParticipants.forEach((p) =>
        p.trackPublications.forEach((pub) => { if (pub.track) attach(pub.track, false) }),
    )

    status.value = 'live'
    startTimers()
}

function startTimers() {
    clockTimer = setInterval(() => { elapsedSeconds.value += 1 }, 1000)
    // Renova o JWT a cada 4min (antes do TTL de 5). Reautoriza na leitura.
    refreshTimer = setInterval(refresh, 4 * 60 * 1000)
    // O heartbeat/cobrança é do MEMBRO; a performer não cobra ninguém.
    if (isMember.value) {
        heartbeatTimer = setInterval(heartbeat, 60 * 1000)
    }
}

async function heartbeat() {
    try {
        const r = await postJson(route('call.heartbeat', props.callId))
        balance.value = r.balance_remaining
        minutesLeft.value = r.minutes_left
        if (r.can_continue === false) {
            beginGoodbye('Seu saldo acabou.')
        }
    } catch (e) {
        // 410/404 = sessão encerrada do lado do servidor.
        beginGoodbye()
    }
}

async function refresh() {
    try {
        await postJson(route('call.token-refresh', props.callId))
    } catch (e) {
        beginGoodbye()
    }
}

// Despedida elegante (§ 3): 10s de mensagem e desconecta. A sessão já está
// encerrada no servidor quando chegamos aqui.
function beginGoodbye(reason = '') {
    if (status.value === 'ending' || status.value === 'ended') return
    status.value = 'ending'
    stopBillingTimers()
    endedNotice.value = isMember.value
        ? (reason ? `${reason} A sessão foi encerrada. Obrigado pela companhia.` : 'A sessão foi encerrada. Obrigado pela companhia.')
        : 'O membro encerrou a sessão.'
    goodbyeTimer = setTimeout(finish, 10 * 1000)
}

async function endCall() {
    // Encerramento voluntário por este lado.
    status.value = 'ending'
    stopBillingTimers()
    try { await postJson(route('call.end', props.callId)) } catch (e) { /* idempotente */ }
    await finish()
}

async function finish() {
    await teardown()
    status.value = 'ended'
    emit('ended')
}

function stopBillingTimers() {
    if (heartbeatTimer) { clearInterval(heartbeatTimer); heartbeatTimer = null }
    if (refreshTimer) { clearInterval(refreshTimer); refreshTimer = null }
}

async function teardown() {
    stopBillingTimers()
    if (clockTimer) { clearInterval(clockTimer); clockTimer = null }
    if (goodbyeTimer) { clearTimeout(goodbyeTimer); goodbyeTimer = null }
    if (room) { await room.disconnect(); room = null }
}

onMounted(async () => {
    try {
        await connect()
    } catch (e) {
        status.value = 'ended'
        endedNotice.value = 'Não foi possível conectar à chamada.'
    }
})

onBeforeUnmount(teardown)
</script>

<template>
    <div class="space-y-3">
        <div class="relative overflow-hidden rounded-xl border border-frame bg-black aspect-video">
            <!-- Vídeo remoto (o outro lado) preenche a tela. -->
            <video ref="remoteVideo" autoplay playsinline class="h-full w-full object-contain" />
            <audio ref="remoteAudio" autoplay />

            <!-- Prévia local no canto. -->
            <video
                ref="localVideo"
                autoplay
                muted
                playsinline
                class="absolute bottom-3 right-3 h-24 w-32 rounded-lg border border-frame object-cover bg-black/60"
            />

            <!-- Timer sempre visível. -->
            <div class="absolute top-3 left-3 rounded-full bg-black/60 px-3 py-1 text-sm font-medium text-white tabular-nums">
                {{ timerLabel }}
            </div>

            <!-- Despedida / encerramento. -->
            <div
                v-if="status === 'ending' || status === 'ended'"
                class="absolute inset-0 flex items-center justify-center bg-black/80 p-6 text-center text-white"
            >
                <p class="max-w-sm text-lg">{{ endedNotice }}</p>
            </div>
        </div>

        <!-- Banner de saldo (só o membro). -->
        <div
            v-if="balanceBanner"
            :class="[
                'flex items-center justify-between rounded-lg px-4 py-2 text-sm',
                balanceBanner.level === 'warn'
                    ? 'bg-amber-100 text-amber-900'
                    : 'bg-neutral-100 text-neutral-700',
            ]"
        >
            <span>{{ balanceBanner.text }}</span>
            <button
                v-if="balanceBanner.level === 'warn'"
                type="button"
                class="rounded-md bg-amber-600 px-3 py-1 font-medium text-white hover:bg-amber-700"
                @click="emit('recharge')"
            >
                Recarregar
            </button>
        </div>

        <!-- Controles. -->
        <div v-if="status === 'live'" class="flex items-center gap-3">
            <button
                type="button"
                class="rounded-lg bg-red-600 px-4 py-2 font-medium text-white hover:bg-red-700"
                @click="endCall"
            >
                Encerrar
            </button>
            <button
                v-if="isMember"
                type="button"
                class="rounded-lg border border-frame px-4 py-2 font-medium hover:bg-neutral-50"
                @click="emit('recharge')"
            >
                Recarregar tokens
            </button>
        </div>
    </div>
</template>
