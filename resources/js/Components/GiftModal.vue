<script setup>
// Modal de PRESENTE pelo perfil (feat/gift-from-profile). Reaproveita o catálogo
// de 6 presentes (Rosa → Diamante) da live e o mesmo endpoint POST /presentes
// (gifts.send) — a economia (débito/crédito/split 80/20) é a mesma, só muda o
// ponto de entrada e o pedido de entrega no chat (deliver_to_chat). Espelha o
// TipModal: presets/idempotência/erros no mesmo padrão da gorjeta.
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import Button from '@/Components/Button.vue'
import Modal from '@/Components/Modal.vue'
import GiftIcon from '@/Components/GiftIcon.vue'
import { getJson, postJson } from '@/lib/http'
import { formatTokens } from '@/lib/tokens'

const props = defineProps({
    show: { type: Boolean, default: false },
    performerSlug: { type: String, required: true },
    performerName: { type: String, default: '' },
})

const emit = defineEmits(['close', 'sent'])

const gifts = ref([])
const catalogLoaded = ref(false)
const catalogError = ref('')
const selectedSlug = ref('')
const sending = ref(false)
const giftError = ref('')
const toastMessage = ref('')

// Uma chave de idempotência por intenção (por abertura do modal), reusada em todo
// reenvio — um retry após resposta perdida nunca debita duas vezes (o GiftService
// devolve o envio existente pela mesma chave). CLAUDE.md, princípio 3.
const idempotencyKey = ref('')

watch(
    () => props.show,
    async (open) => {
        if (!open) return
        giftError.value = ''
        selectedSlug.value = ''
        idempotencyKey.value = crypto.randomUUID()
        if (!catalogLoaded.value) {
            await loadCatalog()
        }
    },
)

async function loadCatalog() {
    catalogError.value = ''
    try {
        const data = await getJson(route('gifts.catalog'))
        gifts.value = data?.gifts ?? []
        catalogLoaded.value = true
    } catch {
        catalogError.value = 'Não foi possível carregar os presentes. Tente novamente.'
    }
}

const selectedGift = computed(() => gifts.value.find((g) => g.slug === selectedSlug.value) ?? null)
const canSend = computed(() => selectedGift.value !== null && !sending.value)

function selectGift(gift) {
    selectedSlug.value = gift.slug
}

async function sendGift() {
    if (!canSend.value) return

    giftError.value = ''
    sending.value = true

    try {
        const data = await postJson(route('gifts.send'), {
            performer_slug: props.performerSlug,
            gift_slug: selectedSlug.value,
            idempotency_key: idempotencyKey.value,
            deliver_to_chat: true,
        })

        emit('sent', data)
        emit('close')
        toastMessage.value = 'Presente enviado'
        setTimeout(() => (toastMessage.value = ''), 4000)
    } catch (error) {
        if (error.status === 422 && error.data?.reason === 'insufficient_balance') {
            router.visit(route('wallet.index'))
            return
        }
        if (error.status === 429) {
            giftError.value = 'Muitos presentes em pouco tempo. Aguarde um instante.'
        } else {
            giftError.value = error.data?.message ?? 'Não foi possível enviar o presente. Tente novamente.'
        }
    } finally {
        sending.value = false
    }
}
</script>

<template>
    <Modal :show="show" max-width="sm" @close="emit('close')">
        <div class="space-y-5">
            <h3 class="font-serif text-2xl text-cream">Enviar presente</h3>
            <p class="text-sm text-muted">
                Escolha um presente para {{ performerName }}. Ele aparece na conversa de vocês.
            </p>

            <p v-if="catalogError" class="text-sm text-danger">{{ catalogError }}</p>

            <div v-else class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                <button
                    v-for="gift in gifts"
                    :key="gift.slug"
                    type="button"
                    class="mi-press flex items-center gap-2 rounded-lg border px-3 py-2 text-left transition-colors"
                    :class="selectedSlug === gift.slug
                        ? 'border-gold bg-gold/10'
                        : 'border-frame bg-surface hover:border-gold/40'"
                    @click="selectGift(gift)"
                >
                    <span class="h-6 w-6 shrink-0 text-gold"><GiftIcon :slug="gift.slug" /></span>
                    <span class="min-w-0">
                        <span class="block truncate text-sm text-cream">{{ gift.name }}</span>
                        <span class="block text-xs text-gold">{{ formatTokens(gift.price_tokens) }} tokens</span>
                    </span>
                </button>
            </div>

            <p v-if="giftError" class="text-sm text-danger">{{ giftError }}</p>

            <div class="flex justify-end gap-3 pt-2">
                <Button variant="ghost" :disabled="sending" @click="emit('close')">Cancelar</Button>
                <Button variant="primary" :loading="sending" :disabled="!canSend" @click="sendGift">
                    {{ selectedGift ? `Enviar ${formatTokens(selectedGift.price_tokens)} tokens` : 'Escolha um presente' }}
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
