<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ArchHeading from '@/Components/Member/ArchHeading.vue'
import LiveBadge from '@/Components/LiveBadge.vue'
import EphemeralPhotoPanel from '@/Components/EphemeralPhotoPanel.vue'

const props = defineProps({
    balance: { type: Number, required: true },
    following: { type: Array, required: true },
    followingCount: { type: Number, required: true },
    interests: { type: Object, required: true },
    tips: { type: Array, required: true },
    tipsSummary: { type: Object, required: true },
    photos: { type: Array, default: () => [] },
    photoLimit: { type: Number, default: 5 },
    photoTtlOptions: { type: Array, default: () => [] },
})

const page = usePage()

// Saudação pelo relógio do próprio membro — o clube é noturno, e a saudação
// nasce do mundo do produto, não de uma caixinha. Só cosmético (fuso local).
const firstName = computed(() => (page.props.auth?.user?.name ?? '').trim().split(/\s+/)[0] || '')
const greeting = computed(() => {
    const h = new Date().getHours()
    if (h < 6) return 'Boa madrugada'
    if (h < 12) return 'Bom dia'
    if (h < 18) return 'Boa tarde'
    return 'Boa noite'
})
const headingTitle = computed(() => (firstName.value ? `${greeting.value}, ${firstName.value}` : greeting.value))

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
        <div class="mx-auto max-w-5xl space-y-14 px-6 py-12">
            <ArchHeading
                as="h1"
                eyebrow="Seu clube"
                :title="headingTitle"
                subtitle="O que aconteceu enquanto você esteve fora."
            />

            <!-- Carteira em PROSA, não em caixinha de estatística: o saldo é uma
                 frase, e o resto da atividade vem em linha derivada. O arco à
                 esquerda é a assinatura do portal. -->
            <section class="relative overflow-hidden rounded-2xl border border-gold/15 bg-surface/60 px-7 py-8 sm:px-10">
                <div class="pointer-events-none absolute -left-16 top-1/2 h-56 w-56 -translate-y-1/2 rounded-full border border-gold/10" aria-hidden="true" />
                <div class="relative flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between">
                    <div class="space-y-2">
                        <p class="text-[11px] font-medium uppercase tracking-[0.28em] text-gold/80">Sua carteira</p>
                        <p class="font-serif text-cream">
                            <span class="text-5xl text-gold">{{ balance }}</span>
                            <span class="ml-2 text-xl text-muted">tokens</span>
                        </p>
                        <p class="text-sm text-muted">
                            Você segue {{ followingCount }}
                            {{ followingCount === 1 ? 'performer' : 'performers' }}
                            <template v-if="tipsSummary.count > 0">
                                · {{ tipsSummary.tokens }} tokens em
                                {{ tipsSummary.count }}
                                {{ tipsSummary.count === 1 ? 'gorjeta' : 'gorjetas' }}
                            </template>
                        </p>
                    </div>
                    <Link
                        :href="route('wallet.index')"
                        class="shrink-0 rounded-full border border-gold/40 px-5 py-2.5 text-sm text-gold no-underline transition-colors hover:border-gold hover:bg-gold/10"
                    >
                        Comprar tokens
                    </Link>
                </div>
            </section>

            <!-- Interesse recebido: o gancho de engajamento. Acende dourado quando
                 há algo a desbloquear. -->
            <Link
                :href="route('interests.index')"
                class="flex items-center justify-between gap-4 rounded-2xl border px-7 py-6 no-underline transition-colors"
                :class="hasLockedInterests
                    ? 'border-gold/40 bg-gold/10 hover:border-gold/60'
                    : 'border-gold/15 bg-surface/60 hover:border-gold/40'"
            >
                <div class="space-y-1">
                    <p class="font-serif text-lg" :class="hasLockedInterests ? 'text-gold' : 'text-cream'">
                        {{ interestHeadline }}
                    </p>
                    <p class="text-sm text-muted">
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
                <span class="shrink-0 text-sm text-gold">Abrir &rarr;</span>
            </Link>

            <!-- Quem eu sigo — retratos em MOLDURA DE ARCO (o portal como
                 estrutura), não círculos. -->
            <section class="space-y-5">
                <div class="flex items-end justify-between gap-4">
                    <ArchHeading eyebrow="Presença" title="Quem eu sigo" />
                    <Link
                        v-if="followingCount > following.length"
                        :href="route('catalog')"
                        class="shrink-0 text-sm text-gold transition-colors hover:text-gold-light"
                    >
                        Ver todas ({{ followingCount }})
                    </Link>
                </div>

                <div
                    v-if="following.length === 0"
                    class="rounded-2xl border border-gold/15 bg-surface/60 px-6 py-12 text-center"
                >
                    <p class="font-serif text-xl text-cream">Seu círculo começa vazio</p>
                    <p class="mx-auto mt-2 max-w-md text-sm text-muted">
                        Siga performers para saber na hora em que entram ao vivo e acompanhar o que publicam.
                    </p>
                    <Link
                        :href="route('catalog')"
                        class="mt-5 inline-block rounded-full border border-gold/40 px-5 py-2.5 text-sm text-gold no-underline transition-colors hover:border-gold hover:bg-gold/10"
                    >
                        Explorar o catálogo
                    </Link>
                </div>

                <div v-else class="grid grid-cols-3 gap-4 sm:grid-cols-4 lg:grid-cols-6">
                    <Link
                        v-for="performer in following"
                        :key="performer.slug"
                        :href="route('catalog.show', performer.slug)"
                        class="group/arch block no-underline"
                    >
                        <div class="relative aspect-[3/4] overflow-hidden rounded-t-full rounded-b-lg border border-gold/20 bg-surface-2 transition-colors group-hover/arch:border-gold/50">
                            <img
                                v-if="performer.avatar_url"
                                :src="performer.avatar_url"
                                :alt="performer.stage_name"
                                class="h-full w-full object-cover"
                            />
                            <div v-else class="flex h-full w-full items-center justify-center">
                                <span class="font-serif text-3xl text-gold/70">{{ performer.stage_name?.charAt(0) }}</span>
                            </div>
                            <span v-if="performer.is_live" class="absolute bottom-2 left-1/2 -translate-x-1/2">
                                <LiveBadge />
                            </span>
                        </div>
                        <p class="mt-2 truncate text-center text-sm text-cream">{{ performer.stage_name }}</p>
                    </Link>
                </div>
            </section>

            <!-- Últimas gorjetas — livro-caixa discreto, divisores em bronze fino,
                 não tabela pesada. -->
            <section class="space-y-5">
                <div class="flex items-end justify-between gap-4">
                    <ArchHeading eyebrow="Registro" title="Últimas gorjetas" />
                    <Link
                        v-if="tips.length > 0"
                        :href="route('wallet.history')"
                        class="shrink-0 text-sm text-gold transition-colors hover:text-gold-light"
                    >
                        Ver extrato
                    </Link>
                </div>

                <div
                    v-if="tips.length === 0"
                    class="rounded-2xl border border-gold/15 bg-surface/60 px-6 py-10 text-center"
                >
                    <p class="font-serif text-lg text-cream">Nenhuma gorjeta ainda</p>
                    <p class="mx-auto mt-2 max-w-md text-sm text-muted">
                        Uma gorjeta é a forma mais direta de ser notado. Comece pelo catálogo.
                    </p>
                    <Link
                        :href="route('catalog')"
                        class="mt-4 inline-block text-sm text-gold no-underline transition-colors hover:text-gold-light"
                    >
                        Ir ao catálogo &rarr;
                    </Link>
                </div>

                <ul v-else class="rounded-2xl border border-gold/15 bg-surface/60 px-2">
                    <li
                        v-for="tip in tips"
                        :key="tip.id"
                        class="flex items-center justify-between gap-4 border-b border-gold/10 px-5 py-4 last:border-b-0"
                    >
                        <span class="truncate text-cream">{{ tip.performer ?? '—' }}</span>
                        <span class="flex shrink-0 items-baseline gap-3">
                            <span class="font-serif text-lg text-gold">{{ tip.amount }}</span>
                            <span class="text-xs text-muted">{{ tip.created_at }}</span>
                        </span>
                    </li>
                </ul>
            </section>

            <!-- Fotos efêmeras (Sprint 9B). O compartilhamento NÃO acontece aqui:
                 vive no chat, porque só se compartilha com performer com quem já
                 se conversa. -->
            <EphemeralPhotoPanel
                :photos="photos"
                :limit="photoLimit"
                :ttl-options="photoTtlOptions"
            />
        </div>
    </AppLayout>
</template>
