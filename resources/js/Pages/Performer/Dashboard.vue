<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Button from '@/Components/Button.vue'

const props = defineProps({
    wallet: { type: Number, required: true },
    totalEarned: { type: Number, required: true },
    tips: { type: Array, required: true },
    followers: { type: Number, required: true },
    kycStatus: { type: String, required: true },
    isLive: { type: Boolean, required: true },
})

const kycBadge = computed(() => {
    return {
        pending: { label: 'Pendente', class: 'bg-gold/10 text-gold border-gold/30' },
        active: { label: 'Verificado', class: 'bg-success/10 text-success border-success/30' },
        rejected: { label: 'Rejeitado', class: 'bg-danger/10 text-danger border-danger/30' },
    }[props.kycStatus] ?? { label: props.kycStatus, class: 'bg-muted/10 text-muted border-frame' }
})

const canGoLive = computed(() => props.kycStatus === 'active')
</script>

<template>
    <AppLayout title="Painel do performer">
        <div class="max-w-6xl mx-auto px-6 py-10 space-y-8">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="space-y-1">
                    <h1 class="font-serif text-4xl text-cream">Painel</h1>
                    <p class="text-muted text-sm">Visão geral dos seus ganhos e atividade.</p>
                </div>
                <Button
                    variant="primary"
                    :disabled="!canGoLive"
                    :title="!canGoLive ? 'Disponível somente após verificação KYC aprovada' : undefined"
                >
                    Ir ao vivo
                </Button>
            </div>

            <!-- Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                <Link
                    :href="route('performer.payouts.index')"
                    class="rounded-xl border border-frame bg-surface p-5 space-y-1 block no-underline hover:border-gold/40 transition-colors"
                >
                    <p class="text-xs text-muted uppercase tracking-wide">Saldo</p>
                    <p class="font-serif text-3xl text-gold">{{ wallet }}</p>
                    <p class="text-xs text-gold/70">Sacar &rarr;</p>
                </Link>

                <div class="rounded-xl border border-frame bg-surface p-5 space-y-1">
                    <p class="text-xs text-muted uppercase tracking-wide">Total ganho</p>
                    <p class="font-serif text-3xl text-gold">{{ totalEarned }}</p>
                    <p class="text-xs text-muted">tokens</p>
                </div>

                <Link
                    :href="route('performer.followers')"
                    class="rounded-xl border border-frame bg-surface p-5 space-y-1 block no-underline hover:border-gold/40 transition-colors"
                >
                    <p class="text-xs text-muted uppercase tracking-wide">Seguidores</p>
                    <p class="font-serif text-3xl text-cream">{{ followers }}</p>
                    <p class="text-xs text-gold/70">Demonstrar interesse &rarr;</p>
                </Link>

                <div class="rounded-xl border border-frame bg-surface p-5 space-y-2">
                    <p class="text-xs text-muted uppercase tracking-wide">Status KYC</p>
                    <span
                        class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-medium"
                        :class="kycBadge.class"
                    >
                        {{ kycBadge.label }}
                    </span>
                </div>
            </div>

            <!-- Tips table -->
            <div class="space-y-3">
                <h2 class="font-serif text-xl text-cream">Últimas gorjetas</h2>

                <div v-if="tips.length === 0" class="rounded-xl border border-frame bg-surface p-8 text-center text-muted text-sm">
                    Nenhuma gorjeta ainda.
                </div>

                <div v-else class="rounded-xl border border-frame bg-surface overflow-hidden">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-frame text-left text-xs text-muted uppercase tracking-wide">
                                <th class="px-5 py-3 font-medium">Fã</th>
                                <th class="px-5 py-3 font-medium">Tokens</th>
                                <th class="px-5 py-3 font-medium">Data</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(tip, i) in tips"
                                :key="i"
                                class="border-b border-frame/50 last:border-b-0"
                            >
                                <td class="px-5 py-3 text-cream">{{ tip.fan }}</td>
                                <td class="px-5 py-3 text-gold">{{ tip.amount }}</td>
                                <td class="px-5 py-3 text-muted">{{ tip.created_at }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
