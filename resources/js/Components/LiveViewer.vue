<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { Room, RoomEvent } from 'livekit-client'
import { postJson, getJson } from '@/lib/http'

/**
 * Live pública GRÁTIS — lado do MEMBRO. Assina o stream da performer (view-only:
 * o token não dá canPublish nem canPublishData, então o membro não injeta vídeo
 * nem abre chat P2P). Gorjeta/presente vão pelas rotas existentes (tips.send /
 * gifts.send). O room_name nunca chega aqui — a sala vem DENTRO do token.
 *
 * Refresh: renova o JWT antes do TTL de 5 min. O endpoint reautoriza na leitura;
 * 410 (live encerrada) → desconecta e mostra o encerramento.
 */
const props = defineProps({
    performer: { type: Object, required: true },
    token: { type: String, required: true },
    wsUrl: { type: String, required: true },
    viewerCount: { type: Number, default: 0 },
})

const videoEl = ref(null)
const audioEl = ref(null)
const status = ref('connecting') // connecting | live | ended | error
const viewers = ref(props.viewerCount)
const gifts = ref([])
const notice = ref('')

let room = null
let refreshTimer = null

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

    // Anexa o que a performer já estava publicando quando entramos.
    room.remoteParticipants.forEach((p) =>
        p.trackPublications.forEach((pub) => { if (pub.track) attach(pub.track) }),
    )
}

async function refresh() {
    try {
        await postJson(route('live.refresh', slug))
    } catch (e) {
        // 410 = live encerrada; qualquer outra falha (ex.: membro sem sessão) também
        // encerra do lado do cliente — o acesso deixou de ser reautorizado.
        await teardown()
        status.value = 'ended'
    }
}

async function teardown() {
    if (refreshTimer) { clearInterval(refreshTimer); refreshTimer = null }
    if (room) { await room.disconnect(); room = null }
}

async function sendTip(amount) {
    notice.value = ''
    try {
        await postJson(route('tips.send'), {
            performer_slug: slug,
            amount,
            idempotency_key: crypto.randomUUID(),
        })
        notice.value = `Gorjeta de ${amount} enviada 💛`
    } catch (e) {
        notice.value = e?.data?.message ?? 'Não foi possível enviar a gorjeta.'
    }
}

async function sendGift(gift) {
    notice.value = ''
    try {
        await postJson(route('gifts.send'), {
            performer_slug: slug,
            gift_slug: gift.slug,
            idempotency_key: crypto.randomUUID(),
        })
        notice.value = `${gift.name} enviado ✨`
    } catch (e) {
        notice.value = e?.data?.message ?? 'Não foi possível enviar o presente.'
    }
}

onMounted(async () => {
    try {
        await connect(props.token)
        // Renova antes do TTL de 5 min (LIVEKIT token_ttl=300).
        refreshTimer = setInterval(refresh, 4 * 60 * 1000)
        const data = await getJson(route('gifts.catalog'))
        gifts.value = data?.gifts ?? []
    } catch (e) {
        status.value = 'error'
    }
})

onBeforeUnmount(teardown)
</script>

<template>
    <div class="grid gap-6 lg:grid-cols-[1fr_320px]">
        <div class="space-y-3">
            <div class="relative overflow-hidden rounded-xl border border-frame bg-black aspect-video">
                <video ref="videoEl" autoplay playsinline class="h-full w-full object-contain" />
                <audio ref="audioEl" autoplay />

                <div v-if="status !== 'live'" class="absolute inset-0 flex items-center justify-center text-cream/80 text-sm">
                    <span v-if="status === 'connecting'">Conectando à live…</span>
                    <span v-else-if="status === 'ended'">A live foi encerrada.</span>
                    <span v-else>Não foi possível carregar a live.</span>
                </div>

                <div class="absolute left-3 top-3 flex items-center gap-2">
                    <span class="rounded-full bg-danger/90 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-white">Ao vivo</span>
                    <span class="rounded-full bg-black/60 px-2.5 py-1 text-[11px] text-cream">👁 {{ viewers }}</span>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <img v-if="performer.avatar_url" :src="performer.avatar_url" alt="" class="h-10 w-10 rounded-full object-cover" >
                <div>
                    <p class="font-serif text-lg text-cream">{{ performer.stage_name }}</p>
                    <p class="text-xs text-muted">A live é gratuita. Apoie com gorjeta ou presente.</p>
                </div>
            </div>
        </div>

        <div class="space-y-4">
            <p v-if="notice" class="rounded-lg border border-gold/30 bg-surface px-3 py-2 text-sm text-cream">{{ notice }}</p>

            <div class="rounded-xl border border-frame bg-surface p-4 space-y-3">
                <p class="text-xs uppercase tracking-wide text-muted">Gorjeta</p>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="amount in [10, 50, 100]"
                        :key="amount"
                        type="button"
                        :disabled="status !== 'live'"
                        class="rounded-lg border border-gold/40 px-3 py-1.5 text-sm text-gold hover:bg-gold/10 disabled:opacity-40"
                        @click="sendTip(amount)"
                    >
                        {{ amount }} 🪙
                    </button>
                </div>
            </div>

            <div v-if="gifts.length" class="rounded-xl border border-frame bg-surface p-4 space-y-3">
                <p class="text-xs uppercase tracking-wide text-muted">Presentes</p>
                <div class="grid grid-cols-2 gap-2">
                    <button
                        v-for="gift in gifts"
                        :key="gift.slug"
                        type="button"
                        :disabled="status !== 'live'"
                        class="rounded-lg border border-frame px-3 py-2 text-sm text-cream hover:border-gold/40 disabled:opacity-40"
                        @click="sendGift(gift)"
                    >
                        {{ gift.name }} · {{ gift.price_tokens }} 🪙
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
