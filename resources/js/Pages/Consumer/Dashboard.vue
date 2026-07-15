<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import LiveBadge from '@/Components/LiveBadge.vue'

const props = defineProps({
    balance: { type: Number, required: true },
    following: { type: Array, required: true },
    followingCount: { type: Number, required: true },
    interests: { type: Object, required: true },
    tips: { type: Array, required: true },
    tipsSummary: { type: Object, required: true },
})

const hasLockedInterests = computed(() => props.interests.locked > 0)

const interestHeadline = computed(() => {
    if (props.interests.locked === 1) return 'Uma performer demonstrou interesse em você'
    if (props.interests.locked > 1) return `${props.interests.locked} performers demonstraram interesse em você`
    if (props.interests.unlocked > 0) return 'Nenhum interesse novo'
    return 'Nenhum interesse ainda'
})
</script>

<template>
    <AppLayout title="Meu painel">
        <div class="max-w-6xl mx-auto px-6 py-10 space-y-10">
            <div class="space-y-1">
                <h1 class="font-serif text-4xl text-cream">Meu painel</h1>
                <p class="text-muted text-sm">Seu saldo, quem você segue e sua atividade.</p>
            </div>

            <!-- Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                <Link
                    :href="route('wallet.index')"
                    class="rounded-xl border border-frame bg-surface p-5 space-y-1 block no-underline hover:border-gold/40 transition-colors"
                >
                    <p class="text-xs text-muted uppercase tracking-wide">Saldo</p>
                    <p class="font-serif text-3xl text-gold">{{ balance }}</p>
                    <p class="text-xs text-gold/70">Comprar tokens &rarr;</p>
                </Link>

                <Link
                    :href="route('interests.index')"
                    class="rounded-xl border p-5 space-y-1 block no-underline transition-colors"
                    :class="hasLockedInterests
                        ? 'border-gold/40 bg-gold/10 hover:border-gold'
                        : 'border-frame bg-surface hover:border-gold/40'"
                >
                    <p class="text-xs text-muted uppercase tracking-wide">Interesses</p>
                    <p class="font-serif text-3xl" :class="hasLockedInterests ? 'text-gold' : 'text-cream'">
                        {{ interests.locked }}
                    </p>
                    <p class="text-xs text-gold/70">Ver interesses &rarr;</p>
                </Link>

                <Link
                    :href="route('catalog')"
                    class="rounded-xl border border-frame bg-surface p-5 space-y-1 block no-underline hover:border-gold/40 transition-colors"
                >
                    <p class="text-xs text-muted uppercase tracking-wide">Seguindo</p>
                    <p class="font-serif text-3xl text-cream">{{ followingCount }}</p>
                    <p class="text-xs text-gold/70">Explorar catálogo &rarr;</p>
                </Link>

                <div class="rounded-xl border border-frame bg-surface p-5 space-y-1">
                    <p class="text-xs text-muted uppercase tracking-wide">Gorjetas enviadas</p>
                    <p class="font-serif text-3xl text-gold">{{ tipsSummary.tokens }}</p>
                    <p class="text-xs text-muted">
                        tokens em {{ tipsSummary.count }}
                        {{ tipsSummary.count === 1 ? 'gorjeta' : 'gorjetas' }}
                    </p>
                </div>
            </div>

            <!-- Interests callout -->
            <Link
                :href="route('interests.index')"
                class="rounded-xl border p-5 flex items-center justify-between gap-4 no-underline transition-colors"
                :class="hasLockedInterests
                    ? 'border-gold/30 bg-gold/10 hover:border-gold/60'
                    : 'border-frame bg-surface hover:border-gold/40'"
            >
                <div class="space-y-1">
                    <p class="text-sm font-medium" :class="hasLockedInterests ? 'text-gold' : 'text-cream'">
                        {{ interestHeadline }}
                    </p>
                    <p class="text-xs text-muted">
                        <template v-if="hasLockedInterests">
                            Desbloqueie para descobrir quem — a identidade só aparece na sua caixa.
                        </template>
                        <template v-else-if="interests.unlocked > 0">
                            Você já revelou {{ interests.unlocked }}
                            {{ interests.unlocked === 1 ? 'performer' : 'performers' }}.
                        </template>
                        <template v-else>
                            Quando alguém demonstrar interesse em você, avisamos aqui.
                        </template>
                    </p>
                </div>
                <span class="text-gold text-sm shrink-0">Abrir &rarr;</span>
            </Link>

            <!-- Following -->
            <div class="space-y-3">
                <div class="flex items-center justify-between gap-4">
                    <h2 class="font-serif text-xl text-cream">Quem eu sigo</h2>
                    <Link
                        v-if="followingCount > following.length"
                        :href="route('catalog')"
                        class="text-sm text-gold hover:text-gold-light transition-colors"
                    >
                        Ver todos ({{ followingCount }})
                    </Link>
                </div>

                <div
                    v-if="following.length === 0"
                    class="rounded-xl border border-frame bg-surface p-10 text-center space-y-2"
                >
                    <p class="text-cream font-serif text-lg">Você ainda não segue ninguém</p>
                    <p class="text-muted text-sm">Siga performers para acompanhar quando entrarem ao vivo.</p>
                    <Link :href="route('catalog')" class="inline-block pt-2 text-sm text-gold hover:text-gold-light transition-colors">
                        Explorar o catálogo
                    </Link>
                </div>

                <div v-else class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                    <Link
                        v-for="performer in following"
                        :key="performer.slug"
                        :href="route('catalog.show', performer.slug)"
                        class="rounded-xl border border-frame bg-surface p-4 flex flex-col items-center gap-2 no-underline hover:border-gold/40 transition-colors"
                    >
                        <div class="relative">
                            <div class="h-16 w-16 rounded-full bg-surface-2 border border-frame overflow-hidden flex items-center justify-center">
                                <img
                                    v-if="performer.avatar_url"
                                    :src="performer.avatar_url"
                                    :alt="performer.stage_name"
                                    class="h-full w-full object-cover"
                                />
                                <span v-else class="font-serif text-xl text-gold">
                                    {{ performer.stage_name?.charAt(0) }}
                                </span>
                            </div>
                            <span v-if="performer.is_live" class="absolute -bottom-1 left-1/2 -translate-x-1/2">
                                <LiveBadge />
                            </span>
                        </div>
                        <p class="text-sm text-cream text-center truncate w-full" :class="performer.is_live && 'pt-2'">
                            {{ performer.stage_name }}
                        </p>
                    </Link>
                </div>
            </div>

            <!-- Tips sent -->
            <div class="space-y-3">
                <div class="flex items-center justify-between gap-4">
                    <h2 class="font-serif text-xl text-cream">Últimas gorjetas enviadas</h2>
                    <Link :href="route('wallet.history')" class="text-sm text-gold hover:text-gold-light transition-colors">
                        Ver extrato
                    </Link>
                </div>

                <div v-if="tips.length === 0" class="rounded-xl border border-frame bg-surface p-8 text-center text-muted text-sm">
                    Você ainda não enviou gorjetas.
                </div>

                <div v-else class="rounded-xl border border-frame bg-surface overflow-hidden">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-frame text-left text-xs text-muted uppercase tracking-wide">
                                <th class="px-5 py-3 font-medium">Performer</th>
                                <th class="px-5 py-3 font-medium">Tokens</th>
                                <th class="px-5 py-3 font-medium">Data</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="tip in tips" :key="tip.id" class="border-b border-frame/50 last:border-b-0">
                                <td class="px-5 py-3 text-cream">{{ tip.performer ?? '—' }}</td>
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
