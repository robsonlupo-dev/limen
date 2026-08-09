<script setup>
import { ref, computed } from 'vue'
import { postJson } from '@/lib/http'

/**
 * Agendamento de chamada (feat/scheduled-call-v1) — lado do MEMBRO. Botão + modal
 * para agendar uma chamada com a performer: escolhe data/hora e o sistema TRAVA um
 * depósito (o preço de 1 min). v1 SEM agenda visual — um picker simples; o servidor
 * recusa horário ocupado/colidido (buffer) e fora da antecedência.
 *
 * O horário do <input type="datetime-local"> é hora LOCAL (assumida São Paulo, o
 * mercado do produto); o backend a interpreta no fuso de exibição (config).
 */
const props = defineProps({
    performerProfileId: { type: Number, required: true },
    pricePerMinute: { type: Number, required: true },
    tokenBrl: { type: Number, default: 0.6 },
})

const emit = defineEmits(['scheduled'])

const showModal = ref(false)
const scheduledAt = ref('')
const state = ref('idle') // idle | saving | error | done
const error = ref('')

const depositBrl = computed(() =>
    props.tokenBrl > 0 ? `≈ R$ ${(props.pricePerMinute * props.tokenBrl).toFixed(2).replace('.', ',')}` : '',
)

// Mínimo do picker: agora + ~1h (a antecedência mínima real é do servidor; aqui é
// só uma dica de UX). Formato datetime-local: YYYY-MM-DDTHH:mm.
const minDateTime = computed(() => {
    const d = new Date(Date.now() + 60 * 60 * 1000)
    const pad = (n) => String(n).padStart(2, '0')
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
})

function open() {
    error.value = ''
    state.value = 'idle'
    scheduledAt.value = ''
    showModal.value = true
}

function close() {
    showModal.value = false
}

async function submit() {
    if (!scheduledAt.value) {
        error.value = 'Escolha uma data e hora.'
        return
    }
    state.value = 'saving'
    error.value = ''
    try {
        const data = await postJson(route('reservations.store', props.performerProfileId), {
            scheduled_at: scheduledAt.value,
        })
        state.value = 'done'
        emit('scheduled', data)
    } catch (e) {
        state.value = 'error'
        error.value = e?.data?.message ?? 'Não foi possível agendar.'
    }
}
</script>

<template>
    <div>
        <button
            type="button"
            class="rounded-lg border border-limen-gold/50 px-4 py-2 font-medium text-limen-gold transition-colors hover:bg-limen-gold/10"
            @click="open"
        >
            🗓️ Agendar chamada
        </button>

        <div
            v-if="showModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            @click.self="close"
        >
            <div class="w-full max-w-sm space-y-4 rounded-2xl bg-limen-surface p-6 shadow-xl">
                <header>
                    <h2 class="font-serif text-lg text-limen-ink">Agendar chamada privada</h2>
                    <p class="mt-1 text-sm text-limen-ink-soft">
                        {{ pricePerMinute }} tokens por minuto
                    </p>
                </header>

                <template v-if="state !== 'done'">
                    <label class="block text-sm text-limen-ink-soft">
                        Data e hora
                        <input
                            v-model="scheduledAt"
                            type="datetime-local"
                            :min="minDateTime"
                            class="mt-1 w-full rounded-lg border border-limen-line bg-limen-bg px-3 py-2 text-limen-ink"
                        />
                    </label>

                    <div class="rounded-lg bg-limen-surface-2 px-4 py-3 text-sm text-limen-ink-soft">
                        Ao agendar, travamos um depósito de
                        <span class="font-medium text-limen-gold">{{ pricePerMinute }} tokens</span>
                        <span v-if="depositBrl" class="text-limen-ink-mute">({{ depositBrl }})</span>.
                        Ele paga o 1º minuto quando a chamada acontece; é devolvido se a
                        performer não confirmar, recusar ou não comparecer. Cancelamento grátis
                        até 2h antes.
                    </div>

                    <p v-if="error" class="text-sm text-limen-live">{{ error }}</p>

                    <div class="flex justify-end gap-3">
                        <button type="button" class="rounded-lg px-4 py-2 text-sm text-limen-ink-soft hover:bg-limen-surface-2" @click="close">
                            Cancelar
                        </button>
                        <button
                            type="button"
                            :disabled="state === 'saving'"
                            class="rounded-lg bg-limen-gold px-4 py-2 text-sm font-medium text-limen-bg hover:opacity-90 disabled:opacity-60"
                            @click="submit"
                        >
                            {{ state === 'saving' ? 'Agendando…' : 'Agendar' }}
                        </button>
                    </div>
                </template>

                <template v-else>
                    <p class="rounded-lg bg-limen-surface-2 px-4 py-3 text-sm text-limen-ink-soft">
                        Agendamento enviado! A performer precisa confirmar. Acompanhe em
                        <span class="text-limen-gold">Minhas chamadas</span>.
                    </p>
                    <div class="flex justify-end">
                        <button type="button" class="rounded-lg bg-limen-gold px-4 py-2 text-sm font-medium text-limen-bg hover:opacity-90" @click="close">
                            Fechar
                        </button>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
