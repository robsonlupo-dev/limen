<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { postJson } from '@/lib/http'
import { useNotificationSound } from '@/composables/useNotificationSound'

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

const { play } = useNotificationSound()

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
        // Som de chamada ao vivo (preferência 'live'); silencioso se desligado
        // ou se o autoplay estiver bloqueado.
        play('live')
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
    <!-- Card no tema ESCURO do painel (antes era tema claro sobre o console
         escuro, o que fazia o "Recusar" outline sumir e parecer travado —
         bug 2). Os dois botões têm cor e contraste explícitos e alvos ≥44px. -->
    <div
        v-if="incoming"
        class="fixed bottom-4 right-4 z-50 w-80 space-y-3 rounded-2xl border border-gold/30 bg-surface p-5 shadow-xl shadow-black/40"
    >
        <header class="flex items-start justify-between gap-2">
            <div class="min-w-0">
                <h3 class="font-serif text-lg text-cream">Chamada recebida</h3>
                <p class="truncate text-sm text-muted">{{ incoming.memberLabel }}</p>
            </div>
            <span class="shrink-0 rounded-full bg-black/40 px-2.5 py-1 text-xs tabular-nums text-cream">
                {{ secondsLeft }}s
            </span>
        </header>

        <p class="text-sm text-muted">
            {{ incoming.pricePerMinute }} tokens por minuto para você.
        </p>

        <p v-if="error" class="text-sm text-danger">{{ error }}</p>

        <div class="flex gap-3">
            <button
                type="button"
                :disabled="busy"
                class="mi-press flex-1 rounded-lg bg-success px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-success/90 disabled:opacity-60"
                @click="accept"
            >
                Aceitar
            </button>
            <button
                type="button"
                :disabled="busy"
                class="mi-press flex-1 rounded-lg border border-frame bg-surface-2 px-4 py-2 text-sm font-semibold text-cream transition-colors hover:border-danger/60 hover:text-danger disabled:opacity-60"
                @click="decline"
            >
                Recusar
            </button>
        </div>
    </div>
</template>
