<script setup>
import { ref, computed, onBeforeUnmount } from 'vue'
import { postJson } from '@/lib/http'

/**
 * Chamada privada 1:1 (Sprint 15) — lado do MEMBRO. Botão + modal para solicitar
 * a chamada a uma performer, mostrando o preço por minuto. Depois do pedido,
 * aguarda a resposta pelo canal privado user.{id} (call.accepted / call.declined)
 * — sem polling. No aceite, emite `accepted` com o call_id para o pai abrir a
 * <PrivateCall> (que busca o token em call.token-refresh).
 */
const props = defineProps({
    performerProfileId: { type: Number, required: true },
    pricePerMinute: { type: Number, required: true },
    myUserId: { type: Number, required: true },
    // R$ equivalente por token (M.13.5, exibição) — opcional.
    tokenBrl: { type: Number, default: 0.6 },
})

const emit = defineEmits(['accepted'])

const state = ref('idle') // idle | requesting | waiting | declined | error
const error = ref('')
const showModal = ref(false)

let channel = null

const priceBrlLabel = computed(() =>
    props.tokenBrl > 0 ? `≈ R$ ${(props.pricePerMinute * props.tokenBrl).toFixed(2).replace('.', ',')}/min` : '',
)

function open() {
    error.value = ''
    state.value = 'idle'
    showModal.value = true
}

function close() {
    showModal.value = false
    leaveChannel()
    if (state.value !== 'waiting') state.value = 'idle'
}

async function requestCall() {
    state.value = 'requesting'
    error.value = ''
    try {
        const { call_id } = await postJson(route('call.request', props.performerProfileId))
        state.value = 'waiting'
        listenForAnswer(call_id)
    } catch (e) {
        state.value = 'error'
        error.value = e?.data?.message ?? 'Não foi possível solicitar a chamada.'
    }
}

function listenForAnswer(callId) {
    if (!window.Echo) return
    channel = window.Echo.private(`user.${props.myUserId}`)
    channel.listen('.call.accepted', (e) => {
        if (e.call_id === callId) {
            leaveChannel()
            showModal.value = false
            emit('accepted', callId)
        }
    })
    channel.listen('.call.declined', (e) => {
        if (e.call_id === callId) {
            leaveChannel()
            state.value = 'declined'
        }
    })
}

function leaveChannel() {
    if (channel) { window.Echo?.leave(`user.${props.myUserId}`); channel = null }
}

onBeforeUnmount(leaveChannel)
</script>

<template>
    <div>
        <button
            type="button"
            class="rounded-lg bg-brand px-4 py-2 font-medium text-white hover:opacity-90"
            @click="open"
        >
            📹 Chamada privada
        </button>

        <div
            v-if="showModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            @click.self="close"
        >
            <div class="w-full max-w-sm space-y-4 rounded-2xl bg-white p-6 shadow-xl">
                <header>
                    <h2 class="text-lg font-semibold">Chamada privada 1:1</h2>
                    <p class="mt-1 text-sm text-neutral-600">
                        {{ pricePerMinute }} tokens por minuto
                        <span v-if="priceBrlLabel" class="text-neutral-400">{{ priceBrlLabel }}</span>
                    </p>
                </header>

                <p class="text-sm text-neutral-500">
                    O primeiro minuto é cobrado ao ser aceita. Você pode encerrar a qualquer
                    momento — só paga pelos minutos usados.
                </p>

                <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

                <div v-if="state === 'waiting'" class="rounded-lg bg-neutral-100 px-4 py-3 text-sm text-neutral-700">
                    Aguardando a performer aceitar…
                </div>
                <div v-else-if="state === 'declined'" class="rounded-lg bg-neutral-100 px-4 py-3 text-sm text-neutral-700">
                    A performer não pôde atender agora.
                </div>

                <div class="flex justify-end gap-3">
                    <button type="button" class="rounded-lg px-4 py-2 text-sm hover:bg-neutral-100" @click="close">
                        Fechar
                    </button>
                    <button
                        v-if="state === 'idle' || state === 'error'"
                        type="button"
                        class="rounded-lg bg-brand px-4 py-2 text-sm font-medium text-white hover:opacity-90"
                        @click="requestCall"
                    >
                        Solicitar
                    </button>
                    <button
                        v-else-if="state === 'requesting'"
                        type="button"
                        disabled
                        class="rounded-lg bg-brand px-4 py-2 text-sm font-medium text-white opacity-60"
                    >
                        Enviando…
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
