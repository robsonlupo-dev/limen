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
        <!-- `bg-brand` não existe no tema (ficava sem fundo); estilo primário do
             design system (gold sobre background), alvo ≥44px. -->
        <button
            type="button"
            class="mi-press inline-flex min-h-[44px] items-center gap-2 rounded-lg bg-gold px-4 font-semibold text-background transition-colors hover:bg-gold/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold/60"
            @click="open"
        >
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.5 10.5 20 8v8l-4.5-2.5M4 6.5h9a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-9a1 1 0 0 1 1-1Z" />
            </svg>
            Chamada privada
        </button>

        <div
            v-if="showModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            @click.self="close"
        >
            <!-- Tema ESCURO do app (antes era bg-white: o <h2> sem cor herdava o
                 texto claro do tema e sumia no branco, e os botões ficavam sem
                 contraste/espaço — "FecharSolicitar". Mesma correção já feita no
                 CallIncoming). Cores explícitas, alvos ≥44px (mobile first). -->
            <div class="w-full max-w-sm space-y-4 rounded-2xl border border-gold/30 bg-surface p-6 text-cream shadow-xl shadow-black/40">
                <header>
                    <h2 class="font-serif text-xl text-cream">Chamada privada 1:1</h2>
                    <p class="mt-1 text-sm text-muted">
                        {{ pricePerMinute }} tokens por minuto
                        <span v-if="priceBrlLabel" class="text-muted/70">{{ priceBrlLabel }}</span>
                    </p>
                </header>

                <p class="text-sm text-muted">
                    O primeiro minuto é cobrado ao ser aceita. Você pode encerrar a qualquer
                    momento — só paga pelos minutos usados.
                </p>

                <p v-if="error" class="text-sm text-danger">{{ error }}</p>

                <div v-if="state === 'waiting'" class="rounded-lg border border-frame bg-background/40 px-4 py-3 text-sm text-cream/80">
                    Aguardando a performer aceitar…
                </div>
                <div v-else-if="state === 'declined'" class="rounded-lg border border-frame bg-background/40 px-4 py-3 text-sm text-cream/80">
                    A performer não pôde atender agora.
                </div>

                <div class="flex justify-end gap-3">
                    <button
                        type="button"
                        class="mi-press min-h-[44px] rounded-lg border border-frame px-4 text-sm font-medium text-cream transition-colors hover:border-gold/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold/60"
                        @click="close"
                    >
                        Fechar
                    </button>
                    <button
                        v-if="state === 'idle' || state === 'error'"
                        type="button"
                        class="mi-press min-h-[44px] rounded-lg bg-gold px-4 text-sm font-semibold text-background transition-colors hover:bg-gold/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold/60"
                        @click="requestCall"
                    >
                        Solicitar
                    </button>
                    <button
                        v-else-if="state === 'requesting'"
                        type="button"
                        disabled
                        class="min-h-[44px] rounded-lg bg-gold px-4 text-sm font-semibold text-background opacity-60"
                    >
                        Enviando…
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
