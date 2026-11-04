<script setup>
import { computed, ref } from 'vue'

// Data de nascimento em TRÊS seletores (Dia / Mês por extenso / Ano), no lugar
// do <input type="date"> nativo (feat/growth-entries, pedido do PO em 04/11).
// Motivo: para chegar a um ano de nascimento (ex.: 1981) o calendário nativo
// exige dezenas de toques; três <select> resolvem em 3 toques e no celular
// abrem a RODA nativa do sistema — melhor UX possível para data de NASCIMENTO
// (não é um date-picker genérico: não serve para datas futuras).
//
// Contrato intacto com o servidor: emite a string `YYYY-MM-DD` (o mesmo formato
// que o input nativo mandava) em `modelValue`, ou '' enquanto incompleta — a
// validação (RegisterConsumerRequest) não muda.

const props = defineProps({
    id: { type: String, default: 'birthdate' },
    modelValue: { type: String, default: '' },
    label: { type: String, default: 'Data de nascimento' },
    error: { type: String, default: '' },
    required: { type: Boolean, default: false },
    // Idade mínima: limita o MAIOR ano oferecido (18+ é lei da casa). A
    // validação real continua no servidor — isto só evita oferecer anos inúteis.
    minAge: { type: Number, default: 18 },
})

const emit = defineEmits(['update:modelValue'])

const MONTHS = [
    'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
    'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro',
]

// Rascunho interno das três escolhas. Inicializa do modelValue (volta de erro
// 422 preserva o form do Inertia) e emite para fora SÓ a data completa — o
// servidor nunca vê um valor parcial: incompleto = '' = cai no `required`.
const seed = /^(\d{4})-(\d{2})-(\d{2})$/.exec(props.modelValue || '')
const draft = ref({
    year: seed ? Number(seed[1]) : 0,
    month: seed ? Number(seed[2]) : 0,
    day: seed ? Number(seed[3]) : 0,
})
const parts = computed(() => draft.value)

const currentYear = new Date().getFullYear()
// Do ano de quem faz 18 hoje até 100 anos atrás — ninguém nasce no futuro.
const years = computed(() => {
    const max = currentYear - props.minAge
    const list = []
    for (let y = max; y >= currentYear - 100; y--) list.push(y)
    return list
})

// Dias válidos para o mês/ano escolhidos (fevereiro de bissexto incluso).
// Sem mês escolhido, oferece 31 — o ajuste vem quando o mês entra.
const daysInMonth = computed(() => {
    const { year, month } = parts.value
    if (!month) return 31
    return new Date(year || 2000, month, 0).getDate()
})
const days = computed(() => Array.from({ length: daysInMonth.value }, (_, i) => i + 1))

function setPart(key, rawValue) {
    const value = Number(rawValue) || 0
    const next = { ...draft.value, [key]: value }

    // Trocou para um mês mais curto com dia 31 marcado? Rebaixa para o último
    // dia válido em vez de montar uma data impossível (31/02 → 28 ou 29/02).
    if (next.month) {
        const limit = new Date(next.year || 2000, next.month, 0).getDate()
        if (next.day > limit) next.day = limit
    }

    draft.value = next

    emit('update:modelValue', next.year && next.month && next.day
        ? `${next.year}-${String(next.month).padStart(2, '0')}-${String(next.day).padStart(2, '0')}`
        : '')
}

const selectClass = computed(() => [
    'w-full appearance-none rounded-lg border bg-surface px-3 py-3 pr-8 text-sm text-cream',
    'transition-colors duration-150',
    'focus:outline-none focus:border-gold focus:ring-1 focus:ring-gold',
    props.error ? 'border-danger' : 'border-frame',
])
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <label :for="`${id}-day`" class="text-sm font-medium text-cream">
            {{ label }}
            <span v-if="required" class="text-gold ml-0.5">*</span>
        </label>

        <div class="grid grid-cols-[1fr_1.6fr_1.2fr] gap-2">
            <!-- Dia -->
            <div class="relative">
                <select
                    :id="`${id}-day`"
                    :value="parts.day || ''"
                    :class="selectClass"
                    aria-label="Dia de nascimento"
                    @change="setPart('day', $event.target.value)"
                >
                    <option value="" disabled>Dia</option>
                    <option v-for="d in days" :key="d" :value="d">{{ d }}</option>
                </select>
                <span class="pointer-events-none absolute inset-y-0 right-2.5 flex items-center text-muted" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" class="h-3.5 w-3.5"><path d="m6 9 6 6 6-6" /></svg>
                </span>
            </div>

            <!-- Mês, por extenso — a pessoa vê exatamente o mês em que nasceu. -->
            <div class="relative">
                <select
                    :id="`${id}-month`"
                    :value="parts.month || ''"
                    :class="selectClass"
                    aria-label="Mês de nascimento"
                    @change="setPart('month', $event.target.value)"
                >
                    <option value="" disabled>Mês</option>
                    <option v-for="(name, i) in MONTHS" :key="name" :value="i + 1">{{ name }}</option>
                </select>
                <span class="pointer-events-none absolute inset-y-0 right-2.5 flex items-center text-muted" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" class="h-3.5 w-3.5"><path d="m6 9 6 6 6-6" /></svg>
                </span>
            </div>

            <!-- Ano -->
            <div class="relative">
                <select
                    :id="`${id}-year`"
                    :value="parts.year || ''"
                    :class="selectClass"
                    aria-label="Ano de nascimento"
                    @change="setPart('year', $event.target.value)"
                >
                    <option value="" disabled>Ano</option>
                    <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                </select>
                <span class="pointer-events-none absolute inset-y-0 right-2.5 flex items-center text-muted" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" class="h-3.5 w-3.5"><path d="m6 9 6 6 6-6" /></svg>
                </span>
            </div>
        </div>

        <transition name="mi-error">
            <p v-if="error" class="text-xs text-danger">{{ error }}</p>
        </transition>
    </div>
</template>
