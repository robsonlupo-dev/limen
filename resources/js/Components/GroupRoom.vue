<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { Room, RoomEvent } from 'livekit-client'
import { postJson } from '@/lib/http'

/**
 * Group show 1:X (Sprint 15) — lado do MEMBRO. Assiste ao stream da performer
 * (view-only) e paga o próprio preço-grupo por minuto (heartbeat a cada 60s),
 * independente dos outros. `{group}` = id da sessão; o room_name nunca chega aqui
 * (vem dentro do token). Banners de saldo iguais aos do 1:1: ≤3min discreto,
 * ≤1min amarelo, =0 despedida de 10s.
 *
 * "Pedir 1:1": bate em group.upgrade.request e escuta group.upgrade.resolved. Se
 * outro membro leva a sessão para 1:1, este recebe group.ended(reason=upgraded) e
 * vê "A sessão privada foi iniciada. Obrigado pela companhia." por 10s.
 */
const props = defineProps({
    groupId: { type: Number, required: true },
    token: { type: String, required: true },
    wsUrl: { type: String, required: true },
    myUserId: { type: Number, required: true },
    pricePerMinute: { type: Number, required: true },
    initialBalance: { type: Number, default: 0 },
})

const emit = defineEmits(['left', 'recharge', 'upgraded'])

const videoEl = ref(null)
const audioEl = ref(null)
const status = ref('connecting') // connecting | live | ending | ended
const balance = ref(props.initialBalance)
const minutesLeft = ref(props.pricePerMinute > 0 ? Math.floor(props.initialBalance / props.pricePerMinute) : 0)
const endedNotice = ref('')
const upgradeState = ref('idle') // idle | pending | declined

let room = null
let heartbeatTimer = null
let refreshTimer = null
let goodbyeTimer = null
let channel = null

const balanceBanner = computed(() => {
    if (status.value !== 'live' || minutesLeft.value <= 0) return null
    if (minutesLeft.value <= 1) return { level: 'warn', text: 'Último minuto. Recarregue para continuar.' }
    if (minutesLeft.value <= 3) return { level: 'soft', text: `Seu saldo cobre mais ${minutesLeft.value} minutos.` }
    return null
})

function attach(track) {
    if (track.kind === 'video' && videoEl.value) track.attach(videoEl.value)
    if (track.kind === 'audio' && audioEl.value) track.attach(audioEl.value)
}

async function connect() {
    room = new Room()
    room.on(RoomEvent.TrackSubscribed, (track) => attach(track))
    room.on(RoomEvent.Disconnected, () => { if (status.value === 'live') status.value = 'ended' })

    await room.connect(props.wsUrl, props.token)
    room.remoteParticipants.forEach((p) =>
        p.trackPublications.forEach((pub) => { if (pub.track) attach(pub.track) }),
    )
    status.value = 'live'

    heartbeatTimer = setInterval(heartbeat, 60 * 1000)
    refreshTimer = setInterval(refresh, 4 * 60 * 1000)
    subscribeSignals()
}

async function heartbeat() {
    try {
        const r = await postJson(route('group.heartbeat', props.groupId))
        balance.value = r.balance_remaining
        minutesLeft.value = r.minutes_left
        if (r.can_continue === false) beginGoodbye('Seu saldo acabou.')
    } catch (e) {
        beginGoodbye()
    }
}

async function refresh() {
    try {
        await postJson(route('group.token-refresh', props.groupId))
    } catch (e) {
        beginGoodbye()
    }
}

function subscribeSignals() {
    if (!window.Echo) return
    channel = window.Echo.private(`user.${props.myUserId}`)
    channel.listen('.group.upgrade.resolved', (e) => {
        if (e.group_id !== props.groupId) return
        if (e.accepted) {
            // O upgrade DESTE membro foi aceito — ele é o sobrevivente 1:1.
            upgradeState.value = 'idle'
            emit('upgraded')
        } else {
            upgradeState.value = 'declined'
        }
    })
    channel.listen('.group.ended', (e) => {
        if (e.group_id !== props.groupId) return
        beginGoodbye(e.reason === 'upgraded' ? 'A sessão privada foi iniciada.' : '')
    })
}

async function requestUpgrade() {
    if (upgradeState.value === 'pending') return
    upgradeState.value = 'pending'
    try {
        await postJson(route('group.upgrade.request', props.groupId))
    } catch (e) {
        upgradeState.value = 'idle'
    }
}

function beginGoodbye(reason = '') {
    if (status.value === 'ending' || status.value === 'ended') return
    status.value = 'ending'
    stopTimers()
    endedNotice.value = reason
        ? `${reason} Obrigado pela companhia.`
        : 'A sessão foi encerrada. Obrigado pela companhia.'
    goodbyeTimer = setTimeout(finish, 10 * 1000)
}

async function leave() {
    status.value = 'ending'
    stopTimers()
    try { await postJson(route('group.leave', props.groupId)) } catch (e) { /* idempotente */ }
    await finish()
}

async function finish() {
    await teardown()
    status.value = 'ended'
    emit('left')
}

function stopTimers() {
    if (heartbeatTimer) { clearInterval(heartbeatTimer); heartbeatTimer = null }
    if (refreshTimer) { clearInterval(refreshTimer); refreshTimer = null }
}

async function teardown() {
    stopTimers()
    if (goodbyeTimer) { clearTimeout(goodbyeTimer); goodbyeTimer = null }
    if (channel) { window.Echo?.leave(`user.${props.myUserId}`); channel = null }
    if (room) { await room.disconnect(); room = null }
}

onMounted(async () => {
    try {
        await connect()
    } catch (e) {
        status.value = 'ended'
        endedNotice.value = 'Não foi possível conectar ao group show.'
    }
})

onBeforeUnmount(teardown)
</script>

<template>
    <div class="space-y-3">
        <div class="relative overflow-hidden rounded-xl border border-frame bg-black aspect-video">
            <video ref="videoEl" autoplay playsinline class="h-full w-full object-contain" />
            <audio ref="audioEl" autoplay />

            <div
                v-if="status === 'ending' || status === 'ended'"
                class="absolute inset-0 flex items-center justify-center bg-black/80 p-6 text-center text-white"
            >
                <p class="max-w-sm text-lg">{{ endedNotice }}</p>
            </div>
        </div>

        <div
            v-if="balanceBanner"
            :class="[
                'flex items-center justify-between rounded-lg px-4 py-2 text-sm',
                balanceBanner.level === 'warn' ? 'bg-amber-100 text-amber-900' : 'bg-neutral-100 text-neutral-700',
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

        <div v-if="status === 'live'" class="flex flex-wrap items-center gap-3">
            <button type="button" class="rounded-lg bg-red-600 px-4 py-2 font-medium text-white hover:bg-red-700" @click="leave">
                Sair
            </button>
            <button
                type="button"
                :disabled="upgradeState === 'pending'"
                class="rounded-lg border border-frame px-4 py-2 font-medium hover:bg-neutral-50 disabled:opacity-60"
                @click="requestUpgrade"
            >
                {{ upgradeState === 'pending' ? 'Aguardando…' : 'Pedir 1:1' }}
            </button>
            <span v-if="upgradeState === 'declined'" class="text-sm text-neutral-500">Não disponível no momento.</span>
        </div>
    </div>
</template>
