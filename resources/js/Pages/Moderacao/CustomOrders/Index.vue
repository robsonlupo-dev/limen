<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import ModeratorLayout from '@/Layouts/ModeratorLayout.vue'

/**
 * Disputas de encomenda sob medida (Onda 4 §4.3) — fila da MODERAÇÃO. Uma entrega
 * contestada fica RETIDA (escrow parado) até um humano decidir: liberar à performer
 * (a peça fica com o membro) ou estornar ao membro (revoga o acesso à peça). O
 * dinheiro e a idempotência vivem no CustomOrderService; aqui é só a decisão.
 *
 * Privacidade: as duas pontas saem pseudonimizadas — a performer pela vitrine pública
 * (stage_name) e o membro SÓ por FanAlias, nunca id/nome/e-mail. Sem a mídia embutida:
 * a prova é servida pelos endpoints de conteúdo (que re-checam acesso), não na prop.
 */
const props = defineProps({
    orders: { type: Array, default: () => [] },
})

const busyId = ref(null)
const error = ref('')

function fmt(iso) {
    if (!iso) return '—'
    return new Date(iso).toLocaleString('pt-BR', {
        timeZone: 'America/Sao_Paulo',
        day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit',
    })
}

function resolve(order, decision) {
    if (busyId.value) return
    const msg = decision === 'release'
        ? 'Liberar o valor à performer? A peça fica com o membro.'
        : 'Estornar o valor ao membro? O acesso à peça é revogado.'
    if (!window.confirm(msg)) return
    busyId.value = order.id
    error.value = ''
    router.post(route('moderacao.custom-orders.resolve', order.id), { decision }, {
        preserveScroll: true,
        onError: () => { error.value = 'Não foi possível resolver a disputa.' },
        onFinish: () => { busyId.value = null },
    })
}
</script>

<template>
    <ModeratorLayout title="Disputas de encomenda">
        <div class="mx-auto max-w-3xl px-6 py-10">
            <h1 class="font-serif text-2xl text-limen-ink">Disputas de encomenda</h1>
            <p class="mt-1 text-sm text-limen-ink-soft">
                Entregas contestadas pelo membro. O valor está retido — decida a favor de um dos lados.
            </p>

            <p v-if="error" class="mt-4 rounded-lg bg-limen-live/10 px-4 py-2 text-sm text-limen-live">{{ error }}</p>

            <div v-if="orders.length === 0" class="mt-10 rounded-2xl border border-limen-line bg-limen-surface p-8 text-center text-limen-ink-soft">
                Nenhuma disputa em aberto.
            </div>

            <ul v-else class="mt-6 space-y-3">
                <li
                    v-for="order in orders"
                    :key="order.id"
                    class="rounded-2xl border border-limen-line bg-limen-surface p-4"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-sm text-limen-ink">
                                <span class="text-limen-gold">{{ order.performer.stage_name }}</span>
                                <span class="text-limen-ink-mute"> · </span>
                                <span>{{ order.fan }}</span>
                            </p>
                            <p class="mt-1 text-sm text-limen-ink-soft">
                                <span class="text-limen-ink-mute">Pedido: </span>{{ order.description }}
                            </p>
                            <p class="mt-2 text-xs text-limen-ink-mute">
                                Entrega: {{ order.delivered ? (order.delivered.kind === 'video' ? 'vídeo' : 'foto') + ' (' + order.delivered.status + ')' : 'sem mídia' }}
                                · aceita em {{ fmt(order.accepted_at) }}
                                · entregue em {{ fmt(order.delivered_at) }}
                            </p>
                        </div>
                        <span class="shrink-0 text-sm text-limen-gold">{{ order.price }} tk</span>
                    </div>

                    <!-- Os dois lados do caso. O MOTIVO do membro é o centro da decisão
                         (destacado); o recado da performer dá o contexto da entrega. -->
                    <div class="mt-3 space-y-2">
                        <div class="rounded-lg border border-limen-live/30 bg-limen-live/5 px-3 py-2">
                            <p class="text-xs font-medium text-limen-live">Motivo da contestação (membro)</p>
                            <p class="mt-0.5 text-sm text-limen-ink-soft">
                                {{ order.dispute_reason || '— (contestação anterior ao campo de motivo)' }}
                            </p>
                        </div>
                        <div v-if="order.delivery_message" class="rounded-lg bg-limen-surface-2 px-3 py-2">
                            <p class="text-xs font-medium text-limen-ink-mute">Recado da performer na entrega</p>
                            <p class="mt-0.5 text-sm text-limen-ink-soft">{{ order.delivery_message }}</p>
                        </div>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-2">
                        <button
                            type="button"
                            :disabled="busyId === order.id"
                            class="rounded-lg bg-limen-gold px-3 py-1.5 text-sm font-medium text-limen-bg hover:opacity-90 disabled:opacity-60"
                            @click="resolve(order, 'release')"
                        >Liberar à performer</button>
                        <button
                            type="button"
                            :disabled="busyId === order.id"
                            class="rounded-lg border border-limen-live/40 px-3 py-1.5 text-sm text-limen-live hover:bg-limen-live/10 disabled:opacity-60"
                            @click="resolve(order, 'refund')"
                        >Estornar ao membro</button>
                    </div>
                </li>
            </ul>
        </div>
    </ModeratorLayout>
</template>
