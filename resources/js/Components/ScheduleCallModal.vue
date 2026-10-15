<script setup>
import { ref, computed } from 'vue'
import { postJson } from '@/lib/http'

/**
 * Agendamento de chamada (feat/scheduled-call-v1) — lado do MEMBRO. Botão + modal
 * para agendar uma chamada com a performer: escolhe DIA e HORA e confirma; o sistema
 * TRAVA um depósito (o preço de 1 min). v1 SEM agenda visual.
 *
 * Picker em DOIS campos (dia + hora) em vez do `datetime-local` nativo (UX round):
 * o membro reclamou de não conseguir agendar "para já" sem entender o porquê. Agora o
 * modal:
 *  - deriva a JANELA PERMITIDA do servidor (min_lead_minutes / max_lead_days, mesmos
 *    de config/scheduled_call.php) e mostra "de HH:mm de hoje até DD/MM";
 *  - já abre com um horário VÁLIDO por padrão (o mais cedo possível, arredondado);
 *  - barra no cliente um horário cedo demais com mensagem amigável (antes era só o
 *    422 cru "fora da janela"); o servidor segue sendo a autoridade final.
 *
 * Fuso: o horário digitado é hora LOCAL do mercado (São Paulo por padrão, igual ao
 * backend). Os limites são calculados NO fuso de exibição via Intl — correto mesmo
 * que o aparelho do membro esteja em outro fuso. Strings "YYYY-MM-DDTHH:mm" no fuso
 * de exibição ordenam cronologicamente, então a comparação de janela é lexical.
 */
const props = defineProps({
    performerProfileId: { type: Number, required: true },
    pricePerMinute: { type: Number, required: true },
    tokenBrl: { type: Number, default: 0.6 },
    // Janela de agendamento — espelha config/scheduled_call.php. Defaults batem com
    // os valores fechados pelo PO; o controller passa os reais para não divergir.
    scheduleWindow: {
        type: Object,
        default: () => ({ minLeadMinutes: 45, maxLeadDays: 30, timezone: 'America/Sao_Paulo' }),
    },
})

const emit = defineEmits(['scheduled'])

const showModal = ref(false)
const dateVal = ref('') // YYYY-MM-DD (hora do fuso de exibição)
const timeVal = ref('') // HH:mm
const state = ref('idle') // idle | saving | error | done
const error = ref('')

const tz = computed(() => props.scheduleWindow?.timezone || 'America/Sao_Paulo')
const minLead = computed(() => Number(props.scheduleWindow?.minLeadMinutes ?? 45))
const maxLeadDays = computed(() => Number(props.scheduleWindow?.maxLeadDays ?? 30))

const depositBrl = computed(() =>
    props.tokenBrl > 0 ? `≈ R$ ${(props.pricePerMinute * props.tokenBrl).toFixed(2).replace('.', ',')}` : '',
)

// Hora de parede no fuso de EXIBIÇÃO para um instante (ms). en-CA dá YYYY-MM-DD e
// 24h, independente do fuso do aparelho.
function wallClock(ms) {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: tz.value,
        year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', hour12: false,
    }).formatToParts(new Date(ms))
    const p = {}
    for (const { type, value } of parts) p[type] = value
    const hh = p.hour === '24' ? '00' : p.hour
    return { date: `${p.year}-${p.month}-${p.day}`, time: `${hh}:${p.minute}` }
}

// Limites calculados no OPEN (evita relógio velho de página aberta há muito tempo).
const earliest = ref({ date: '', time: '' }) // agora + min_lead
const latest = ref({ date: '', time: '' }) // agora + max_lead_days
const todayDate = ref('')

const earliestStr = computed(() => `${earliest.value.date}T${earliest.value.time}`)
const latestStr = computed(() => `${latest.value.date}T${latest.value.time}`)
const chosenStr = computed(() =>
    dateVal.value && timeVal.value ? `${dateVal.value}T${timeVal.value}` : '',
)

function ddmm(isoDate) {
    if (!isoDate) return ''
    const [, m, d] = isoDate.split('-')
    return `${d}/${m}`
}

// "hoje às 16:05" quando é o mesmo dia, senão "15/10 às 09:00".
const earliestLabel = computed(() => {
    const e = earliest.value
    if (!e.date) return ''
    const when = e.date === todayDate.value ? 'hoje' : ddmm(e.date)
    return `${when} às ${e.time}`
})

// Prévia do que foi escolhido: "14/10 às 16:30" (reforça que a pessoa sabe o que vai
// agendar antes do OK).
const chosenLabel = computed(() => {
    if (!chosenStr.value) return ''
    const when = dateVal.value === todayDate.value ? 'hoje' : ddmm(dateVal.value)
    return `${when} às ${timeVal.value}`
})

// Horário escolhido é cedo demais? (comparação lexical de strings de parede).
const tooSoon = computed(() => chosenStr.value && chosenStr.value < earliestStr.value)
const tooFar = computed(() => chosenStr.value && chosenStr.value > latestStr.value)

// Folga sobre o corte do servidor. O servidor recusa com 422 se o horário for <
// now()+min_lead AO SEGUNDO; o picker trabalha em minutos. Sem folga, o horário
// exato (now+45) é recusado porque o relógio do servidor avança entre abrir o modal,
// clicar e a requisição chegar. A folga cobre latência + um tempo de uso do modal.
const SAFETY_MS = 90 * 1000

// Arredonda um instante (ms) para CIMA até o próximo múltiplo de 5 min. Como o fuso
// de exibição tem offset de horas inteiras, o múltiplo de 5 min no epoch cai num
// :X0/:X5 de parede — então o horário mostrado fica "redondo".
function ceilTo5MinMs(ms) {
    const step = 5 * 60 * 1000
    return Math.ceil(ms / step) * step
}

// O mais cedo RESERVÁVEL agora (com folga), como {date, time} no fuso de exibição.
function earliestBookable() {
    const ms = ceilTo5MinMs(Date.now() + minLead.value * 60 * 1000 + SAFETY_MS)
    return wallClock(ms)
}

function open() {
    error.value = ''
    state.value = 'idle'

    const now = Date.now()
    todayDate.value = wallClock(now).date
    // Abre já no mais cedo RESERVÁVEL (com folga sobre os 45 min) — assim o horário
    // oferecido nunca volta como "fora da janela" por corrida de relógio.
    earliest.value = earliestBookable()
    latest.value = wallClock(now + maxLeadDays.value * 24 * 60 * 60 * 1000)

    dateVal.value = earliest.value.date
    timeVal.value = earliest.value.time

    showModal.value = true
}

function close() {
    showModal.value = false
}

async function submit() {
    if (!dateVal.value || !timeVal.value) {
        error.value = 'Escolha o dia e a hora.'
        return
    }
    // Revalida contra o relógio AGORA (não o de quando o modal abriu): se a pessoa
    // demorou no modal, o mais cedo reservável pode ter avançado. Atualiza o limite
    // e a dica para o horário atual, evitando o 422 "fora da janela" do servidor.
    const liveEarliest = earliestBookable()
    earliest.value = liveEarliest
    if (chosenStr.value < `${liveEarliest.date}T${liveEarliest.time}`) {
        error.value = `O horário mais próximo que você pode agendar é ${earliestLabel.value}.`
        return
    }
    if (tooFar.value) {
        error.value = `Você pode agendar até ${ddmm(latest.value.date)}.`
        return
    }
    state.value = 'saving'
    error.value = ''
    try {
        const data = await postJson(route('reservations.store', props.performerProfileId), {
            scheduled_at: chosenStr.value,
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
            class="inline-flex items-center gap-2 rounded-lg border border-limen-gold/50 px-4 py-2 font-medium text-limen-gold transition-colors hover:bg-limen-gold/10"
            @click="open"
        >
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 2v3M16 2v3M3.5 9.5h17M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z" />
            </svg>
            Agendar chamada
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
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block text-sm text-limen-ink-soft">
                            Dia
                            <input
                                v-model="dateVal"
                                type="date"
                                :min="earliest.date"
                                :max="latest.date"
                                class="mt-1 min-h-[44px] w-full rounded-lg border border-limen-line bg-limen-bg px-3 py-2 text-limen-ink"
                            />
                        </label>
                        <label class="block text-sm text-limen-ink-soft">
                            Hora
                            <input
                                v-model="timeVal"
                                type="time"
                                step="300"
                                class="mt-1 min-h-[44px] w-full rounded-lg border border-limen-line bg-limen-bg px-3 py-2 text-limen-ink"
                            />
                        </label>
                    </div>

                    <p class="text-xs text-limen-ink-mute">
                        Você pode agendar de <span class="text-limen-ink-soft">{{ earliestLabel }}</span>
                        até <span class="text-limen-ink-soft">{{ ddmm(latest.date) }}</span>.
                    </p>

                    <p
                        v-if="chosenLabel && !tooSoon && !tooFar"
                        class="rounded-lg bg-limen-surface-2 px-4 py-2 text-sm text-limen-ink"
                    >
                        Chamada <span class="font-medium text-limen-gold">{{ chosenLabel }}</span>.
                        Confirme no botão abaixo.
                    </p>

                    <div class="rounded-lg bg-limen-surface-2 px-4 py-3 text-sm text-limen-ink-soft">
                        Ao agendar, travamos um depósito de
                        <span class="font-medium text-limen-gold">{{ pricePerMinute }} tokens</span>
                        <span v-if="depositBrl" class="text-limen-ink-mute">({{ depositBrl }})</span>.
                        Ele paga o 1º minuto quando a chamada acontece; é devolvido se a
                        performer não confirmar, recusar ou não comparecer. Cancelamento grátis
                        até 2h antes.
                    </div>

                    <p v-if="error" class="text-sm text-limen-live">{{ error }}</p>
                    <p v-else-if="tooSoon" class="text-sm text-limen-live">
                        O horário mais próximo que você pode agendar é {{ earliestLabel }}.
                    </p>
                    <p v-else-if="tooFar" class="text-sm text-limen-live">
                        Você pode agendar até {{ ddmm(latest.date) }}.
                    </p>

                    <div class="flex justify-end gap-3">
                        <button type="button" class="rounded-lg px-4 py-2 text-sm text-limen-ink-soft hover:bg-limen-surface-2" @click="close">
                            Cancelar
                        </button>
                        <button
                            type="button"
                            :disabled="state === 'saving' || tooSoon || tooFar"
                            class="rounded-lg bg-limen-gold px-4 py-2 text-sm font-medium text-limen-bg hover:opacity-90 disabled:opacity-60"
                            @click="submit"
                        >
                            {{ state === 'saving' ? 'Agendando…' : 'Agendar' }}
                        </button>
                    </div>
                </template>

                <template v-else>
                    <p class="rounded-lg bg-limen-surface-2 px-4 py-3 text-sm text-limen-ink-soft">
                        <span class="font-medium text-limen-gold">Agendado para {{ chosenLabel }}.</span>
                        A performer precisa confirmar. Acompanhe em
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
