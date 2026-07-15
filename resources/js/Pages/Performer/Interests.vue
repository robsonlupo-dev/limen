<script setup>
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

defineProps({
    interests: { type: Object, required: true },
    stats: { type: Object, required: true },
    remainingToday: { type: Number, required: true },
    dailyLimit: { type: Number, required: true },
})
</script>

<template>
    <AppLayout title="Interesses enviados">
        <div class="max-w-4xl mx-auto px-6 py-10 space-y-8">
            <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                <div class="space-y-1">
                    <h1 class="font-serif text-4xl text-cream">Interesses enviados</h1>
                    <p class="text-muted text-sm">
                        Quem você sinalizou e quem decidiu revelar você.
                    </p>
                </div>
                <Link :href="route('performer.dashboard')" class="text-sm text-gold hover:text-gold-light transition-colors shrink-0">
                    Voltar ao painel
                </Link>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
                <div class="rounded-xl border border-frame bg-surface p-5 space-y-1">
                    <p class="text-xs text-muted uppercase tracking-wide">Enviados</p>
                    <p class="font-serif text-3xl text-cream">{{ stats.total_sent }}</p>
                    <p class="text-xs text-muted">no total</p>
                </div>

                <div class="rounded-xl border border-frame bg-surface p-5 space-y-1">
                    <p class="text-xs text-muted uppercase tracking-wide">Revelaram você</p>
                    <p class="font-serif text-3xl text-gold">{{ stats.total_unlocked }}</p>
                    <p class="text-xs text-muted">desbloqueios</p>
                </div>

                <Link
                    :href="route('performer.followers')"
                    class="rounded-xl border border-frame bg-surface p-5 space-y-1 block no-underline hover:border-gold/40 transition-colors"
                >
                    <p class="text-xs text-muted uppercase tracking-wide">Restam hoje</p>
                    <p class="font-serif text-3xl" :class="remainingToday > 0 ? 'text-gold' : 'text-muted'">
                        {{ remainingToday }}
                    </p>
                    <p class="text-xs text-gold/70">de {{ dailyLimit }} envios &rarr;</p>
                </Link>
            </div>

            <div class="space-y-3">
                <div
                    v-if="interests.data.length === 0"
                    class="rounded-xl border border-frame bg-surface p-10 text-center space-y-3"
                >
                    <p class="text-cream font-serif text-lg">Você ainda não demonstrou interesse</p>
                    <p class="text-muted text-sm">
                        O interesse parte da sua lista de seguidores — é lá que você escolhe quem sinalizar.
                    </p>
                    <Link :href="route('performer.followers')" class="inline-block text-sm text-gold hover:text-gold-light transition-colors">
                        Ver seguidores &rarr;
                    </Link>
                </div>

                <div
                    v-for="interest in interests.data"
                    :key="interest.id"
                    class="rounded-xl border border-frame bg-surface p-5 flex items-center gap-4"
                >
                    <div class="h-12 w-12 rounded-full bg-surface-2 border border-frame flex items-center justify-center shrink-0">
                        <span class="font-serif text-lg text-gold/60" aria-hidden="true">M</span>
                    </div>

                    <div class="flex-1 min-w-0 space-y-0.5">
                        <p class="text-cream">{{ interest.label }}</p>
                        <p class="text-xs text-muted">Interesse enviado em {{ interest.sent_at }}</p>
                    </div>

                    <div class="shrink-0 text-right space-y-1">
                        <span
                            v-if="interest.status === 'unlocked'"
                            class="inline-flex items-center rounded-full border border-gold/30 bg-gold/10 px-3 py-1 text-xs text-gold"
                        >
                            Revelou você
                        </span>
                        <span
                            v-else
                            class="inline-flex items-center rounded-full border border-frame bg-muted/10 px-3 py-1 text-xs text-muted"
                        >
                            Aguardando
                        </span>
                        <p v-if="interest.unlocked_at" class="text-xs text-muted">
                            em {{ interest.unlocked_at }}
                        </p>
                    </div>
                </div>
            </div>

            <div v-if="interests.last_page > 1" class="flex justify-center gap-2 pt-2">
                <Link
                    v-for="link in interests.links"
                    :key="link.label"
                    :href="link.url ?? '#'"
                    class="rounded-lg border px-3 py-1.5 text-sm transition-colors no-underline"
                    :class="[
                        link.active ? 'border-gold bg-gold/10 text-gold' : 'border-frame text-muted hover:border-gold/40',
                        !link.url && 'pointer-events-none opacity-40',
                    ]"
                    v-html="link.label"
                />
            </div>
        </div>
    </AppLayout>
</template>
