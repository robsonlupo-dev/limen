<script setup>
import { ref, onBeforeUnmount } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PrivateCall from '@/Components/PrivateCall.vue'
import { postJson } from '@/lib/http'

/**
 * Fila de chamadas agendadas (feat/scheduled-call-v1) — lado da PERFORMER. Lista as
 * reservas por FanAlias (M.13.10 — nunca id/tier/nome do membro), com confirmar/
 * recusar e ENTRAR na hora. A performer entra PRIMEIRO (abre a sala); o call_id
 * ainda não existe — chega por broadcast (reservation.call_started) quando o membro
 * entra, e a <PrivateCall> passa a poder renovar/encerrar pelas rotas do PR #140.
 */
const props = defineProps({
    reservations: { type: Array, default: () => [] },
    strikeCount: { type: Number, default: 0 },
    strikeThreshold: { type: Number, default: 3 },
})

const page = usePage()
const myUserId = page.props.auth?.user?.id

const items = ref(props.reservations.map((r) => ({ ...r })))
const busyId = ref(null)
const error = ref('')

// Chamada em curso: token/wsUrl vêm do enter; callId começa 0 e é preenchido pelo
// broadcast quando o membro entra.
const activeCall = ref(null)
let channel = null

const STATUS = {
    pending: 'Aguardando sua confirmação',
    confirmed: 'Confirmada',
    completed: 'Realizada',
    cancelled: 'Cancelada',
    no_show_member: 'Membro não compareceu (depósito seu)',
    no_show_performer: 'Você não compareceu',
}

function fmt(iso) {
    if (!iso) return ''
    return new Date(iso).toLocaleString('pt-BR', {
        timeZone: 'America/Sao_Paulo',
        day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit',
    })
}

async function act(item, routeName) {
    if (busyId.value) return
    busyId.value = item.id
    error.value = ''
    try {
        await postJson(route(routeName, item.id))
        router.reload({ only: ['reservations'] })
    } catch (e) {
        error.value = e?.data?.message ?? 'Ação indisponível.'
    } finally {
        busyId.value = null
    }
}

async function enter(item) {
    if (busyId.value) return
    busyId.value = item.id
    error.value = ''
    try {
        const { token, wsUrl } = await postJson(route('performer.reservations.enter', item.id))
        activeCall.value = { token, wsUrl, callId: 0 }
        listenForCallStart(item.id)
    } catch (e) {
        error.value = e?.data?.message ?? 'Não foi possível entrar.'
    } finally {
        busyId.value = null
    }
}

function listenForCallStart(reservationId) {
    if (!window.Echo || !myUserId) return
    channel = window.Echo.private(`user.${myUserId}`)
    channel.listen('.reservation.call_started', (e) => {
        if (e.reservation_id === reservationId && activeCall.value) {
            activeCall.value = { ...activeCall.value, callId: e.call_id }
        }
    })
}

function leaveChannel() {
    if (channel && myUserId) { window.Echo?.leave(`user.${myUserId}`); channel = null }
}

function onEnded() {
    activeCall.value = null
    leaveChannel()
    router.reload({ only: ['reservations'] })
}

onBeforeUnmount(leaveChannel)
</script>

<template>
    <AppLayout title="Agendamentos">
        <div class="max-w-3xl mx-auto px-6 py-10">
            <h1 class="font-serif text-2xl text-cream">Chamadas agendadas</h1>
            <p class="mt-1 text-sm text-muted">
                Confirme para garantir o horário. Confirmar e não comparecer gera um strike.
            </p>

            <p
                v-if="strikeCount > 0"
                class="mt-3 rounded-lg px-4 py-2 text-sm"
                :class="strikeCount >= strikeThreshold ? 'bg-danger/10 text-danger' : 'bg-gold/10 text-gold'"
            >
                Você tem {{ strikeCount }} de {{ strikeThreshold }} strikes de não comparecimento.
                <span v-if="strikeCount >= strikeThreshold">Sua conta está em revisão.</span>
            </p>

            <p v-if="error" class="mt-4 rounded-lg bg-danger/10 px-4 py-2 text-sm text-danger">{{ error }}</p>

            <div v-if="items.length === 0" class="mt-10 rounded-2xl border border-frame/50 bg-surface p-8 text-center text-muted">
                Nenhuma chamada agendada.
            </div>

            <ul v-else class="mt-6 space-y-3">
                <li
                    v-for="item in items"
                    :key="item.id"
                    class="flex items-center gap-4 rounded-2xl border border-frame/50 bg-surface p-4"
                >
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-cream">{{ item.member_label }}</p>
                        <p class="text-sm text-muted">{{ fmt(item.scheduled_at) }} · {{ item.slot_minutes }} min · {{ item.deposit_tokens }} tk</p>
                        <p class="text-xs text-gold/80">{{ STATUS[item.status] ?? item.status }}</p>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <button
                            v-if="item.can_enter"
                            type="button"
                            :disabled="busyId === item.id"
                            class="rounded-lg bg-gold px-3 py-1.5 text-sm font-medium text-background hover:opacity-90 disabled:opacity-60"
                            @click="enter(item)"
                        >
                            Entrar
                        </button>
                        <template v-else>
                            <button
                                v-if="item.can_confirm"
                                type="button"
                                :disabled="busyId === item.id"
                                class="rounded-lg bg-gold px-3 py-1.5 text-sm font-medium text-background hover:opacity-90 disabled:opacity-60"
                                @click="act(item, 'performer.reservations.confirm')"
                            >
                                Confirmar
                            </button>
                            <button
                                v-if="item.can_decline"
                                type="button"
                                :disabled="busyId === item.id"
                                class="rounded-lg border border-frame/60 px-3 py-1.5 text-sm text-muted hover:bg-frame/20 disabled:opacity-60"
                                @click="act(item, 'performer.reservations.decline')"
                            >
                                Recusar
                            </button>
                        </template>
                    </div>
                </li>
            </ul>
        </div>

        <div v-if="activeCall" class="fixed inset-0 z-50 bg-black">
            <PrivateCall
                :call-id="activeCall.callId"
                :token="activeCall.token"
                :ws-url="activeCall.wsUrl"
                role="performer"
                @ended="onEnded"
            />
        </div>
    </AppLayout>
</template>
