<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

/**
 * Página inicial da moderação (feat/moderator-overview). Resumo das três filas
 * com o nº de pendentes e atalho para cada uma. Só contagens — nenhum conteúdo
 * denunciado nem PII. O moderador cai aqui em vez de direto numa fila.
 */
const props = defineProps({
    queues: { type: Object, required: true },
})

const cards = computed(() => [
    {
        key: 'reports',
        title: 'Denúncias',
        blurb: 'Fila de denúncias de conteúdo (perfil, mensagem, story, foto).',
        count: props.queues.reports ?? 0,
        href: route('moderacao.reports.index'),
    },
    {
        key: 'member_photos',
        title: 'Fotos de membro',
        blurb: 'Fotos de membro aguardando aprovação prévia.',
        count: props.queues.member_photos ?? 0,
        href: route('moderacao.member-photos.index'),
    },
    {
        key: 'voice_intros',
        title: 'Intros de voz',
        blurb: 'Apresentações de voz de performers aguardando revisão.',
        count: props.queues.voice_intros ?? 0,
        href: route('moderacao.voice-intros.index'),
    },
])

const total = computed(() => cards.value.reduce((sum, c) => sum + c.count, 0))
</script>

<template>
    <AppLayout title="Moderação">
        <div class="max-w-5xl mx-auto px-6 py-10 space-y-6">
            <div class="space-y-1">
                <h1 class="font-serif text-3xl text-cream">Moderação</h1>
                <p class="text-sm text-muted">
                    {{ total }} item{{ total === 1 ? '' : 'ns' }} aguardando revisão nas três filas.
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="card in cards"
                    :key="card.key"
                    :href="card.href"
                    class="group flex flex-col gap-3 rounded-xl border border-frame bg-surface p-5 no-underline transition-colors hover:border-gold/60"
                >
                    <div class="flex items-start justify-between gap-3">
                        <h2 class="font-serif text-xl text-cream">{{ card.title }}</h2>
                        <span
                            :class="[
                                'min-w-[28px] rounded-full px-2 py-0.5 text-center font-mono text-sm font-semibold',
                                card.count > 0 ? 'bg-gold/15 text-gold' : 'text-muted',
                            ]"
                        >{{ card.count }}</span>
                    </div>
                    <p class="text-sm text-muted">{{ card.blurb }}</p>
                    <span class="mt-auto text-sm font-medium text-gold">
                        {{ card.count > 0 ? 'Revisar' : 'Abrir fila' }}
                        <span aria-hidden="true" class="transition-transform group-hover:translate-x-0.5 inline-block">→</span>
                    </span>
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
