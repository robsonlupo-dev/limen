<script setup>
import { ref, computed, onBeforeUnmount } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { postJson, postFormWithProgress, errorMessage } from '@/lib/http'

/**
 * Fila de encomendas sob medida (Onda 4 §4.3) — lado da PERFORMER. Lista os pedidos
 * recebidos (CustomOrderPresenter::forPerformer) por FanAlias (M.13.10 — nunca id/nome
 * do membro), com aceitar (escrow: debita o membro e retém), recusar, e entregar (envia
 * uma peça — foto ou vídeo — que passa pela MESMA moderação/CSAM do cofre). O crédito
 * 80/20 só cai quando o membro aprova (ou o prazo de contestação passa).
 */
const props = defineProps({
    orders: { type: Array, default: () => [] },
})

const page = usePage()
const myUserId = page.props.auth?.user?.id

const busyId = ref(null)
const error = ref('')
// Progresso do upload da entrega (0–100). O envio pode ser um vídeo de até 500 MB;
// sem barra a performer achava que travou.
const uploadPct = ref(0)

// Estado do upload de entrega, por encomenda.
const deliverFor = ref(null) // id da encomenda com o seletor de arquivo aberto
const file = ref(null)
// Recado opcional que acompanha a entrega (o membro lê). Passa pelo filtro anti-contato
// no servidor — mesma regra da descrição do pedido.
const deliveryMsg = ref('')
// Prévia local do arquivo escolhido (para a performer conferir ANTES de enviar). É um
// object URL do próprio navegador — nada sobe até ela confirmar em "Enviar entrega".
const previewUrl = ref(null)
const previewIsVideo = computed(() => (file.value?.type ?? '').startsWith('video/'))

function clearPreview() {
    if (previewUrl.value) { URL.revokeObjectURL(previewUrl.value); previewUrl.value = null }
    file.value = null
    deliveryMsg.value = ''
}

const STATUS = {
    requested: 'Novo pedido',
    accepted: 'Aceita — produza e entregue',
    delivered: 'Entregue — aguardando o membro',
    released: 'Concluída (crédito liberado)',
    refunded: 'Estornada ao membro',
    declined: 'Recusada',
    disputed: 'Em disputa (moderação)',
    cancelled: 'Cancelada pelo membro',
    expired: 'Expirada (sem resposta a tempo)',
}

async function act(order, routeName, confirmMsg) {
    if (busyId.value) return
    if (confirmMsg && !window.confirm(confirmMsg)) return
    busyId.value = order.id
    error.value = ''
    try {
        await postJson(route(routeName, order.id))
        router.reload({ only: ['orders'] })
    } catch (e) {
        error.value = errorMessage(e, 'Não foi possível concluir a ação.')
    } finally {
        busyId.value = null
    }
}

const accept = (o) => act(o, 'performer.custom-orders.accept', 'Aceitar este pedido? O valor é debitado do membro e fica retido até a entrega ser aprovada.')
const decline = (o) => act(o, 'performer.custom-orders.decline', 'Recusar este pedido?')

function openDeliver(order) {
    deliverFor.value = order.id
    clearPreview()
    error.value = ''
}

function closeDeliver() {
    deliverFor.value = null
    clearPreview()
}

function onFile(e) {
    clearPreview()
    const f = e.target.files?.[0] ?? null
    file.value = f
    if (f) previewUrl.value = URL.createObjectURL(f)
}

async function submitDelivery(order) {
    if (busyId.value || !file.value) return
    busyId.value = order.id
    error.value = ''
    uploadPct.value = 0
    const form = new FormData()
    form.append('arquivo', file.value)
    if (deliveryMsg.value.trim() !== '') form.append('mensagem', deliveryMsg.value.trim())
    try {
        await postFormWithProgress(
            route('performer.custom-orders.deliver', order.id),
            form,
            (frac) => { uploadPct.value = Math.round(frac * 100) },
        )
        deliverFor.value = null
        clearPreview()
        router.reload({ only: ['orders'] })
    } catch (e) {
        error.value = errorMessage(e, 'Não foi possível entregar. Verifique o arquivo e tente de novo.')
    } finally {
        busyId.value = null
        uploadPct.value = 0
    }
}

// Reverb: o membro pediu/cancelou/aprovou/contestou → recarrega a fila.
let channel = null
if (window.Echo && myUserId) {
    channel = window.Echo.private(`user.${myUserId}`)
    channel.listen('.custom_order.changed', () => router.reload({ only: ['orders'] }))
}
onBeforeUnmount(() => {
    if (channel && myUserId) { window.Echo?.leave(`user.${myUserId}`); channel = null }
    clearPreview()
})
</script>

<template>
    <AppLayout title="Encomendas">
        <div class="mx-auto max-w-3xl px-6 py-10">
            <h1 class="font-serif text-2xl text-limen-ink">Encomendas recebidas</h1>
            <p class="mt-1 text-sm text-limen-ink-soft">
                Ao aceitar, o valor é debitado do membro e fica retido até ele aprovar a entrega —
                então seu crédito (80%) é liberado.
            </p>

            <p v-if="error" class="mt-4 rounded-lg bg-limen-live/10 px-4 py-2 text-sm text-limen-live">{{ error }}</p>

            <div v-if="orders.length === 0" class="mt-10 rounded-2xl border border-limen-line bg-limen-surface p-8 text-center text-limen-ink-soft">
                Nenhuma encomenda por enquanto.
            </div>

            <ul v-else class="mt-6 space-y-3">
                <li
                    v-for="order in orders"
                    :key="order.id"
                    class="rounded-2xl border border-limen-line bg-limen-surface p-4"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="font-medium text-limen-ink">{{ order.fan }}</p>
                            <p class="mt-1 text-sm text-limen-ink-soft">{{ order.description }}</p>
                            <p class="mt-1 text-xs text-limen-ink-mute">{{ STATUS[order.status] ?? order.status }}</p>
                        </div>
                        <span class="shrink-0 text-sm text-limen-gold">{{ order.price }} tk</span>
                    </div>

                    <!-- Confirmação do que foi entregue: a performer revê a peça que enviou
                         (foto, ou o frame de capa do vídeo — não há player da dona aqui). -->
                    <div v-if="order.delivered" class="mt-3">
                        <template v-if="order.delivered.poster">
                            <img
                                :src="order.delivered.poster"
                                alt="Peça entregue"
                                class="max-h-56 rounded-xl border border-limen-line object-cover"
                            />
                            <p v-if="order.delivered.kind === 'video'" class="mt-1 text-xs text-limen-ink-mute">
                                Vídeo entregue (capa acima).
                            </p>
                        </template>
                        <p v-else class="rounded-lg bg-limen-surface-2 px-3 py-2 text-xs text-limen-ink-soft">
                            {{ order.delivered.kind === 'video' ? 'Vídeo em processamento…' : 'Preparando a mídia…' }}
                        </p>
                        <p v-if="order.delivery_message" class="mt-2 rounded-lg bg-limen-surface-2 px-3 py-2 text-sm text-limen-ink-soft">
                            <span class="text-limen-ink-mute">Seu recado: </span>{{ order.delivery_message }}
                        </p>
                    </div>

                    <div v-if="order.can_accept || order.can_decline || order.can_deliver" class="mt-3">
                        <div v-if="order.can_accept || order.can_decline" class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                :disabled="busyId === order.id"
                                class="rounded-lg bg-limen-gold px-3 py-1.5 text-sm font-medium text-limen-bg hover:opacity-90 disabled:opacity-60"
                                @click="accept(order)"
                            >Aceitar</button>
                            <button
                                type="button"
                                :disabled="busyId === order.id"
                                class="rounded-lg border border-limen-line px-3 py-1.5 text-sm text-limen-ink-soft hover:bg-limen-surface-2 disabled:opacity-60"
                                @click="decline(order)"
                            >Recusar</button>
                        </div>

                        <div v-if="order.can_deliver">
                            <button
                                v-if="deliverFor !== order.id"
                                type="button"
                                class="rounded-lg bg-limen-gold px-3 py-1.5 text-sm font-medium text-limen-bg hover:opacity-90"
                                @click="openDeliver(order)"
                            >Entregar</button>

                            <div v-else class="space-y-2">
                                <input
                                    type="file"
                                    accept="image/jpeg,image/png,video/mp4,video/quicktime,video/webm"
                                    class="block w-full text-sm text-limen-ink-soft file:mr-3 file:rounded-lg file:border-0 file:bg-limen-surface-2 file:px-3 file:py-1.5 file:text-sm file:text-limen-ink"
                                    @change="onFile"
                                />

                                <!-- Prévia do arquivo escolhido, para conferir antes de enviar. -->
                                <div v-if="previewUrl" class="rounded-xl border border-limen-line p-2">
                                    <p class="mb-1 text-xs text-limen-ink-mute">Prévia — confira antes de enviar:</p>
                                    <img
                                        v-if="!previewIsVideo"
                                        :src="previewUrl"
                                        alt="Prévia da entrega"
                                        class="max-h-56 rounded-lg object-cover"
                                    />
                                    <video
                                        v-else
                                        :src="previewUrl"
                                        controls
                                        playsinline
                                        class="max-h-56 rounded-lg"
                                    ></video>
                                </div>

                                <!-- Recado opcional para o membro (lido junto da entrega). -->
                                <div>
                                    <label class="mb-1 block text-xs text-limen-ink-mute">
                                        Recado para o membro (opcional)
                                    </label>
                                    <textarea
                                        v-model="deliveryMsg"
                                        rows="2"
                                        maxlength="500"
                                        :disabled="busyId === order.id"
                                        placeholder="Ex.: Espero que você goste! Fiz com carinho."
                                        class="block w-full rounded-lg border border-limen-line bg-limen-bg px-3 py-2 text-sm text-limen-ink placeholder:text-limen-ink-mute focus:border-limen-gold focus:outline-none disabled:opacity-60"
                                    ></textarea>
                                    <p class="mt-1 text-xs text-limen-ink-mute">
                                        Sem telefone, e-mail ou redes sociais — a conversa segue no chat.
                                    </p>
                                </div>

                                <div class="flex gap-2">
                                    <button
                                        type="button"
                                        :disabled="busyId === order.id || !file"
                                        class="rounded-lg bg-limen-gold px-3 py-1.5 text-sm font-medium text-limen-bg hover:opacity-90 disabled:opacity-60"
                                        @click="submitDelivery(order)"
                                    >{{ busyId === order.id ? `Enviando… ${uploadPct}%` : 'Enviar entrega' }}</button>
                                    <button
                                        type="button"
                                        :disabled="busyId === order.id"
                                        class="rounded-lg border border-limen-line px-3 py-1.5 text-sm text-limen-ink-soft hover:bg-limen-surface-2"
                                        @click="closeDeliver()"
                                    >Cancelar</button>
                                </div>

                                <!-- Barra de progresso do upload (vídeo grande). Em
                                     100% o arquivo subiu e o servidor está processando. -->
                                <div v-if="busyId === order.id" class="space-y-1">
                                    <div class="h-2 w-full overflow-hidden rounded-full bg-limen-surface-2">
                                        <div class="h-full rounded-full bg-limen-gold transition-all duration-150" :style="{ width: uploadPct + '%' }"></div>
                                    </div>
                                    <p class="text-xs text-limen-ink-mute">
                                        {{ uploadPct < 100 ? `Enviando… ${uploadPct}%` : 'Enviado — processando no servidor…' }}
                                    </p>
                                </div>
                                <p class="text-xs text-limen-ink-mute">
                                    Foto (JPG/PNG) ou vídeo (MP4/MOV/WebM). O conteúdo passa pela moderação antes de ficar disponível.
                                </p>
                            </div>
                        </div>
                    </div>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
