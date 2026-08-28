<script setup>
import { ref, onBeforeUnmount } from 'vue'
import { postJson, getJson, errorMessage } from '@/lib/http'

/**
 * Compra de tokens SOBRE a chamada (feat/private-call-from-live) — painel por cima
 * do vídeo, sem derrubar a conexão nem abrir aba/janela. Reusa o fluxo existente:
 * wallet.purchase gera o PIX, wallet.pending pola status+saldo até o webhook creditar.
 *
 * O relógio da chamada NÃO para enquanto ele paga (o pai segue com heartbeat/timer).
 * Ao compensar, emite `credited(balance)` — o pai atualiza o saldo e o aviso some.
 * Se ele não pagar a tempo e o saldo zerar, o pai encerra a chamada; o pagamento em
 * andamento ainda compensa depois (a carteira credita pelo webhook) — daí `pending`.
 */
const props = defineProps({
    packages: { type: Array, default: () => [] },
    needsCpf: { type: Boolean, default: false },
})

const emit = defineEmits(['credited', 'close', 'pending-changed'])

const step = ref('choose') // choose | paying
const cpf = ref('')
const error = ref('')
const busy = ref(false)
const pix = ref(null) // { payment_id, pix_code, pix_qr_base64 }
const copied = ref(false)

let pollTimer = null

async function buy(pkg) {
    error.value = ''
    if (props.needsCpf && cpf.value.replace(/\D/g, '').length !== 11) {
        error.value = 'Informe um CPF válido para gerar o PIX.'
        return
    }
    busy.value = true
    try {
        const data = await postJson(route('wallet.purchase', pkg.id), props.needsCpf ? { cpf: cpf.value } : {})
        pix.value = { paymentId: data.payment_id, code: data.pix_code, qr: data.pix_qr_base64 }
        step.value = 'paying'
        emit('pending-changed', true) // o pai passa a saber que há pagamento em andamento
        startPolling()
    } catch (e) {
        error.value = errorMessage(e, 'Não foi possível gerar a cobrança PIX.')
    } finally {
        busy.value = false
    }
}

// Pola o saldo até o webhook creditar — a chamada segue rodando enquanto isso.
function startPolling() {
    stopPolling()
    pollTimer = setInterval(checkPending, 4000)
}

async function checkPending() {
    if (!pix.value) return
    try {
        const data = await getJson(route('wallet.pending', { payment_id: pix.value.paymentId }))
        if (data.status === 'paid') {
            stopPolling()
            emit('pending-changed', false)
            emit('credited', data.balance) // saldo novo vale na hora, sem recarregar
        } else if (data.status === 'failed' || data.status === 'expired') {
            stopPolling()
            emit('pending-changed', false)
            error.value = 'O pagamento não foi concluído. Você pode tentar de novo.'
            step.value = 'choose'
            pix.value = null
        }
    } catch (e) {
        // Poll perdido é inócuo — o próximo tenta de novo.
    }
}

function stopPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null }
}

async function copyCode() {
    try {
        await navigator.clipboard.writeText(pix.value.code)
        copied.value = true
        setTimeout(() => { copied.value = false }, 2000)
    } catch (e) {
        // Sem clipboard: o membro copia manualmente do campo.
    }
}

onBeforeUnmount(stopPolling)
</script>

<template>
    <div class="flex max-h-full flex-col overflow-hidden rounded-xl border border-gold/40 bg-surface/95 backdrop-blur">
        <div class="flex items-center justify-between border-b border-frame px-4 py-2.5">
            <p class="text-sm font-semibold text-cream">Comprar tokens sem sair da chamada</p>
            <button type="button" class="rounded p-1 text-muted hover:text-cream" aria-label="Fechar" @click="emit('close')">✕</button>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3">
            <p v-if="error" class="mb-2 rounded-lg border border-danger/40 bg-danger/10 px-3 py-2 text-[13px] text-danger">{{ error }}</p>

            <!-- Escolha do pacote. -->
            <div v-if="step === 'choose'" class="space-y-2">
                <div v-if="needsCpf" class="space-y-1">
                    <label class="text-[12px] text-muted">CPF (para o PIX)</label>
                    <input
                        v-model="cpf"
                        inputmode="numeric"
                        placeholder="000.000.000-00"
                        class="w-full rounded-lg border border-frame bg-background px-3 py-2 text-sm text-cream placeholder:text-muted focus:border-gold/50 focus:outline-none"
                    />
                </div>
                <button
                    v-for="pkg in packages"
                    :key="pkg.id"
                    type="button"
                    :disabled="busy"
                    class="mi-press flex w-full items-center justify-between rounded-lg border border-frame px-3 py-2 text-left hover:border-gold/50 disabled:opacity-50"
                    @click="buy(pkg)"
                >
                    <span class="min-w-0">
                        <span class="block text-sm text-cream">{{ pkg.tokens }} tokens<span v-if="pkg.bonus" class="text-gold"> +{{ pkg.bonus }}</span></span>
                        <span class="block text-[11px] text-muted">{{ pkg.name }}</span>
                    </span>
                    <span class="shrink-0 text-sm font-semibold text-gold">{{ pkg.price_formatted }}</span>
                </button>
            </div>

            <!-- Pagando: PIX + espera do webhook. -->
            <div v-else class="space-y-3 text-center">
                <img v-if="pix?.qr" :src="`data:image/png;base64,${pix.qr}`" alt="QR do PIX" class="mx-auto h-40 w-40 rounded-lg bg-white p-1" />
                <button
                    type="button"
                    class="mi-press w-full rounded-lg border border-gold/40 px-3 py-2 text-sm text-gold hover:bg-gold/10"
                    @click="copyCode"
                >
                    {{ copied ? 'Código copiado ✓' : 'Copiar código PIX' }}
                </button>
                <p class="text-[12px] leading-snug text-muted">
                    Pague no app do seu banco. A chamada continua — assim que o pagamento compensar, o saldo aparece aqui sozinho.
                </p>
                <span class="inline-flex items-center gap-2 text-[12px] text-cream">
                    <span class="mi-loading-dot h-2 w-2 rounded-full bg-gold" /> Aguardando o pagamento…
                </span>
            </div>
        </div>
    </div>
</template>
