<script setup>
import { ref, computed, onBeforeUnmount } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Lightbox from '@/Components/Lightbox.vue'
import { postJson, errorMessage } from '@/lib/http'

/**
 * Minhas encomendas sob medida (Onda 4 §4.3) — lado do MEMBRO. Lista os pedidos do
 * próprio membro (CustomOrderPresenter::forMember) com status e ações: cancelar (antes
 * do aceite), aprovar (libera 80/20 à performer) e contestar a entrega (retém o escrow
 * para a moderação). A mídia entregue abre no Lightbox (foto) ou toca inline (vídeo). O
 * token só se moveu no aceite (escrow) — aqui aprovar/contestar decide o destino dele.
 */
const props = defineProps({
    orders: { type: Array, default: () => [] },
    balance: { type: Number, default: 0 },
})

const page = usePage()
const myUserId = page.props.auth?.user?.id

const busyId = ref(null)
const error = ref('')

// Contestação: motivo OBRIGATÓRIO. Abre um campo inline (não um prompt) para o membro
// explicar o problema — é o que o moderador lê para decidir liberar ou estornar.
const disputeFor = ref(null)
const disputeReason = ref('')
const DISPUTE_MIN = 10

// Lightbox das fotos entregues: coleção plana de fotos prontas + índice aberto.
const lightboxIndex = ref(null)
const deliveredPhotos = computed(() =>
    props.orders
        .filter((o) => o.delivered?.kind === 'photo' && o.delivered?.url)
        .map((o) => ({ id: o.id, url: o.delivered.url, full_url: o.delivered.url })),
)
function openPhoto(order) {
    const i = deliveredPhotos.value.findIndex((p) => p.id === order.id)
    if (i !== -1) lightboxIndex.value = i
}

const STATUS = {
    requested: { label: 'Aguardando a performer', tone: 'text-limen-ink-soft' },
    accepted: { label: 'Aceita — produzindo', tone: 'text-limen-gold' },
    delivered: { label: 'Entregue — confira', tone: 'text-limen-gold' },
    released: { label: 'Concluída', tone: 'text-limen-ink-mute' },
    refunded: { label: 'Estornada', tone: 'text-limen-ink-mute' },
    declined: { label: 'Recusada', tone: 'text-limen-ink-mute' },
    disputed: { label: 'Relatado — em análise', tone: 'text-limen-live' },
    cancelled: { label: 'Cancelada', tone: 'text-limen-ink-mute' },
    expired: { label: 'Expirada (sem resposta)', tone: 'text-limen-ink-mute' },
}

function fmtDeadline(iso) {
    if (!iso) return ''
    return new Date(iso).toLocaleString('pt-BR', {
        timeZone: 'America/Sao_Paulo',
        day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit',
    })
}

async function act(order, routeName, confirmMsg) {
    if (busyId.value) return
    if (confirmMsg && !window.confirm(confirmMsg)) return
    busyId.value = order.id
    error.value = ''
    try {
        await postJson(route(routeName, order.id))
        router.reload({ only: ['orders', 'balance'] })
    } catch (e) {
        error.value = errorMessage(e, 'Não foi possível concluir a ação.')
    } finally {
        busyId.value = null
    }
}

const cancel = (o) => act(o, 'custom-orders.cancel', 'Cancelar este pedido?')
const approve = (o) => act(o, 'custom-orders.approve', 'Aprovar a entrega? O valor é liberado para a performer.')

function openDispute(order) {
    disputeFor.value = order.id
    disputeReason.value = ''
    error.value = ''
}

function closeDispute() {
    disputeFor.value = null
    disputeReason.value = ''
}

async function submitDispute(order) {
    if (busyId.value) return
    if (disputeReason.value.trim().length < DISPUTE_MIN) {
        error.value = `Explique o problema com pelo menos ${DISPUTE_MIN} caracteres.`
        return
    }
    busyId.value = order.id
    error.value = ''
    try {
        await postJson(route('custom-orders.dispute', order.id), { motivo: disputeReason.value.trim() })
        disputeFor.value = null
        disputeReason.value = ''
        router.reload({ only: ['orders', 'balance'] })
    } catch (e) {
        error.value = errorMessage(e, 'Não foi possível relatar o problema.')
    } finally {
        busyId.value = null
    }
}

// Reverb: a performer aceitou/recusou/entregou → recarrega a lista.
let channel = null
if (window.Echo && myUserId) {
    channel = window.Echo.private(`user.${myUserId}`)
    channel.listen('.custom_order.changed', () => router.reload({ only: ['orders', 'balance'] }))
}
onBeforeUnmount(() => {
    if (channel && myUserId) { window.Echo?.leave(`user.${myUserId}`); channel = null }
})
</script>

<template>
    <AppLayout title="Minhas encomendas">
        <div class="mx-auto max-w-3xl px-6 py-10">
            <div class="flex items-baseline justify-between">
                <h1 class="font-serif text-2xl text-limen-ink">Minhas encomendas</h1>
                <span class="text-sm text-limen-ink-soft">{{ balance }} tokens</span>
            </div>
            <p class="mt-1 text-sm text-limen-ink-soft">
                O valor é debitado quando a performer aceita e fica retido até você aprovar a entrega.
            </p>

            <p v-if="error" class="mt-4 rounded-lg bg-limen-live/10 px-4 py-2 text-sm text-limen-live">{{ error }}</p>

            <div v-if="orders.length === 0" class="mt-10 rounded-2xl border border-limen-line bg-limen-surface p-8 text-center text-limen-ink-soft">
                Você ainda não fez nenhuma encomenda.
                <Link :href="route('catalog')" class="mt-2 block text-limen-gold hover:underline">Explorar performers →</Link>
            </div>

            <ul v-else class="mt-6 space-y-3">
                <li
                    v-for="order in orders"
                    :key="order.id"
                    class="rounded-2xl border border-limen-line bg-limen-surface p-4"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <Link
                                v-if="order.performer.slug"
                                :href="route('catalog.show', order.performer.slug)"
                                class="font-medium text-limen-ink no-underline hover:text-limen-gold"
                            >{{ order.performer.stage_name }}</Link>
                            <span v-else class="font-medium text-limen-ink">{{ order.performer.stage_name }}</span>
                            <p class="mt-1 text-sm text-limen-ink-soft">{{ order.description }}</p>
                            <p class="mt-1 text-xs" :class="STATUS[order.status]?.tone">
                                {{ STATUS[order.status]?.label ?? order.status }}
                            </p>
                        </div>
                        <span class="shrink-0 text-sm text-limen-ink-mute">{{ order.price }} tk</span>
                    </div>

                    <!-- Mídia entregue -->
                    <div v-if="order.delivered" class="mt-3">
                        <button
                            v-if="order.delivered.kind === 'photo' && order.delivered.url"
                            type="button"
                            class="block overflow-hidden rounded-xl border border-limen-line"
                            @click="openPhoto(order)"
                        >
                            <img :src="order.delivered.url" alt="Conteúdo entregue" class="max-h-64 w-full object-cover" />
                        </button>
                        <video
                            v-else-if="order.delivered.kind === 'video' && order.delivered.url"
                            :src="order.delivered.url"
                            controls
                            playsinline
                            class="max-h-64 w-full rounded-xl border border-limen-line"
                        ></video>
                        <p v-else class="rounded-lg bg-limen-surface-2 px-3 py-2 text-xs text-limen-ink-soft">
                            {{ order.delivered.kind === 'video' ? 'Vídeo em processamento…' : 'Preparando a mídia…' }}
                        </p>
                        <p v-if="order.delivery_message" class="mt-2 rounded-lg bg-limen-surface-2 px-3 py-2 text-sm text-limen-ink-soft">
                            <span class="text-limen-ink-mute">Recado da performer: </span>{{ order.delivery_message }}
                        </p>
                    </div>

                    <p v-if="order.can_approve && order.dispute_deadline_at" class="mt-2 text-xs text-limen-ink-mute">
                        Se você não fizer nada, o pedido é concluído automaticamente e a performer
                        recebe em {{ fmtDeadline(order.dispute_deadline_at) }}.
                    </p>

                    <div v-if="order.can_cancel || order.can_approve || order.can_dispute" class="mt-3 flex flex-wrap gap-2">
                        <button
                            v-if="order.can_cancel"
                            type="button"
                            :disabled="busyId === order.id"
                            class="rounded-lg border border-limen-line px-3 py-1.5 text-sm text-limen-ink-soft hover:bg-limen-surface-2 disabled:opacity-60"
                            @click="cancel(order)"
                        >Cancelar</button>
                        <button
                            v-if="order.can_approve"
                            type="button"
                            :disabled="busyId === order.id"
                            class="rounded-lg bg-limen-gold px-3 py-1.5 text-sm font-medium text-limen-bg hover:opacity-90 disabled:opacity-60"
                            @click="approve(order)"
                        >Aprovar entrega</button>
                        <button
                            v-if="order.can_dispute && disputeFor !== order.id"
                            type="button"
                            :disabled="busyId === order.id"
                            class="rounded-lg border border-limen-live/40 px-3 py-1.5 text-sm text-limen-live hover:bg-limen-live/10 disabled:opacity-60"
                            @click="openDispute(order)"
                        >Relatar problema</button>
                    </div>

                    <!-- Contestação: motivo obrigatório. Vai para a moderação, que lê o
                         relato dos dois lados antes de liberar ou estornar. -->
                    <div v-if="order.can_dispute && disputeFor === order.id" class="mt-3 space-y-2 rounded-xl border border-limen-live/30 bg-limen-live/5 p-3">
                        <label class="block text-sm text-limen-ink">
                            O que houve com esta entrega?
                        </label>
                        <textarea
                            v-model="disputeReason"
                            rows="3"
                            maxlength="500"
                            :disabled="busyId === order.id"
                            placeholder="Explique o problema — ex.: não era o que foi combinado, a imagem veio cortada…"
                            class="block w-full rounded-lg border border-limen-line bg-limen-bg px-3 py-2 text-sm text-limen-ink placeholder:text-limen-ink-mute focus:border-limen-gold focus:outline-none disabled:opacity-60"
                        ></textarea>
                        <p class="text-xs text-limen-ink-mute">
                            A moderação analisa seu relato e a entrega, e decide liberar à performer ou estornar você.
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                :disabled="busyId === order.id"
                                class="rounded-lg bg-limen-live px-3 py-1.5 text-sm font-medium text-white hover:opacity-90 disabled:opacity-60"
                                @click="submitDispute(order)"
                            >Enviar para a moderação</button>
                            <button
                                type="button"
                                :disabled="busyId === order.id"
                                class="rounded-lg border border-limen-line px-3 py-1.5 text-sm text-limen-ink-soft hover:bg-limen-surface-2 disabled:opacity-60"
                                @click="closeDispute()"
                            >Cancelar</button>
                        </div>
                    </div>
                </li>
            </ul>
        </div>

        <Lightbox v-model:index="lightboxIndex" :photos="deliveredPhotos" />
    </AppLayout>
</template>
