<script setup>
import { ref, computed, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { Room, RoomEvent } from 'livekit-client'
import { postJson, getJson, errorMessage } from '@/lib/http'
import LiveOverlay from '@/Components/LiveOverlay.vue'
import LiveChat from '@/Components/LiveChat.vue'
import GiftIcon from '@/Components/GiftIcon.vue'
import PrivateCall from '@/Components/PrivateCall.vue'

/**
 * Sala de live — lado do MEMBRO (feat/live-room-console). O vídeo DOMINA; o chat da
 * sala fica em coluna ao lado (desktop) ou preenchendo o resto abaixo do vídeo
 * (mobile), sem precisar rolar. Gorjeta e presente ficam numa barra compacta que
 * NÃO cobre o vídeo.
 *
 * View-only: o token não dá canPublish nem canPublishData — o vídeo do membro nunca
 * sobe, e o chat anda pelo Reverb (não pelo data channel do LiveKit). O room_name
 * nunca chega aqui. Refresh renova o JWT antes do TTL; 410/403 (encerrada ou
 * removido) → desconecta.
 */
const props = defineProps({
    performer: { type: Object, required: true },
    token: { type: String, required: true },
    wsUrl: { type: String, required: true },
    viewerCount: { type: Number, default: 0 },
    initialChat: { type: Array, default: () => [] },
    // Live começa pausada? (performer já em chamada quando o membro entrou.)
    paused: { type: Boolean, default: false },
    // Chamada privada A PARTIR da live (feat/private-call-from-live).
    profileId: { type: Number, default: 0 },
    callPricePerMinute: { type: Number, default: null },
    myUserId: { type: Number, default: 0 },
    tokenPackages: { type: Array, default: () => [] },
    needsCpf: { type: Boolean, default: false },
})

const videoEl = ref(null)
const audioEl = ref(null)
const status = ref('connecting') // connecting | live | ended | error
const viewers = ref(props.viewerCount)
const gifts = ref([])
const showGifts = ref(false)
const notice = ref('')

// PAUSA: a performer entrou numa chamada privada. Os OUTROS espectadores veem
// "volta já"; quem está NA chamada vê a <PrivateCall> (callState='in-call').
const paused = ref(props.paused)
const callState = ref('idle') // idle | requesting | waiting | declined | in-call
const activeCall = ref(null)
const callError = ref('')
const canRequestCall = computed(() => props.callPricePerMinute && props.myUserId && callState.value === 'idle')

let room = null
let refreshTimer = null
let viewersTimer = null
let liveChannel = null
let userChannel = null

const slug = props.performer.slug

function attach(track) {
    if (track.kind === 'video' && videoEl.value) track.attach(videoEl.value)
    if (track.kind === 'audio' && audioEl.value) track.attach(audioEl.value)
}

async function connect(token) {
    room = new Room()
    room.on(RoomEvent.TrackSubscribed, (track) => attach(track))
    room.on(RoomEvent.Disconnected, () => { if (status.value !== 'ended') status.value = 'ended' })

    await room.connect(props.wsUrl, token)
    status.value = 'live'

    room.remoteParticipants.forEach((p) =>
        p.trackPublications.forEach((pub) => { if (pub.track) attach(pub.track) }),
    )
}

async function refresh() {
    try {
        await postJson(route('live.refresh', slug))
    } catch (e) {
        // 410 (encerrada) ou 403 (removido) — o acesso deixou de ser reautorizado.
        await teardown()
        status.value = 'ended'
    }
}

async function refreshViewers() {
    if (status.value !== 'live') return
    try {
        const data = await getJson(route('live.viewer-count', slug))
        viewers.value = data.viewers
        // Reconcilia o estado de pausa para quem perdeu o broadcast LiveStateChanged.
        if (typeof data.paused === 'boolean') paused.value = data.paused
    } catch (e) {
        // Poll perdido é inócuo; o encerramento chega pelo refresh do JWT.
    }
}

// ── Chamada privada A PARTIR da live (feat/private-call-from-live) ─────────────

function subscribeLiveState() {
    if (!window.Echo) return
    // MESMO canal do chat/overlay — stopListening no fim, nunca Echo.leave.
    liveChannel = window.Echo.private(`live.${slug}`)
    liveChannel.listen('.live.state', (e) => { paused.value = !!e.paused })
}

async function requestCall() {
    callState.value = 'requesting'
    callError.value = ''
    try {
        const { call_id } = await postJson(route('call.request', props.profileId))
        callState.value = 'waiting'
        listenForCallAnswer(call_id)
    } catch (e) {
        callState.value = 'idle'
        callError.value = errorMessage(e, 'Não foi possível pedir a chamada agora.')
    }
}

function listenForCallAnswer(callId) {
    if (!window.Echo) return
    // user.{id} é COMPARTILHADO (MessageToast/ReservationNotice no AppLayout) —
    // stopListening dos eventos da chamada no fim, NUNCA Echo.leave.
    userChannel = window.Echo.private(`user.${props.myUserId}`)
    userChannel.listen('.call.accepted', async (e) => {
        if (e.call_id !== callId) return
        stopCallAnswer()
        try {
            const { token, wsUrl } = await postJson(route('call.token-refresh', callId))
            activeCall.value = { callId, token, wsUrl }
            callState.value = 'in-call'
        } catch (err) {
            callState.value = 'idle'
            callError.value = 'A chamada foi aceita, mas não foi possível conectar.'
        }
    })
    userChannel.listen('.call.declined', (e) => {
        if (e.call_id !== callId) return
        stopCallAnswer()
        // Some discretamente, sem constranger.
        callState.value = 'declined'
        setTimeout(() => { if (callState.value === 'declined') callState.value = 'idle' }, 4000)
    })
}

function stopCallAnswer() {
    if (userChannel) {
        userChannel.stopListening('.call.accepted')
        userChannel.stopListening('.call.declined')
        userChannel = null
    }
}

function onCallEnded() {
    activeCall.value = null
    callState.value = 'idle'
    stopCallAnswer()
    // O <video> da live foi DESMONTADO enquanto ela via a chamada; ao voltar, o
    // elemento remonta VAZIO — reanexa a faixa da live já recebida (padrão do #206:
    // faixa não pode ficar órfã num elemento que o Vue remontou). A performer retoma
    // sozinha (chama resume); `paused=false` chega pelo broadcast e o vídeo volta.
    nextTick(reattachLiveTracks)
}

function reattachLiveTracks() {
    if (!room) return
    room.remoteParticipants.forEach((p) =>
        p.trackPublications.forEach((pub) => { if (pub.track) attach(pub.track) }),
    )
}

async function teardown() {
    if (refreshTimer) { clearInterval(refreshTimer); refreshTimer = null }
    if (viewersTimer) { clearInterval(viewersTimer); viewersTimer = null }
    // Canal compartilhado: stopListening, nunca Echo.leave.
    if (liveChannel) { liveChannel.stopListening('.live.state'); liveChannel = null }
    stopCallAnswer()
    if (room) { await room.disconnect(); room = null }
}

async function sendTip(amount) {
    notice.value = ''
    try {
        await postJson(route('tips.send'), { performer_slug: slug, amount, idempotency_key: crypto.randomUUID() })
        notice.value = `Gorjeta de ${amount} enviada 💛`
    } catch (e) {
        notice.value = errorMessage(e, 'Não foi possível enviar a gorjeta.')
    }
}

async function sendGift(gift) {
    notice.value = ''
    try {
        await postJson(route('gifts.send'), { performer_slug: slug, gift_slug: gift.slug, idempotency_key: crypto.randomUUID() })
        notice.value = `${gift.name} enviado ✨`
        showGifts.value = false
    } catch (e) {
        notice.value = errorMessage(e, 'Não foi possível enviar o presente.')
    }
}

function sendChat(body) {
    return postJson(route('live.chat', slug), { body })
}

onMounted(async () => {
    try {
        await connect(props.token)
        subscribeLiveState()
        // A contagem inicial vem do show() ANTES do membro entrar na sala LiveKit
        // (conta a si mesmo a menos). Repuxa já que conectou, e depois no ritmo da
        // mesma fonte cacheada (~12s) que a performer usa — igual dos dois lados.
        refreshViewers()
        refreshTimer = setInterval(refresh, 4 * 60 * 1000)
        viewersTimer = setInterval(refreshViewers, 12000)
        const data = await getJson(route('gifts.catalog'))
        gifts.value = data?.gifts ?? []
    } catch (e) {
        status.value = 'error'
    }
})

onBeforeUnmount(teardown)
</script>

<template>
    <div class="flex flex-col gap-4 lg:h-[calc(100dvh-9rem)] lg:flex-row">
        <!-- Vídeo dominante + barra de ações compacta. -->
        <div class="flex min-h-0 flex-col gap-3 lg:flex-1">
            <!-- Em chamada privada 1:1: a sala substitui o vídeo da live (só para
                 QUEM está na chamada; os demais espectadores veem "volta já"). -->
            <div v-if="callState === 'in-call' && activeCall" class="min-h-0 lg:flex-1">
                <PrivateCall
                    :call-id="activeCall.callId"
                    :token="activeCall.token"
                    :ws-url="activeCall.wsUrl"
                    role="member"
                    :price-per-minute="callPricePerMinute"
                    :token-packages="tokenPackages"
                    :needs-cpf="needsCpf"
                    @ended="onCallEnded"
                />
            </div>

            <template v-else>
            <div class="relative aspect-video overflow-hidden rounded-xl border border-frame bg-black lg:aspect-auto lg:flex-1">
                <video ref="videoEl" autoplay playsinline class="h-full w-full object-contain" />
                <audio ref="audioEl" autoplay />

                <LiveOverlay :performer-slug="performer.slug" />

                <div v-if="status !== 'live'" class="absolute inset-0 flex items-center justify-center text-sm text-cream/80">
                    <span v-if="status === 'connecting'">Conectando à live…</span>
                    <span v-else-if="status === 'ended'">A live foi encerrada.</span>
                    <span v-else>Não foi possível carregar a live.</span>
                </div>

                <!-- PAUSA: a performer entrou numa chamada privada. O vídeo dela some
                     (a chamada roda numa sala LiveKit SEPARADA — nada dela vaza aqui),
                     mas o membro NÃO é desconectado e o chat continua. -->
                <div v-else-if="paused" role="status" aria-live="polite" class="absolute inset-0 flex flex-col items-center justify-center gap-1 bg-black/75 px-6 text-center">
                    <span class="font-serif text-lg text-cream">Em chamada privada — volta já</span>
                    <span class="text-xs text-cream/70">A performer voltará em instantes. O chat continua funcionando.</span>
                </div>

                <div class="absolute left-3 top-3 flex items-center gap-2">
                    <span class="rounded-full bg-limen-live px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-white">Ao vivo</span>
                    <span class="rounded-full bg-black/60 px-2.5 py-1 text-[11px] text-cream">👁 {{ viewers }}</span>
                </div>

                <div class="absolute bottom-3 left-3 flex items-center gap-2">
                    <img v-if="performer.avatar_url" :src="performer.avatar_url" alt="" class="h-8 w-8 rounded-full object-cover ring-1 ring-white/20" >
                    <span class="rounded-full bg-black/60 px-2.5 py-1 font-serif text-sm text-cream">{{ performer.stage_name }}</span>
                </div>
            </div>

            <!-- Barra de ações: nunca cobre o vídeo. -->
            <div class="shrink-0 space-y-2">
                <p v-if="notice" class="rounded-lg border border-gold/30 bg-surface px-3 py-2 text-sm text-cream">{{ notice }}</p>
                <p v-if="callError" class="rounded-lg border border-danger/40 bg-danger/10 px-3 py-2 text-sm text-danger">{{ callError }}</p>

                <!-- Chamada privada A PARTIR da live (feat/private-call-from-live). -->
                <button
                    v-if="canRequestCall"
                    type="button"
                    :disabled="status !== 'live'"
                    class="mi-press flex min-h-[44px] w-full items-center justify-center gap-2 rounded-lg border border-gold/50 bg-gold/10 px-4 text-sm font-semibold text-gold hover:bg-gold/20 disabled:opacity-40"
                    @click="requestCall"
                >
                    📹 Pedir chamada privada · {{ callPricePerMinute }} 🪙/min
                </button>
                <div v-else-if="callState === 'requesting' || callState === 'waiting'" class="rounded-lg border border-frame bg-surface px-4 py-2.5 text-sm text-cream">
                    Aguardando a performer aceitar a chamada…
                </div>
                <div v-else-if="callState === 'declined'" class="rounded-lg border border-frame bg-surface px-4 py-2.5 text-sm text-muted">
                    A performer não pôde atender agora.
                </div>

                <div class="flex items-center gap-2 overflow-x-auto rounded-xl border border-frame bg-surface p-2">
                    <span class="shrink-0 pl-1 text-[11px] uppercase tracking-wide text-muted">Gorjeta</span>
                    <button
                        v-for="amount in [10, 50, 100]"
                        :key="amount"
                        type="button"
                        :disabled="status !== 'live'"
                        class="mi-press shrink-0 rounded-lg border border-gold/40 px-3 py-1.5 text-sm text-gold hover:bg-gold/10 disabled:opacity-40"
                        @click="sendTip(amount)"
                    >
                        {{ amount }} 🪙
                    </button>

                    <span class="mx-1 h-5 w-px shrink-0 bg-frame" />

                    <button
                        v-if="gifts.length"
                        type="button"
                        :disabled="status !== 'live'"
                        class="mi-press shrink-0 rounded-lg border border-frame px-3 py-1.5 text-sm text-cream hover:border-gold/40 disabled:opacity-40"
                        @click="showGifts = !showGifts"
                    >
                        🎁 Presentes
                    </button>
                </div>

                <!-- Presentes: abre inline sob a barra, some ao enviar; não sobre o vídeo. -->
                <div v-if="showGifts && gifts.length" class="grid grid-cols-2 gap-2 rounded-xl border border-frame bg-surface p-3 sm:grid-cols-3">
                    <button
                        v-for="gift in gifts"
                        :key="gift.slug"
                        type="button"
                        :disabled="status !== 'live'"
                        class="mi-press flex items-center gap-2 rounded-lg border border-frame px-3 py-2 text-left hover:border-gold/40 disabled:opacity-40"
                        @click="sendGift(gift)"
                    >
                        <span class="h-6 w-6 shrink-0 text-gold"><GiftIcon :slug="gift.slug" /></span>
                        <span class="min-w-0">
                            <span class="block truncate text-sm text-cream">{{ gift.name }}</span>
                            <span class="block text-xs text-gold">{{ gift.price_tokens }} 🪙</span>
                        </span>
                    </button>
                </div>
            </div>
            </template>
        </div>

        <!-- Chat: coluna no desktop, preenche o resto no mobile (sem rolar a página). -->
        <div class="flex min-h-0 flex-1 lg:w-[360px] lg:flex-none">
            <LiveChat
                :performer-slug="performer.slug"
                :initial-messages="initialChat"
                :disabled="status !== 'live'"
                placeholder="Fale com a sala…"
                :on-send="sendChat"
                class="w-full"
            />
        </div>
    </div>
</template>
