<script setup>
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

defineProps({
    payouts: { type: Object, required: true },
})

const statusLabels = {
    pending: 'Pendente',
    processing: 'Processando',
    paid: 'Pago',
    failed: 'Falhou',
    cancelled: 'Cancelado',
}

const statusClasses = {
    pending: 'bg-gold/10 text-gold border-gold/30',
    processing: 'bg-sky-500/10 text-sky-400 border-sky-500/30',
    paid: 'bg-success/10 text-success border-success/30',
    failed: 'bg-danger/10 text-danger border-danger/30',
    cancelled: 'bg-muted/10 text-muted border-frame',
}

function statusLabel(status) {
    return statusLabels[status] ?? status
}

function statusClass(status) {
    return statusClasses[status] ?? 'bg-muted/10 text-muted border-frame'
}

function formatBrl(value) {
    return Number(value).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}
</script>

<template>
    <AppLayout title="Histórico de saques">
        <div class="max-w-4xl mx-auto px-6 py-10 space-y-8">
            <div class="flex items-center justify-between gap-4">
                <h1 class="font-serif text-4xl text-cream">Histórico de saques</h1>
                <Link :href="route('performer.payouts.index')" class="text-sm text-gold hover:text-gold-light transition-colors">
                    Voltar aos saques
                </Link>
            </div>

            <div v-if="payouts.data.length === 0" class="rounded-xl border border-frame bg-surface p-8 text-center text-muted text-sm">
                Nenhum saque solicitado ainda.
            </div>

            <template v-else>
                <div class="rounded-xl border border-frame bg-surface overflow-hidden">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-frame text-left text-xs text-muted uppercase tracking-wide">
                                <th class="px-5 py-3 font-medium">Tokens</th>
                                <th class="px-5 py-3 font-medium">Valor BRL</th>
                                <th class="px-5 py-3 font-medium">Chave PIX</th>
                                <th class="px-5 py-3 font-medium">Status</th>
                                <th class="px-5 py-3 font-medium">Data</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="payout in payouts.data" :key="payout.id" class="border-b border-frame/50 last:border-b-0">
                                <td class="px-5 py-3 text-cream">{{ payout.tokens }}</td>
                                <td class="px-5 py-3 text-gold">{{ formatBrl(payout.amount_brl) }}</td>
                                <td class="px-5 py-3 text-muted">{{ payout.pix_key_masked }}</td>
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

                <!-- Pagination -->
                <div v-if="payouts.links.length > 3" class="flex flex-wrap justify-center gap-2 pt-4">
                    <template v-for="(link, i) in payouts.links" :key="i">
                        <span
                            v-if="!link.url"
                            class="px-3 py-1.5 text-sm text-muted/50"
                            v-html="link.label"
                        />
                        <Link
                            v-else
                            :href="link.url"
                            preserve-scroll
                            :class="[
                                'px-3 py-1.5 rounded-lg text-sm transition-colors',
                                link.active
                                    ? 'bg-gold text-background'
                                    : 'text-muted hover:text-cream border border-frame',
                            ]"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
