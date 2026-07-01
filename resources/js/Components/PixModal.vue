<script setup>
import { computed, onUnmounted, ref, watch } from 'vue'
import Modal from '@/Components/Modal.vue'
import Button from '@/Components/Button.vue'
import { getJson } from '@/lib/http'

const props = defineProps({
    show: { type: Boolean, default: false },
    payment: { type: Object, default: null },
})

const emit = defineEmits(['close', 'paid'])

const status = ref('pending')
const remainingSeconds = ref(0)
const copied = ref(false)
let pollTimer = null
let countdownTimer = null

const statusLabel = computed(() => ({
    pending: 'Aguardando pagamento',
    paid: 'Pago ✓',
    expired: 'Expirado',
    failed: 'Falhou',
}[status.value] ?? status.value))

const statusClass = computed(() => ({
    pending: 'bg-gold/10 text-gold border-gold/30 animate-pulse',
    paid: 'bg-success/10 text-success border-success/30',
    expired: 'bg-danger/10 text-danger border-danger/30',
    failed: 'bg-danger/10 text-danger border-danger/30',
}[status.value] ?? 'bg-muted/10 text-muted border-frame'))

const countdown = computed(() => {
    const total = Math.max(0, remainingSeconds.value)
    const h = String(Math.floor(total / 3600)).padStart(2, '0')
    const m = String(Math.floor((total % 3600) / 60)).padStart(2, '0')
    const s = String(total % 60).padStart(2, '0')
    return `${h}:${m}:${s}`
})

function startCountdown() {
    if (!props.payment?.expires_at) return
    const expiresAt = new Date(props.payment.expires_at).getTime()

    const tick = () => {
        remainingSeconds.value = Math.floor((expiresAt - Date.now()) / 1000)
        if (remainingSeconds.value <= 0 && status.value === 'pending') {
            status.value = 'expired'
            stopPolling()
        }
    }
    tick()
    countdownTimer = setInterval(tick, 1000)
}

function stopCountdown() {
    clearInterval(countdownTimer)
    countdownTimer = null
}

async function poll() {
    if (!props.payment?.payment_id) return

    try {
        const data = await getJson(route('wallet.pending', { payment_id: props.payment.payment_id }))
        status.value = data.status

        if (data.status === 'paid') {
            stopPolling()
            stopCountdown()
            emit('paid', data.balance)
            setTimeout(() => emit('close'), 1200)
        } else if (data.status === 'expired' || data.status === 'failed') {
            stopPolling()
        }
    } catch {
        // Transient network error — keep polling until it recovers or expires.
    }
}

function startPolling() {
    poll()
    pollTimer = setInterval(poll, 3000)
}

function stopPolling() {
    clearInterval(pollTimer)
    pollTimer = null
}

async function copyCode() {
    if (!props.payment?.pix_code) return
    await navigator.clipboard.writeText(props.payment.pix_code)
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
}

function handleClose() {
    stopPolling()
    stopCountdown()
    emit('close')
}

watch(
    () => props.show,
    (visible) => {
        if (visible) {
            status.value = 'pending'
            copied.value = false
            startCountdown()
            startPolling()
        } else {
            stopPolling()
            stopCountdown()
        }
    },
)

onUnmounted(() => {
    stopPolling()
    stopCountdown()
})
</script>

<template>
    <Modal :show="show" max-width="sm" @close="handleClose">
        <div v-if="payment" class="space-y-5 text-center">
            <h3 class="font-serif text-2xl text-cream">Pagamento via PIX</h3>

            <span
                class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-colors"
                :class="statusClass"
            >
                {{ statusLabel }}
            </span>

            <div class="flex justify-center">
                <div class="rounded-xl border-2 border-gold bg-white p-3">
                    <img
                        :src="`data:image/png;base64,${payment.pix_qr_base64}`"
                        alt="QR Code PIX"
                        class="h-48 w-48"
                    />
                </div>
            </div>

            <div class="space-y-2 text-left">
                <label class="text-xs text-muted uppercase tracking-wide">Código copia e cola</label>
                <div class="flex gap-2">
                    <input
                        :value="payment.pix_code"
                        readonly
                        class="flex-1 min-w-0 rounded-lg border border-frame bg-background px-3 py-2 text-xs text-muted truncate"
                    />
                    <Button type="button" size="sm" variant="ghost" @click="copyCode">
                        {{ copied ? 'Copiado!' : 'Copiar' }}
                    </Button>
                </div>
            </div>

            <p v-if="status === 'pending'" class="text-sm text-muted">
                Expira em <span class="text-cream font-medium">{{ countdown }}</span>
            </p>

            <Button type="button" variant="ghost" class="w-full" @click="handleClose">Fechar</Button>
        </div>
    </Modal>
</template>
