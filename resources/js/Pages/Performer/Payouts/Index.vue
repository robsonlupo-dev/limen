<script setup>
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Input from '@/Components/Input.vue'
import Button from '@/Components/Button.vue'
import { formatTokens } from '@/lib/tokens'

const props = defineProps({
    balance: { type: Number, required: true },
    payoutRatePerToken: { type: Number, required: true },
    minTokens: { type: Number, required: true },
    maxTokens: { type: Number, required: true },
    withdrawableTokens: { type: Number, required: true },
    kycOk: { type: Boolean, required: true },
    // Previsão de saque (Onda 4): valores autoritativos do servidor (centavos, floor).
    forecast: { type: Object, default: null },
    recent: { type: Array, required: true },
})

// Centavos (inteiro, autoritativo do servidor) → R$. Nunca recalcula a conversão no
// cliente — o floor já foi aplicado no servidor (R2).
function brlFromCentavos(centavos) {
    return ((Number(centavos) || 0) / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}
function fmtMonthDay(iso) {
    if (!iso) return ''
    return new Date(iso).toLocaleDateString('pt-BR', { timeZone: 'America/Sao_Paulo', day: '2-digit', month: 'long' })
}

const pixKeyTypes = [
    { value: 'cpf', label: 'CPF' },
    { value: 'email', label: 'E-mail' },
    { value: 'phone', label: 'Telefone' },
    { value: 'random', label: 'Chave aleatória' },
]

const statusLabels = {
    pending: 'Pendente',
    processing: 'Processando',
    paid: 'Pago',
    failed: 'Falhou',
    cancelled: 'Cancelado',
    needs_review: 'Em análise',
}

const statusClasses = {
    pending: 'bg-gold/10 text-gold border-gold/30',
    processing: 'bg-sky-500/10 text-sky-400 border-sky-500/30',
    paid: 'bg-success/10 text-success border-success/30',
    failed: 'bg-danger/10 text-danger border-danger/30',
    cancelled: 'bg-muted/10 text-muted border-frame',
    needs_review: 'bg-gold/10 text-gold border-gold/30',
}

function statusLabel(status) {
    return statusLabels[status] ?? status
}

function statusClass(status) {
    return statusClasses[status] ?? 'bg-muted/10 text-muted border-frame'
}

// R$0,60/token FIXO (M.13.5) — nunca porcentagem sobre reais.
function estimateBrl(tokens) {
    const value = (Number(tokens) || 0) * props.payoutRatePerToken
    return value.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}

const rateLabel = computed(() =>
    `Cada token vale ${props.payoutRatePerToken.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })} no saque`,
)
const withdrawableEstimate = computed(() => estimateBrl(props.withdrawableTokens))

const form = useForm({
    tokens: '',
    pix_key_type: 'cpf',
    pix_key: '',
})

const requestEstimate = computed(() => estimateBrl(form.tokens))

function submit() {
    form.post(route('performer.payouts.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset('tokens', 'pix_key'),
    })
}
</script>

<template>
    <AppLayout title="Saques">
        <div class="max-w-4xl mx-auto px-6 py-10 space-y-10">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="space-y-1">
                    <h1 class="font-serif text-4xl text-cream">Saques</h1>
                    <p class="text-muted text-sm">
                        Disponível para saque:
                        <span class="text-gold font-medium">{{ formatTokens(withdrawableTokens) }}</span> tokens
                        <span class="text-muted">(~{{ withdrawableEstimate }})</span>
                    </p>
                    <p class="text-muted text-xs">{{ rateLabel }}</p>
                </div>
                <Link :href="route('performer.payouts.history')" class="text-sm text-gold hover:text-gold-light transition-colors">
                    Ver histórico de saques
                </Link>
            </div>

            <!-- Previsão de saque (Onda 4): sacável em R$, próximo saque automático e o
                 que falta pra ele rodar. Só leitura. -->
            <div v-if="forecast" class="rounded-2xl border border-frame bg-surface p-6">
                <h2 class="font-serif text-xl text-cream">Previsão de saque</h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div class="rounded-xl border border-frame bg-limen-bg/40 p-4">
                        <p class="text-[11px] uppercase tracking-wide text-muted">Sacável agora</p>
                        <p class="mt-1 font-sans text-3xl font-semibold text-gold tabular-nums">{{ brlFromCentavos(forecast.withdrawable_centavos) }}</p>
                        <p class="mt-1 text-xs text-muted">
                            {{ formatTokens(forecast.withdrawable_tokens) }} tokens
                            <template v-if="Number(forecast.remainder_tokens) > 0">
                                · sobra {{ formatTokens(forecast.remainder_tokens) }} (fica pro próximo)
                            </template>
                        </p>
                    </div>
                    <div class="rounded-xl border border-frame bg-limen-bg/40 p-4">
                        <p class="text-[11px] uppercase tracking-wide text-muted">Próximo saque automático</p>
                        <p class="mt-1 font-serif text-2xl text-cream">{{ fmtMonthDay(forecast.next_auto_payout_at) }}</p>
                        <p v-if="forecast.auto_eligible" class="mt-1 text-xs text-muted">
                            Estimativa: <span class="text-gold">{{ brlFromCentavos(forecast.next_auto_estimate_centavos) }}</span>
                            (o valor cresce até lá)
                        </p>
                        <p v-else class="mt-1 text-xs text-gold/90">
                            <template v-if="!forecast.kyc_ok">Complete a verificação para entrar no automático.</template>
                            <template v-else-if="!forecast.reaches_minimum">Faltam {{ forecast.tokens_to_minimum }} tokens para o mínimo de {{ forecast.min_tokens }}.</template>
                            <template v-else-if="!forecast.has_payout_key">Faça um 1º saque manual abaixo — depois os próximos caem sozinhos, na sua chave PIX.</template>
                        </p>
                    </div>
                </div>
                <p class="mt-3 text-xs text-muted">
                    O saque automático roda todo dia 1. Você também pode sacar quando quiser, abaixo.
                </p>
            </div>

            <div v-if="!kycOk" class="rounded-xl border border-gold/30 bg-gold/10 p-5 text-sm text-gold">
                Complete a verificação de identidade para sacar.
            </div>

            <form v-else @submit.prevent="submit" class="rounded-xl border border-frame bg-surface p-6 space-y-5">
                <h2 class="font-serif text-xl text-cream">Solicitar saque</h2>

                <Input
                    id="tokens"
                    v-model="form.tokens"
                    type="number"
                    label="Quantidade de tokens"
                    :placeholder="`Mínimo ${minTokens}, máximo ${maxTokens.toLocaleString('pt-BR')}`"
                    required
                    :error="form.errors.tokens"
                />

                <div class="flex flex-col gap-1.5">
                    <label for="pix_key_type" class="text-sm font-medium text-cream">
                        Tipo de chave PIX <span class="text-gold ml-0.5">*</span>
                    </label>
                    <select
                        id="pix_key_type"
                        v-model="form.pix_key_type"
                        class="w-full rounded-lg border border-frame bg-surface px-4 py-3 text-sm text-cream focus:outline-none focus:border-gold focus:ring-1 focus:ring-gold"
                    >
                        <option v-for="type in pixKeyTypes" :key="type.value" :value="type.value">
                            {{ type.label }}
                        </option>
                    </select>
                    <p v-if="form.errors.pix_key_type" class="text-xs text-danger">{{ form.errors.pix_key_type }}</p>
                </div>

                <Input
                    id="pix_key"
                    v-model="form.pix_key"
                    label="Chave PIX"
                    placeholder="Sua chave PIX"
                    required
                    :error="form.errors.pix_key"
                />

                <p v-if="form.errors.kyc" class="text-sm text-danger">{{ form.errors.kyc }}</p>

                <p class="text-sm text-muted">
                    Você receberá <span class="text-gold font-medium">~{{ requestEstimate }}</span>
                </p>

                <Button type="submit" variant="primary" class="w-full" :loading="form.processing">
                    Solicitar saque
                </Button>
            </form>

            <div class="space-y-3">
                <h2 class="font-serif text-xl text-cream">Últimos saques</h2>

                <div v-if="recent.length === 0" class="rounded-xl border border-frame bg-surface p-8 text-center text-muted text-sm">
                    Nenhum saque solicitado ainda.
                </div>

                <div v-else class="rounded-xl border border-frame bg-surface overflow-hidden">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-frame text-left text-xs text-muted uppercase tracking-wide">
                                <th class="px-5 py-3 font-medium">Tokens</th>
                                <th class="px-5 py-3 font-medium">Valor BRL</th>
                                <th class="px-5 py-3 font-medium">Status</th>
                                <th class="px-5 py-3 font-medium">Data</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="payout in recent" :key="payout.id" class="border-b border-frame/50 last:border-b-0">
                                <td class="px-5 py-3 text-cream">{{ payout.tokens }}</td>
                                <td class="px-5 py-3 text-gold">
                                    {{ payout.amount_brl.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }) }}
                                </td>
                                <td class="px-5 py-3">
                                    <span
                                        class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-medium"
                                        :class="statusClass(payout.status)"
                                    >
                                        {{ statusLabel(payout.status) }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-muted">{{ payout.created_at }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
