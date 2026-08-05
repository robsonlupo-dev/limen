<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { postJson } from '@/lib/http'

/**
 * Chamada privada 1:1 (Sprint 15) — lado da PERFORMER. Assina o canal privado
 * user.{id} e, ao receber `call.requested`, mostra a notificação de chamada com
 * o FanAlias do membro (nunca o id), o preço/min e uma contagem regressiva de
 * 60s. A performer NUNCA vê tier nem saldo do membro (M.13.10).
 *
 * Aceitar → call.accept devolve o JWT dela; emite `accepted` com {callId, token,
 * wsUrl} para o pai montar a <PrivateCall role="performer">. Recusar → call.decline.
 * Se os 60s passam sem resposta, a notificação some (o backend já expira o pending).
 */
const props = defineProps({
    myUserId: { type: Number, required: true },
})

const emit = defineEmits(['accepted'])

const incoming = ref(null) // { callId, memberLabel, pricePerMinute }
const secondsLeft = ref(0)
const busy = ref(false)
const error = ref('')

let channel = null
let countdown = null

function subscribe() {
    if (!window.Echo) return
    channel = window.Echo.private(`user.${props.myUserId}`)
    channel.listen('.call.requested', (e) => {
        incoming.value = {
            callId: e.call_id,
            memberLabel: e.member_label,
            pricePerMinute: e.price_per_minute,
        }
        startCountdown(e.expires_in_seconds ?? 60)
    })
}

function startCountdown(seconds) {
    secondsLeft.value = seconds
    clearCountdown()
    countdown = setInterval(() => {
        secondsLeft.value -= 1
        if (secondsLeft.value <= 0) dismiss()
    }, 1000)
}

function clearCountdown() {
    if (countdown) { clearInterval(countdown); countdown = null }
}

function dismiss() {
    clearCountdown()
    incoming.value = null
    busy.value = false
    error.value = ''
}

async function accept() {
    if (!incoming.value) return
    busy.value = true
    error.value = ''
    try {
        const { token, wsUrl, call_id } = await postJson(route('call.accept', incoming.value.callId))
        clearCountdown()
        const callId = call_id
        incoming.value = null
        busy.value = false
        emit('accepted', { callId, token, wsUrl })
    } catch (e) {
        busy.value = false
        error.value = e?.data?.message ?? 'Não foi possível aceitar.'
        // Chamada já expirou/cancelada → some.
        if (e?.status === 410 || e?.status === 404) dismiss()
    }
}

async function decline() {
    if (!incoming.value) return
    busy.value = true
    try { await postJson(route('call.decline', incoming.value.callId)) } catch (e) { /* já resolvida */ }
    dismiss()
}

onMounted(subscribe)
onBeforeUnmount(() => {
    clearCountdown()
    if (channel) window.Echo?.leave(`user.${props.myUserId}`)
})
</script>

<template>
    <div
        v-if="incoming"
        class="fixed bottom-4 right-4 z-50 w-80 space-y-3 rounded-2xl border border-frame bg-white p-5 shadow-xl"
    >
        <header class="flex items-start justify-between">
            <div>
                <h3 class="font-semibold">Chamada recebida</h3>
                <p class="text-sm text-neutral-600">{{ incoming.memberLabel }}</p>
            </div>
            <span class="rounded-full bg-neutral-100 px-2 py-1 text-xs tabular-nums text-neutral-600">
                {{ secondsLeft }}s
            </span>
        </header>

        <p class="text-sm text-neutral-500">
            {{ incoming.pricePerMinute }} tokens por minuto para você.
        </p>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div class="flex gap-3">
            <button
                type="button"
                :disabled="busy"
                class="flex-1 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60"
                @click="accept"
            >
                Aceitar
            </button>
            <button
                type="button"
                :disabled="busy"
                class="flex-1 rounded-lg border border-frame px-4 py-2 text-sm font-medium hover:bg-neutral-50 disabled:opacity-60"
                @click="decline"
            >
                Recusar
            </button>
        </div>
    </div>
</template>
