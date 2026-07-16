<script setup>
// Modal de gorjeta compartilhado entre o catálogo autenticado (Catalog/Show.vue)
// e o perfil público (Performers/Show.vue, só para membro logado). Encapsula
// presets, chave de idempotência e a chamada POST /gorjetas (tips.send), que
// exige sessão + role:consumer no backend.
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import Button from '@/Components/Button.vue'
import Modal from '@/Components/Modal.vue'
import Input from '@/Components/Input.vue'
import { postJson } from '@/lib/http'

const props = defineProps({
    show: { type: Boolean, default: false },
    performerSlug: { type: String, required: true },
    performerName: { type: String, default: '' },
})

const emit = defineEmits(['close', 'sent'])

const TIP_PRESETS = [10, 25, 50, 100]

const tipAmount = ref(50)
const sending = ref(false)
const tipError = ref('')
const toastMessage = ref('')

// Uma chave de idempotência por intenção de gorjeta (por abertura do modal).
// Reutilizada em TODOS os reenvios para que um retry após resposta perdida
// nunca debite duas vezes — o TipService devolve o tip existente pela mesma
// chave em vez de criar outro (CLAUDE.md, princípio 3).
const tipIdempotencyKey = ref('')

// Reinicia o estado e gera uma nova chave a cada abertura, para que cada
// intenção de gorjeta tenha a sua própria chave de idempotência.
watch(
    () => props.show,
    (open) => {
        if (open) {
            tipError.value = ''
            tipAmount.value = 50
            tipIdempotencyKey.value = crypto.randomUUID()
        }
    },
)

const amountValue = computed(() => {
    const n = Number.parseInt(tipAmount.value, 10)
    return Number.isNaN(n) ? 0 : n
})

const canSend = computed(() => amountValue.value >= 1 && amountValue.value <= 1000 && !sending.value)

function selectPreset(value) {
    tipAmount.value = value
}

async function sendTip() {
    if (!canSend.value) return

    tipError.value = ''
    sending.value = true

    try {
        const data = await postJson(route('tips.send'), {
            performer_slug: props.performerSlug,
            amount: amountValue.value,
            idempotency_key: tipIdempotencyKey.value,
        })

        emit('sent', data)
        emit('close')
        toastMessage.value = 'Gorjeta enviada! 🎉'
        setTimeout(() => (toastMessage.value = ''), 4000)
    } catch (error) {
        if (error.status === 422 && error.data?.reason === 'insufficient_balance') {
            router.visit(route('wallet.index'))
            return
        }
        if (error.status === 429) {
            tipError.value = 'Muitas gorjetas em pouco tempo. Aguarde um instante.'
        } else {
            tipError.value = error.data?.message ?? 'Não foi possível enviar a gorjeta. Tente novamente.'
        }
    } finally {
        sending.value = false
    }
}
</script>

<template>
    <Modal :show="show" max-width="sm" @close="emit('close')">
        <div class="space-y-5">
            <h3 class="font-serif text-2xl text-cream">Enviar gorjeta</h3>
            <p class="text-sm text-muted">
                Escolha quantos tokens enviar para {{ performerName }}.
            </p>

            <div class="grid grid-cols-4 gap-2">
                <button
                    v-for="preset in TIP_PRESETS"
                    :key="preset"
                    type="button"
                    class="rounded-lg border px-2 py-2.5 text-sm transition-colors"
                    :class="amountValue === preset
                        ? 'border-gold bg-gold/10 text-gold'
                        : 'border-frame bg-surface text-muted hover:border-gold/40'"
                    @click="selectPreset(preset)"
                >
                    {{ preset }}
                </button>
            </div>

            <Input
                id="tip-amount"
                v-model="tipAmount"
                type="number"
                min="1"
                max="1000"
                label="Valor em tokens"
                placeholder="Ex: 50"
            />

            <p v-if="tipError" class="text-sm text-danger">{{ tipError }}</p>

            <div class="flex justify-end gap-3 pt-2">
                <Button variant="ghost" :disabled="sending" @click="emit('close')">Cancelar</Button>
                <Button variant="primary" :loading="sending" :disabled="!canSend" @click="sendTip">
                    Enviar {{ amountValue }} tokens
                </Button>
            </div>
        </div>
    </Modal>

    <!-- Toast -->
    <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="opacity-0 translate-y-2"
        enter-to-class="opacity-100 translate-y-0"
        leave-active-class="transition duration-150 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
    >
        <div
            v-if="toastMessage"
            class="fixed bottom-6 left-1/2 -translate-x-1/2 rounded-lg border border-gold/30 bg-surface px-5 py-3 text-sm text-cream shadow-2xl z-50"
        >
            {{ toastMessage }}
        </div>
    </Transition>
</template>
