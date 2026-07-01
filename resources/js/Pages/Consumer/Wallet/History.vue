<script setup>
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    entries: { type: Object, required: true },
})

const entryTypeLabels = {
    purchase: 'Compra',
    spend_tip: 'Gorjeta enviada',
    tip_credit: 'Gorjeta recebida',
    spend_private: 'Sessão privada',
    spend_camera: 'Câmera',
    payout_reserve: 'Reserva de repasse',
    refund: 'Reembolso',
    bonus: 'Bônus',
    adjustment: 'Ajuste',
}

function entryLabel(type) {
    return entryTypeLabels[type] ?? type
}
</script>

<template>
    <AppLayout title="Histórico da carteira">
        <div class="max-w-4xl mx-auto px-6 py-10 space-y-8">
            <div class="flex items-center justify-between gap-4">
                <h1 class="font-serif text-4xl text-cream">Histórico</h1>
                <Link :href="route('wallet.index')" class="text-sm text-gold hover:text-gold-light transition-colors">
                    Voltar à carteira
                </Link>
            </div>

            <div v-if="entries.data.length === 0" class="rounded-xl border border-frame bg-surface p-8 text-center text-muted text-sm">
                Nenhuma movimentação ainda.
            </div>

            <template v-else>
                <div class="rounded-xl border border-frame bg-surface overflow-hidden">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-frame text-left text-xs text-muted uppercase tracking-wide">
                                <th class="px-5 py-3 font-medium">Tipo</th>
                                <th class="px-5 py-3 font-medium">Tokens</th>
                                <th class="px-5 py-3 font-medium">Saldo após</th>
                                <th class="px-5 py-3 font-medium">Data</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(entry, i) in entries.data" :key="i" class="border-b border-frame/50 last:border-b-0">
                                <td class="px-5 py-3 text-cream">{{ entryLabel(entry.entry_type) }}</td>
                                <td class="px-5 py-3" :class="entry.amount >= 0 ? 'text-success' : 'text-danger'">
                                    {{ entry.amount >= 0 ? '+' : '' }}{{ entry.amount }}
                                </td>
                                <td class="px-5 py-3 text-gold">{{ entry.balance_after }}</td>
                                <td class="px-5 py-3 text-muted">{{ entry.created_at }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="entries.links.length > 3" class="flex flex-wrap justify-center gap-2 pt-4">
                    <template v-for="(link, i) in entries.links" :key="i">
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
