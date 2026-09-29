<script setup>
// Modal de PEDIDO de encomenda sob medida (Onda 4 §4.3), aberto no perfil público da
// performer só para membro logado. O membro descreve o que quer e OFERECE um preço; o
// token só se move quando a performer ACEITA (escrow no CustomOrderService). Aqui é só a
// intenção — POST custom-orders.store {profile:slug}. O servidor é a fonte da verdade do
// preço (piso/passo/teto em monetization.custom_order); os limites abaixo são só dica de UI.
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import Button from '@/Components/Button.vue'
import Modal from '@/Components/Modal.vue'
import { postJson } from '@/lib/http'

const props = defineProps({
    show: { type: Boolean, default: false },
    performerSlug: { type: String, required: true },
    performerName: { type: String, default: '' },
    // Dicas de UI — o servidor valida de verdade (monetization.custom_order).
    minPrice: { type: Number, default: 20 },
    priceStep: { type: Number, default: 5 },
    maxPrice: { type: Number, default: 20000 },
    descriptionMax: { type: Number, default: 500 },
})

const emit = defineEmits(['close', 'created'])

const description = ref('')
const price = ref(props.minPrice)
const sending = ref(false)
const error = ref('')

watch(
    () => props.show,
    (open) => {
        if (open) {
            error.value = ''
            description.value = ''
            price.value = props.minPrice
        }
    },
)

const priceValue = computed(() => {
    const n = Number.parseInt(price.value, 10)
    return Number.isNaN(n) ? 0 : n
})

const canSend = computed(() =>
    description.value.trim().length > 0
    && description.value.length <= props.descriptionMax
    && priceValue.value >= props.minPrice
    && priceValue.value <= props.maxPrice
    && priceValue.value % props.priceStep === 0
    && !sending.value,
)

async function submit() {
    if (!canSend.value) return
    error.value = ''
    sending.value = true
    try {
        const data = await postJson(route('custom-orders.store', props.performerSlug), {
            description: description.value.trim(),
            price_tokens: priceValue.value,
        })
        emit('created', data)
        emit('close')
        // Leva o membro à fila de encomendas para acompanhar o aceite.
        router.visit(route('custom-orders.index'))
    } catch (e) {
        if (e.status === 422 && e.data?.reason === 'insufficient_balance') {
            error.value = 'Saldo insuficiente para o preço oferecido — o débito ocorre quando a performer aceita.'
        } else if (e.status === 429) {
            error.value = 'Muitos pedidos em pouco tempo. Aguarde um instante.'
        } else {
            error.value = e.data?.message ?? 'Não foi possível enviar o pedido. Tente novamente.'
        }
    } finally {
        sending.value = false
    }
}
</script>

<template>
    <Modal :show="show" max-width="md" @close="emit('close')">
        <div class="space-y-5">
            <h3 class="font-serif text-2xl text-limen-ink">Encomendar conteúdo</h3>
            <p class="text-sm text-limen-ink-soft">
                Descreva o que você gostaria de {{ performerName }} e ofereça um preço. Ela pode
                aceitar ou recusar — o valor só é debitado quando ela aceita, e fica retido até a
                entrega.
            </p>

            <div>
                <label for="co-desc" class="block text-sm text-limen-ink-soft">O que você quer</label>
                <textarea
                    id="co-desc"
                    v-model="description"
                    rows="4"
                    :maxlength="descriptionMax"
                    placeholder="Descreva a ideia com o máximo de detalhe possível…"
                    class="mt-1 w-full rounded-lg border border-limen-line bg-limen-surface px-3 py-2 text-sm text-limen-ink placeholder:text-limen-ink-mute focus:border-limen-gold focus:outline-none"
                ></textarea>
                <p class="mt-1 text-right text-xs text-limen-ink-mute">{{ description.length }}/{{ descriptionMax }}</p>
            </div>

            <div>
                <label for="co-price" class="block text-sm text-limen-ink-soft">Preço oferecido (tokens)</label>
                <!-- Input nativo (não o componente Input.vue, que não repassa o `step`):
                     as setas ↑↓ andam de {{ priceStep }} em {{ priceStep }}. -->
                <input
                    id="co-price"
                    v-model="price"
                    type="number"
                    inputmode="numeric"
                    :min="minPrice"
                    :max="maxPrice"
                    :step="priceStep"
                    :placeholder="`Mínimo ${minPrice}`"
                    class="mt-1 w-full rounded-lg border border-limen-line bg-limen-surface px-3 py-2 text-sm text-limen-ink placeholder:text-limen-ink-mute focus:border-limen-gold focus:outline-none"
                />
                <p class="mt-1 text-xs text-limen-ink-mute">
                    De {{ minPrice }} a {{ maxPrice }} tokens, em passos de {{ priceStep }}.
                </p>
            </div>

            <p v-if="error" class="text-sm text-limen-live">{{ error }}</p>

            <div class="flex justify-end gap-3 pt-2">
                <Button variant="ghost" :disabled="sending" @click="emit('close')">Cancelar</Button>
                <Button variant="primary" :loading="sending" :disabled="!canSend" @click="submit">
                    Enviar pedido
                </Button>
            </div>
        </div>
    </Modal>
</template>
