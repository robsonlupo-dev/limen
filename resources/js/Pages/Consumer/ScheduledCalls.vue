<script setup>
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PrivateCall from '@/Components/PrivateCall.vue'
import { postJson } from '@/lib/http'

/**
 * Minhas chamadas agendadas (feat/scheduled-call-v1) — lado do MEMBRO. Lista as
 * reservas do próprio membro (CallReservationPresenter::forMember) com status,
 * horário e ações: cancelar (grátis até T-2h; depois o depósito vai à performer) e
 * ENTRAR quando a performer já entrou (o depósito paga o 1º minuto; os minutos 2+
 * são a cobrança normal do PR #140, dentro do <PrivateCall>).
 */
const props = defineProps({
    reservations: { type: Array, default: () => [] },
    walletBalance: { type: Number, default: 0 },
})

const items = ref(props.reservations.map((r) => ({ ...r })))
const busyId = ref(null)
const error = ref('')

// Chamada ativa após entrar: { callId, token, wsUrl, pricePerMinute }.
const activeCall = ref(null)

const STATUS = {
    pending: { label: 'Aguardando confirmação', tone: 'text-limen-ink-soft' },
    confirmed: { label: 'Confirmada', tone: 'text-limen-gold' },
    completed: { label: 'Realizada', tone: 'text-limen-ink-mute' },
    cancelled: { label: 'Cancelada (reembolsada)', tone: 'text-limen-ink-mute' },
    no_show_member: { label: 'Você não compareceu', tone: 'text-limen-live' },
    no_show_performer: { label: 'Performer não compareceu (reembolsada)', tone: 'text-limen-ink-mute' },
}

function fmt(iso) {
    if (!iso) return ''
    return new Date(iso).toLocaleString('pt-BR', {
        timeZone: 'America/Sao_Paulo',
        day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit',
    })
}

async function cancel(item) {
    if (busyId.value) return
    busyId.value = item.id
    error.value = ''
    try {
        await postJson(route('reservations.cancel', item.id))
        router.reload({ only: ['reservations', 'walletBalance'] })
    } catch (e) {
        error.value = e?.data?.message ?? 'Não foi possível cancelar.'
    } finally {
        busyId.value = null
    }
}

async function enter(item) {
    if (busyId.value) return
    busyId.value = item.id
    error.value = ''
    try {
        const { call_id, token, wsUrl } = await postJson(route('reservations.enter', item.id))
        activeCall.value = { callId: call_id, token, wsUrl, pricePerMinute: item.deposit_tokens }
    } catch (e) {
        error.value = e?.data?.message ?? 'Não foi possível entrar na chamada.'
    } finally {
        busyId.value = null
    }
}

function onEnded() {
    activeCall.value = null
    router.reload({ only: ['reservations', 'walletBalance'] })
}
</script>

<template>
    <AppLayout title="Minhas chamadas">
        <div class="max-w-3xl mx-auto px-6 py-10">
            <h1 class="font-serif text-2xl text-limen-ink">Minhas chamadas agendadas</h1>
            <p class="mt-1 text-sm text-limen-ink-soft">
                O depósito é devolvido se a performer não confirmar, recusar ou não comparecer.
            </p>

            <p v-if="error" class="mt-4 rounded-lg bg-limen-live/10 px-4 py-2 text-sm text-limen-live">{{ error }}</p>

            <div v-if="items.length === 0" class="mt-10 rounded-2xl border border-limen-line bg-limen-surface p-8 text-center text-limen-ink-soft">
                Você ainda não agendou nenhuma chamada.
                <Link :href="route('catalog')" class="mt-2 block text-limen-gold hover:underline">Explorar performers →</Link>
            </div>

            <ul v-else class="mt-6 space-y-3">
                <li
                    v-for="item in items"
                    :key="item.id"
                    class="flex items-center gap-4 rounded-2xl border border-limen-line bg-limen-surface p-4"
                >
                    <img
                        v-if="item.performer.avatar_url"
                        :src="item.performer.avatar_url"
                        alt=""
                        class="h-12 w-12 rounded-full object-cover"
                    />
                    <div class="min-w-0 flex-1">
                        <Link
                            :href="route('catalog.show', item.performer.slug)"
                            class="font-medium text-limen-ink hover:text-limen-gold no-underline"
                        >
                            {{ item.performer.stage_name }}
                        </Link>
                        <p class="text-sm text-limen-ink-soft">{{ fmt(item.scheduled_at) }} · {{ item.slot_minutes }} min</p>
                        <p class="text-xs" :class="STATUS[item.status]?.tone">{{ STATUS[item.status]?.label ?? item.status }}</p>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <span class="text-xs text-limen-ink-mute">{{ item.deposit_tokens }} tk</span>
                        <button
                            v-if="item.can_enter"
                            type="button"
                            :disabled="busyId === item.id"
                            class="rounded-lg bg-limen-gold px-3 py-1.5 text-sm font-medium text-limen-bg hover:opacity-90 disabled:opacity-60"
                            @click="enter(item)"
                        >
                            Entrar
                        </button>
                        <button
                            v-else-if="item.can_cancel"
                            type="button"
                            :disabled="busyId === item.id"
                            class="rounded-lg border border-limen-line px-3 py-1.5 text-sm text-limen-ink-soft hover:bg-limen-surface-2 disabled:opacity-60"
                            @click="cancel(item)"
                        >
                            {{ item.free_cancel ? 'Cancelar' : 'Cancelar (perde depósito)' }}
                        </button>
                    </div>
                </li>
            </ul>
        </div>

        <!-- Sala da chamada agendada: reusa a <PrivateCall> do PR #140. O 1º minuto
             já veio do depósito; daqui em diante é a cobrança por minuto normal. -->
        <div v-if="activeCall" class="fixed inset-0 z-50 bg-black">
            <PrivateCall
                :call-id="activeCall.callId"
                :token="activeCall.token"
                :ws-url="activeCall.wsUrl"
                role="member"
                :price-per-minute="activeCall.pricePerMinute"
                :initial-balance="walletBalance"
                @ended="onEnded"
            />
        </div>
    </AppLayout>
</template>
