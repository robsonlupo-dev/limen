<script setup>
import { ref, onBeforeUnmount } from 'vue'
import { Room, Track } from 'livekit-client'
import { postJson } from '@/lib/http'

/**
 * Group show 1:X (Sprint 15) — lado da PERFORMER (estúdio). Publica câmera+mic
 * para N membros pagantes (view-only). Início/fim batem em group.start / group.stop.
 * O room_name nunca chega ao cliente — a sala vem dentro do token.
 *
 * Upgrade para 1:1: assina o canal privado user.{id} e, ao receber
 * `group.upgrade.requested`, mostra o FanAlias do solicitante (nunca o id) + os
 * botões aceitar/recusar. Aceitar → group.upgrade.accept (os outros saem em 10s).
 */
const props = defineProps({
    myUserId: { type: Number, required: true },
    defaultPrice: { type: Number, default: 10 },
})

const videoEl = ref(null)
const status = ref('idle') // idle | starting | live | stopping | error
const error = ref('')
const price = ref(props.defaultPrice)
const maxParticipants = ref(5)
const groupId = ref(null)
const upgrade = ref(null) // { groupId, memberLabel }
const upgradeBusy = ref(false)

let room = null
let channel = null

async function goLive() {
    status.value = 'starting'
    error.value = ''
    try {
        const { token, wsUrl } = await postJson(route('group.start'), {
            price_per_minute: price.value,
            max_participants: maxParticipants.value,
        })

        room = new Room()
        await room.connect(wsUrl, token)
        await room.localParticipant.setCameraEnabled(true)
        await room.localParticipant.setMicrophoneEnabled(true)
        const camPub = room.localParticipant.getTrackPublication(Track.Source.Camera)
        if (camPub?.track && videoEl.value) camPub.track.attach(videoEl.value)

        status.value = 'live'
        subscribeUpgrades()
    } catch (e) {
        error.value = e?.data?.message ?? 'Não foi possível iniciar o group show.'
        status.value = 'error'
        await disconnectRoom()
    }
}

async function endLive() {
    status.value = 'stopping'
    try {
        await postJson(route('group.stop'))
    } finally {
        await disconnectRoom()
        upgrade.value = null
        status.value = 'idle'
    }
}

function subscribeUpgrades() {
    if (!window.Echo) return
    channel = window.Echo.private(`user.${props.myUserId}`)
    channel.listen('.group.upgrade.requested', (e) => {
        upgrade.value = { groupId: e.group_id, memberLabel: e.member_label }
    })
}

async function acceptUpgrade() {
    if (!upgrade.value) return
    upgradeBusy.value = true
    try {
        await postJson(route('group.upgrade.accept', upgrade.value.groupId))
        upgrade.value = null // agora é 1:1 com o solicitante; os outros saem em 10s
    } catch (e) {
        error.value = e?.data?.message ?? 'Não foi possível aceitar.'
    } finally {
        upgradeBusy.value = false
    }
}

async function declineUpgrade() {
    if (!upgrade.value) return
    upgradeBusy.value = true
    try {
        await postJson(route('group.upgrade.decline', upgrade.value.groupId))
    } catch (e) { /* já resolvida */ } finally {
        upgrade.value = null
        upgradeBusy.value = false
    }
}

async function disconnectRoom() {
    if (channel) { window.Echo?.leave(`user.${props.myUserId}`); channel = null }
    if (room) { await room.disconnect(); room = null }
}

onBeforeUnmount(disconnectRoom)
</script>

<template>
    <div class="space-y-4">
        <div class="relative overflow-hidden rounded-xl border border-frame bg-black aspect-video">
            <video ref="videoEl" autoplay muted playsinline class="h-full w-full object-contain" />

            <!-- Pedido de upgrade para 1:1. -->
            <div
                v-if="upgrade"
                class="absolute bottom-4 left-1/2 -translate-x-1/2 w-80 space-y-3 rounded-2xl bg-white/95 p-4 shadow-xl"
            >
                <p class="text-sm font-medium">
                    {{ upgrade.memberLabel }} quer uma sessão privada 1:1.
                </p>
                <div class="flex gap-3">
                    <button
                        type="button"
                        :disabled="upgradeBusy"
                        class="flex-1 rounded-lg bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60"
                        @click="acceptUpgrade"
                    >
                        Aceitar 1:1
                    </button>
                    <button
                        type="button"
                        :disabled="upgradeBusy"
                        class="flex-1 rounded-lg border border-frame px-3 py-2 text-sm hover:bg-neutral-50 disabled:opacity-60"
                        @click="declineUpgrade"
                    >
                        Manter grupo
                    </button>
                </div>
            </div>
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div v-if="status === 'idle' || status === 'error'" class="flex flex-wrap items-end gap-4">
            <label class="text-sm">
                Tokens por minuto
                <input v-model.number="price" type="number" min="5" step="5" class="mt-1 block w-28 rounded-lg border border-frame px-3 py-2" />
            </label>
            <label class="text-sm">
                Máx. de participantes
                <input v-model.number="maxParticipants" type="number" min="2" max="10" class="mt-1 block w-28 rounded-lg border border-frame px-3 py-2" />
            </label>
            <button type="button" class="rounded-lg bg-brand px-4 py-2 font-medium text-white hover:opacity-90" @click="goLive">
                Iniciar group show
            </button>
        </div>

        <div v-else-if="status === 'live'" class="flex items-center gap-3">
            <span class="inline-flex items-center gap-2 text-sm text-emerald-700">
                <span class="h-2 w-2 rounded-full bg-emerald-500" /> No ar — {{ price }} tokens/min por membro
            </span>
            <button type="button" class="rounded-lg bg-red-600 px-4 py-2 font-medium text-white hover:bg-red-700" @click="endLive">
                Encerrar
            </button>
        </div>
    </div>
</template>
