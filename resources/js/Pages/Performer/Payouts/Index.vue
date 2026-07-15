<script setup>
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Input from '@/Components/Input.vue'
import Button from '@/Components/Button.vue'

const props = defineProps({
    balance: { type: Number, required: true },
    splitPct: { type: Number, required: true },
    kycOk: { type: Boolean, required: true },
    recent: { type: Array, required: true },
})

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

function estimateBrl(tokens) {
    const value = (Number(tokens) || 0) * 0.099 * (props.splitPct / 100)
    return value.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}

const balanceEstimate = computed(() => estimateBrl(props.balance))

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
                        Saldo disponível:
                        <span class="text-gold font-medium">{{ balance }}</span> tokens
                        <span class="text-muted">(~{{ balanceEstimate }})</span>
                    </p>
                </div>
                <Link :href="route('performer.payouts.history')" class="text-sm text-gold hover:text-gold-light transition-colors">
                    Ver histórico
                </Link>
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
                    placeholder="Mínimo 500, máximo 50.000"
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
