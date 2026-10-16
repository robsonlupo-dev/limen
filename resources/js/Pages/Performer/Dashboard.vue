<script setup>
import { computed, ref } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Button from '@/Components/Button.vue'
import ComingSoon from '@/Components/ComingSoon.vue'
import { formatTokens } from '@/lib/tokens'
import KycPendingBanner from '@/Components/KycPendingBanner.vue'
import ProfileProgress from '@/Components/ProfileProgress.vue'
import { patchJson } from '@/lib/http'

// Painel › Resumo (reorg do Painel, UAT Fase 21): "o que fazer agora".
//
// O que SAIU daqui e ganhou aba própria — Stories e Canal (uso diário), Ajustes
// (sons + destaque no catálogo), e Pessoas › Solicitações (pedidos de acesso,
// fotos recebidas, visitantes). Antes tudo vivia numa rolagem única e o uso
// diário ficava no fim. O que fica: ações rápidas, pendências com número, os
// indicadores, as últimas gorjetas e atalhos.
//
// Transição: o controller ainda devolve as props das seções que saíram (os
// testes de contrato apontam para cá); esta tela simplesmente não as declara.
const props = defineProps({
    wallet: { type: Number, required: true },
    totalEarned: { type: Number, required: true },
    tips: { type: Array, required: true },
    // Encomendas sob medida aguardando ação dela (novos + a entregar). Aviso no topo.
    customOrdersPending: { type: Number, default: 0 },
    // Faixa ("Menos de 5", "10+"…), não Number: o contador exato de um perfil
    // pequeno identifica quem seguiu e quando.
    followers: { type: String, required: true },
    kycStatus: { type: String, required: true },
    isLive: { type: Boolean, required: true },
    // Visibilidade no catálogo: a presença "online" deriva da sessão; o painel
    // controla só o OPT-OUT. `isVisible` = ! appear_offline.
    isVisible: { type: Boolean, default: true },
    // Campos do perfil para a barra de completude (Sprint 10). A % é derivada no
    // componente; ele vira selo discreto ao chegar a 100%.
    profileProgress: { type: Object, required: true },
    // Checklist de crescimento (roadmap Fatal Model, Onda A): {items, done, total}.
    // Só-leitura, derivado no servidor; gamifica os sinais do ranking.
    growthChecklist: { type: Object, default: () => ({ items: [], done: 0, total: 0 }) },
    // A performer PENDENTE alcança o painel, mas publicar exige performer-active.
    // A tela não é o guard — só evita oferecer um botão que responderia 403.
    canPublishStories: { type: Boolean, default: false },
})

const page = usePage()
const liveEnabled = computed(() => !!page.props.features?.live_enabled)
const callEnabled = computed(() => !!page.props.features?.call_enabled)

// Pendências (NavBadgeService): o mesmo número que acende o badge de "Painel" na
// nav, aberto aqui em cards que levam para o lugar da ação. Só os que têm algo.
const navCounts = computed(() => page.props.nav_counts ?? {})
const pending = computed(() => {
    const n = navCounts.value
    const items = []
    if (Number(n.access_requests ?? 0) > 0) {
        items.push({
            key: 'access',
            count: Number(n.access_requests),
            title: Number(n.access_requests) === 1 ? 'Solicitação de acesso' : 'Solicitações de acesso',
            hint: 'Fã quer ver suas fotos privadas',
            route: 'performer.people.requests',
            cta: 'Responder',
        })
    }
    if (Number(n.received_photos ?? 0) > 0) {
        items.push({
            key: 'photos',
            count: Number(n.received_photos),
            title: Number(n.received_photos) === 1 ? 'Foto recebida' : 'Fotos recebidas',
            hint: 'Ainda não vista',
            route: 'performer.people.requests',
            cta: 'Ver',
        })
    }
    if (callEnabled.value && Number(n.reservations ?? 0) > 0) {
        items.push({
            key: 'reservations',
            count: Number(n.reservations),
            title: Number(n.reservations) === 1 ? 'Agendamento para confirmar' : 'Agendamentos para confirmar',
            hint: 'A chamada só acontece se você confirmar',
            route: 'performer.reservations.index',
            cta: 'Confirmar',
        })
    }
    return items
})

const kycBadge = computed(() => {
    return {
        pending: { label: 'Pendente', class: 'bg-gold/10 text-gold border-gold/30' },
        active: { label: 'Verificado', class: 'bg-success/10 text-success border-success/30' },
        rejected: { label: 'Rejeitado', class: 'bg-danger/10 text-danger border-danger/30' },
    }[props.kycStatus] ?? { label: props.kycStatus, class: 'bg-muted/10 text-muted border-frame' }
})

const canGoLive = computed(() => props.kycStatus === 'active')

const toastMessage = ref('')

// Visibilidade no catálogo (fix/panel-polish-v1): toggle do OPT-OUT "aparecer
// offline". Estado local otimista; o servidor devolve o booleano `visible`.
const visible = ref(props.isVisible)
const togglingVisibility = ref(false)

async function toggleVisibility() {
    if (togglingVisibility.value) return
    togglingVisibility.value = true
    try {
        const data = await patchJson(route('performer.availability.toggle'), { visible: !visible.value })
        visible.value = data.visible
    } catch (error) {
        toastMessage.value =
            error.status === 429
                ? 'Muitas trocas em pouco tempo. Aguarde um instante.'
                : 'Não foi possível atualizar. Tente novamente.'
        setTimeout(() => (toastMessage.value = ''), 4000)
    } finally {
        togglingVisibility.value = false
    }
}
</script>

<template>
    <AppLayout title="Painel do performer">
        <!-- Sprint 7: enquanto o KYC não está aprovado o perfil não existe no
             catálogo — o banner fica até o status virar aprovado, sem fechar. -->
        <KycPendingBanner :kyc-status="kycStatus" />

        <div class="max-w-6xl mx-auto px-6 py-10 space-y-8">
            <!-- Aviso de encomendas sob medida aguardando ação (Onda 4 §4.3). -->
            <Link
                v-if="customOrdersPending > 0"
                :href="route('performer.custom-orders.index')"
                class="flex items-center justify-between gap-4 rounded-2xl border border-limen-gold/40 bg-limen-gold/10 px-5 py-4 no-underline transition-colors hover:bg-limen-gold/15"
            >
                <span class="text-sm text-limen-ink">
                    Você tem <strong>{{ customOrdersPending }}</strong>
                    {{ customOrdersPending === 1 ? 'encomenda aguardando' : 'encomendas aguardando' }} sua resposta.
                </span>
                <span class="shrink-0 text-sm font-medium text-limen-gold">Abrir →</span>
            </Link>

            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="space-y-1">
                    <h1 class="font-serif text-4xl text-cream">Painel</h1>
                    <p class="text-muted text-sm">O que fazer agora — e como você está.</p>
                </div>
                <!-- Live (Sprint 15): flag off → "Em breve"; ligada → o estúdio. -->
                <div class="flex flex-wrap items-center gap-3">
                    <ComingSoon v-if="!liveEnabled" icon="camera" label="Ir ao vivo" hint="Live estará disponível em breve" />
                    <Link
                        v-else-if="canGoLive"
                        :href="route('performer.live')"
                        class="inline-flex items-center justify-center rounded-lg bg-gold px-6 py-3 text-sm font-medium tracking-wide text-background transition-all duration-200 hover:bg-gold-light no-underline"
                    >
                        Ir ao vivo
                    </Link>
                    <Button v-else variant="primary" :disabled="true" title="Disponível somente após verificação KYC aprovada">
                        Ir ao vivo
                    </Button>
                    <ComingSoon v-if="!callEnabled" icon="phone" label="Definir preço de chamada" />
                </div>
            </div>

            <!-- Presença no catálogo: toggle do OPT-OUT. Só performer verificada. -->
            <div
                v-if="canGoLive"
                class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 rounded-xl border p-5"
                :class="visible ? 'border-success/40 bg-success/5' : 'border-frame bg-surface'"
            >
                <div class="space-y-1">
                    <p class="text-sm text-cream flex items-center gap-2">
                        <span aria-hidden="true" class="inline-block h-2.5 w-2.5 rounded-full" :class="visible ? 'bg-success' : 'bg-muted'" />
                        {{ visible ? 'Aparecendo como online' : 'Invisível no catálogo' }}
                    </p>
                    <p v-if="visible" class="text-xs text-muted">Você aparece como online para quem está procurando agora.</p>
                    <p v-else class="text-xs text-muted">Você está invisível no catálogo. Continua recebendo mensagens normalmente.</p>
                </div>
                <button
                    type="button"
                    role="switch"
                    :aria-checked="visible"
                    aria-label="Aparecer como online no catálogo"
                    :disabled="togglingVisibility"
                    class="relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition-colors disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-success/60"
                    :class="visible ? 'bg-success' : 'bg-frame'"
                    @click="toggleVisibility"
                >
                    <span class="inline-block h-5 w-5 transform rounded-full bg-background transition-transform" :class="visible ? 'translate-x-6' : 'translate-x-1'" />
                </button>
            </div>
            <p v-if="toastMessage" class="text-xs text-success">{{ toastMessage }}</p>

            <!-- Ações rápidas: os gestos diários, sempre no topo. Só performer
                 ativa — as abas de Stories/Canal exigem performer-active. -->
            <section v-if="canPublishStories" aria-labelledby="acoes" class="space-y-3">
                <h2 id="acoes" class="font-serif text-xl text-cream">Ações rápidas</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <Link
                        :href="route('performer.panel.stories')"
                        class="flex items-center gap-4 rounded-xl border border-gold/40 bg-surface p-5 no-underline hover:border-gold transition-colors"
                    >
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-gold text-background">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z" /><circle cx="12" cy="13" r="4" /></svg>
                        </span>
                        <span>
                            <span class="block text-sm font-medium text-cream">Publicar story</span>
                            <span class="block text-xs text-muted mt-0.5">Foto, câmera, enquete</span>
                        </span>
                    </Link>
                    <Link
                        :href="route('performer.panel.channel')"
                        class="flex items-center gap-4 rounded-xl border border-frame bg-surface p-5 no-underline hover:border-gold/40 transition-colors"
                    >
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-surface-2 text-gold">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11l18-6v14L3 13z" /><path d="M7 13v5a2 2 0 0 0 2 2h1" /></svg>
                        </span>
                        <span>
                            <span class="block text-sm font-medium text-cream">Transmitir</span>
                            <span class="block text-xs text-muted mt-0.5">Uma mensagem a quem te segue</span>
                        </span>
                    </Link>
                </div>
            </section>

            <!-- Precisa de você: só aparece quando há algo pendente. É daqui que
                 sai o número do badge de "Painel" na nav. -->
            <section v-if="pending.length" aria-labelledby="pend" class="space-y-3">
                <div class="flex items-baseline justify-between gap-4">
                    <h2 id="pend" class="font-serif text-xl text-cream">Precisa de você</h2>
                    <span class="text-xs text-muted">some quando não há pendência</span>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <Link
                        v-for="item in pending"
                        :key="item.key"
                        :href="route(item.route)"
                        class="flex items-center gap-4 rounded-xl border border-gold/40 bg-surface p-5 no-underline hover:border-gold transition-colors"
                    >
                        <span class="flex h-10 min-w-[2.5rem] shrink-0 items-center justify-center rounded-full bg-gold px-2 font-semibold text-background">
                            {{ item.count }}
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium text-cream">{{ item.title }}</span>
                            <span class="block text-xs text-muted mt-0.5">{{ item.hint }}</span>
                        </span>
                        <span class="shrink-0 text-sm text-gold">{{ item.cta }} →</span>
                    </Link>
                </div>
            </section>

            <!-- Completude do perfil (Sprint 10): vira selo discreto a 100%. -->
            <ProfileProgress :profile="profileProgress" />

            <!-- Checklist de crescimento (roadmap Fatal Model, Onda A): gamifica os
                 sinais que o ranking usa. Some quando tudo está feito. -->
            <section
                v-if="growthChecklist.total && growthChecklist.done < growthChecklist.total"
                aria-labelledby="crescimento"
                class="rounded-xl border border-frame bg-surface p-5 space-y-4"
            >
                <div class="flex items-baseline justify-between gap-3">
                    <h2 id="crescimento" class="font-serif text-xl text-cream">Faça seu perfil crescer</h2>
                    <span class="text-xs text-muted tabular-nums shrink-0">{{ growthChecklist.done }}/{{ growthChecklist.total }}</span>
                </div>
                <ul class="space-y-2.5">
                    <li
                        v-for="item in growthChecklist.items"
                        :key="item.key"
                        class="flex items-start gap-3"
                    >
                        <span
                            class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border"
                            :class="item.done ? 'border-gold bg-gold/15 text-gold' : 'border-frame text-muted'"
                            aria-hidden="true"
                        >
                            <svg v-if="item.done" class="h-3 w-3" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M5 10.5l3 3 7-7.5" />
                            </svg>
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm" :class="item.done ? 'text-muted line-through' : 'text-cream'">{{ item.label }}</p>
                            <p v-if="!item.done" class="text-xs text-muted">{{ item.hint }}</p>
                        </div>
                    </li>
                </ul>
            </section>

            <!-- Indicadores -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                <Link
                    :href="route('performer.payouts.index')"
                    class="rounded-xl border border-frame bg-surface p-5 space-y-1 block no-underline hover:border-gold/40 transition-colors"
                >
                    <p class="text-xs text-muted uppercase tracking-wide">Saldo</p>
                    <p class="font-serif text-3xl text-gold">{{ formatTokens(wallet) }}</p>
                    <p class="text-xs text-gold/70">Sacar &rarr;</p>
                </Link>

                <div class="rounded-xl border border-frame bg-surface p-5 space-y-1">
                    <p class="text-xs text-muted uppercase tracking-wide">Total ganho</p>
                    <p class="font-serif text-3xl text-gold">{{ formatTokens(totalEarned) }}</p>
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
                    <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-medium" :class="kycBadge.class">
                        {{ kycBadge.label }}
                    </span>
                </div>
            </div>

            <!-- Últimas gorjetas -->
            <div class="space-y-3">
                <h2 class="font-serif text-xl text-cream">Últimas gorjetas</h2>

                <div v-if="tips.length === 0" class="rounded-xl border border-frame bg-surface p-8 text-center text-muted text-sm">
                    Seus primeiros apoiadores estão a um post de distância.
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
                            <tr v-for="(tip, i) in tips" :key="i" class="border-b border-frame/50 last:border-b-0">
                                <!-- Apelido em destaque, FanAlias ao lado; depois o Estilo
                                     de Vida se declarado (sem faixa não sai nada). -->
                                <td class="px-5 py-3 text-cream">
                                    <template v-if="tip.nickname">
                                        {{ tip.nickname }}<span class="text-muted"> · {{ tip.fan }}</span>
                                    </template>
                                    <template v-else>{{ tip.fan }}</template><span v-if="tip.lifestyle" class="text-muted"> · {{ tip.lifestyle }}</span>
                                </td>
                                <td class="px-5 py-3 text-gold">{{ tip.amount }}</td>
                                <td class="px-5 py-3 text-muted">{{ tip.created_at }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Atalhos de conteúdo: a casa deles é a seção Conteúdo da nav. -->
            <div v-if="canPublishStories" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <Link
                    :href="route('performer.content')"
                    class="flex items-center justify-between rounded-xl border border-frame bg-surface p-5 no-underline hover:border-gold/40 transition-colors"
                >
                    <div class="space-y-1">
                        <p class="font-serif text-lg text-cream">Conteúdo permanente</p>
                        <p class="text-sm text-muted">Fotos pagas: Aberto, Premium, Exclusivo ou FC Only.</p>
                    </div>
                    <span class="shrink-0 text-sm text-gold">Gerenciar &rarr;</span>
                </Link>
                <Link
                    :href="route('performer.voice-intro.edit')"
                    class="flex items-center justify-between rounded-xl border border-frame bg-surface p-5 no-underline hover:border-gold/40 transition-colors"
                >
                    <div class="space-y-1">
                        <p class="font-serif text-lg text-cream">Apresentação de voz</p>
                        <p class="text-sm text-muted">Mensagem curta (até 20s) para quem visita seu perfil.</p>
                    </div>
                    <span class="shrink-0 text-sm text-gold">Gerenciar &rarr;</span>
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
