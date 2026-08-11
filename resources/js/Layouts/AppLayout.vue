<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import PortalLogo from '@/Components/PortalLogo.vue'
import Modal from '@/Components/Modal.vue'
import Button from '@/Components/Button.vue'
import PanicButton from '@/Components/PanicButton.vue'
import MessageToast from '@/Components/MessageToast.vue'
import ReservationNotice from '@/Components/ReservationNotice.vue'

defineProps({
    title: String,
})

const page = usePage()

const isPerformer = computed(() => page.props.auth.user?.role === 'performer')
const isConsumer = computed(() => page.props.auth.user?.role === 'consumer')
// Moderação (Sprint 13). Moderador e admin veem "Moderação"; só o admin vê
// "Admin" (o back-office /admin/* é Blade — link é <a>, não <Link> Inertia).
const isAdmin = computed(() => page.props.auth.user?.role === 'admin')
const canModerate = computed(() => isAdmin.value || page.props.auth.user?.role === 'moderator')
// Espelha o gate 'performer-active' (AppServiceProvider): performer ainda em
// onboarding não vê links que o gate recusaria com 403.
const isActivePerformer = computed(() => isPerformer.value && page.props.auth.user?.status === 'active')
// Feature flags (Sprint 15 dark launch). O agendamento vive sob feature:call, então
// os links só aparecem com a chamada ligada — como os botões de live/chamada.
const features = computed(() => page.props.features ?? {})
// Contadores de não-vistos da nav (feat/activity-badges): bolinhas ao lado de
// "Mensagens" (os dois papéis) e "Interessadas" (só membro). Vêm resolvidos do
// backend (NavBadgeService) respeitando o paywall do chat; o front só desenha.
const navCounts = computed(() => page.props.nav_counts ?? { messages: 0, hearts: 0 })
const dashboardRoute = computed(() =>
    isActivePerformer.value ? route('performer.dashboard') : route('performer.onboarding'),
)

// O logo leva cada papel ao SEU início. A HOME da performer ativa passou a ser o
// CATÁLOGO DE MEMBROS (a vitrine onde ela navega e engaja), simétrico ao catálogo
// que o membro vê ao logar — o painel segue acessível por "Meu Painel". Performer
// ainda em onboarding cai no onboarding (o catálogo de membros é gateado por
// performer-active). Os demais (membro, admin/moderador) seguem para o catálogo.
const homeRoute = computed(() => {
    if (!isPerformer.value) {
        return route('catalog')
    }

    return isActivePerformer.value ? route('performer.members') : route('performer.onboarding')
})

const showLogoutConfirm = ref(false)

function logout() {
    showLogoutConfirm.value = false
    router.post(route('logout'))
}
</script>

<template>
    <Head :title="title" />
    <div class="min-h-screen bg-background flex flex-col">
        <!-- Header -->
        <header class="border-b border-frame/50">
            <!-- pr-16 reserva o canto superior direito para o DISCO da Saída rápida
                 (PanicButton teleporta um disco fixed top-4 right-4 além do link
                 inline abaixo). Sem a folga, em tela estreita o disco cobre nome/Sair
                 e o link — achado do UAT cenário 63. -->
            <div class="max-w-6xl mx-auto pl-6 pr-16 py-4 flex items-center justify-between">
                <Link :href="homeRoute" class="flex items-center gap-3 no-underline">
                    <PortalLogo :size="32" :show-text="false" />
                    <span class="font-serif text-[#C9A24B] tracking-widest">Limen</span>
                </Link>
                <nav class="flex items-center gap-6 text-sm text-muted">
                    <Link
                        v-if="isPerformer"
                        :href="dashboardRoute"
                        class="text-gold/80 hover:text-gold transition-colors no-underline"
                    >
                        Meu Painel
                    </Link>
                    <template v-if="isActivePerformer">
                        <Link
                            :href="route('performer.profile.edit')"
                            class="text-gold/80 hover:text-gold transition-colors no-underline"
                        >
                            Perfil
                        </Link>
                        <Link
                            :href="route('performer.followers')"
                            class="text-gold/80 hover:text-gold transition-colors no-underline"
                        >
                            Seguidores
                        </Link>
                        <!-- "Membros" saiu da nav: o catálogo de membros virou a
                             HOME da performer (volta pelo logo). Ver homeRoute. -->
                        <Link
                            :href="route('performer.interests.index')"
                            class="text-gold/80 hover:text-gold transition-colors no-underline"
                        >
                            Interesses
                        </Link>
                        <Link
                            :href="route('performer.content')"
                            class="text-gold/80 hover:text-gold transition-colors no-underline"
                        >
                            Conteúdo
                        </Link>
                        <Link
                            :href="route('chat.index')"
                            class="text-gold/80 hover:text-gold transition-colors no-underline"
                        >
                            Mensagens
                            <span
                                v-if="navCounts.messages > 0"
                                class="ml-1 inline-flex min-w-[18px] items-center justify-center rounded-full bg-limen-gold px-1.5 py-0.5 text-[10px] font-semibold leading-none text-limen-bg"
                                aria-label="mensagens não lidas"
                            >{{ navCounts.messages > 99 ? '99+' : navCounts.messages }}</span>
                        </Link>
                        <Link
                            :href="route('performer.payouts.index')"
                            class="text-gold/80 hover:text-gold transition-colors no-underline"
                        >
                            Saques
                        </Link>
                        <!-- Fila de chamadas agendadas (feat/scheduled-call-v1),
                             sob feature:call como o resto da chamada. -->
                        <Link
                            v-if="features.call_enabled"
                            :href="route('performer.reservations.index')"
                            class="text-gold/80 hover:text-gold transition-colors no-underline"
                        >
                            Agendamentos
                        </Link>
                    </template>
                    <!-- isPerformer e não isActivePerformer: a performer em KYC
                         já tem documento e selfie na conta — é a janela em que
                         o 2FA mais importa e a única em que ela ainda não teria
                         acesso à tela se o link seguisse os outros. -->
                    <Link
                        v-if="isPerformer"
                        :href="route('performer.2fa.show')"
                        class="text-gold/80 hover:text-gold transition-colors no-underline"
                    >
                        Segurança
                    </Link>
                    <template v-if="isConsumer">
                        <Link
                            :href="route('consumer.dashboard')"
                            class="text-gold/80 hover:text-gold transition-colors no-underline"
                        >
                            Meu Painel
                        </Link>
                        <!-- Feed de conteúdo permanente de quem o membro segue. -->
                        <Link
                            :href="route('feed')"
                            class="text-gold/80 hover:text-gold transition-colors no-underline"
                        >
                            Feed
                        </Link>
                        <!-- "Meu Perfil" e "Interesses" são coisas diferentes e
                             ficam lado a lado de propósito: Interesses é a caixa
                             do Interesse Controlado (o que performers mandaram),
                             Meu Perfil é a auto-declaração do membro. -->
                        <Link
                            :href="route('consumer.profile.edit')"
                            class="text-gold/80 hover:text-gold transition-colors no-underline"
                        >
                            Meu Perfil
                        </Link>
                        <Link
                            :href="route('interests.index')"
                            class="text-gold/80 hover:text-gold transition-colors no-underline"
                        >
                            Interesses
                        </Link>
                        <!-- "Performers interessadas em você" (corações recebidos):
                             grátis e com a identidade da performer visível — o
                             oposto do Interesse pago/anônimo acima. -->
                        <Link
                            :href="route('consumer.hearts.index')"
                            class="text-gold/80 hover:text-gold transition-colors no-underline"
                        >
                            Interessadas
                            <span
                                v-if="navCounts.hearts > 0"
                                class="ml-1 inline-flex min-w-[18px] items-center justify-center rounded-full bg-limen-gold px-1.5 py-0.5 text-[10px] font-semibold leading-none text-limen-bg"
                                aria-label="novos corações"
                            >{{ navCounts.hearts > 99 ? '99+' : navCounts.hearts }}</span>
                        </Link>
                        <!-- "Quem visitou seu perfil" (visitas bidirecionais): as
                             performers que passaram pelo perfil do membro. A
                             performer é pública, então aparece com a identidade
                             real — nenhum membro é exposto aqui. -->
                        <Link
                            :href="route('consumer.visitors.index')"
                            class="text-gold/80 hover:text-gold transition-colors no-underline"
                        >
                            Quem me visitou
                        </Link>
                        <!-- Bookmark privado — a performer não é avisada. Fica
                             na nav do MEMBRO e não tem espelho na da performer. -->
                        <Link
                            :href="route('favorites.index')"
                            class="text-gold/80 hover:text-gold transition-colors no-underline"
                        >
                            Salvos
                        </Link>
                        <Link
                            :href="route('chat.index')"
                            class="text-gold/80 hover:text-gold transition-colors no-underline"
                        >
                            Mensagens
                            <span
                                v-if="navCounts.messages > 0"
                                class="ml-1 inline-flex min-w-[18px] items-center justify-center rounded-full bg-limen-gold px-1.5 py-0.5 text-[10px] font-semibold leading-none text-limen-bg"
                                aria-label="mensagens não lidas"
                            >{{ navCounts.messages > 99 ? '99+' : navCounts.messages }}</span>
                        </Link>
                        <Link
                            :href="route('wallet.index')"
                            class="text-gold/80 hover:text-gold transition-colors no-underline"
                        >
                            Carteira
                        </Link>
                        <!-- Minhas chamadas agendadas (feat/scheduled-call-v1),
                             sob feature:call. -->
                        <Link
                            v-if="features.call_enabled"
                            :href="route('reservations.index')"
                            class="text-gold/80 hover:text-gold transition-colors no-underline"
                        >
                            Chamadas
                        </Link>
                        <Link
                            :href="route('subscribe.index')"
                            class="text-gold/80 hover:text-gold transition-colors no-underline"
                        >
                            Círculos
                        </Link>
                    </template>
                    <!-- Moderação: moderador E admin. Link Inertia — a fila é
                         uma tela Vue (/moderacao/*). -->
                    <Link
                        v-if="canModerate"
                        :href="route('moderacao.reports.index')"
                        class="text-gold/80 hover:text-gold transition-colors no-underline"
                    >
                        Moderação
                    </Link>
                    <!-- Fila de moderação das intros de voz (feat/voice-intro).
                         Link Inertia — tela Vue sob /moderacao/*. -->
                    <Link
                        v-if="canModerate"
                        :href="route('moderacao.voice-intros.index')"
                        class="text-gold/80 hover:text-gold transition-colors no-underline"
                    >
                        Áudios
                    </Link>
                    <!-- Admin: só admin. <a> e não <Link> — o back-office /admin/*
                         é Blade puro, fora do Inertia. -->
                    <a
                        v-if="isAdmin"
                        href="/admin/dashboard"
                        class="text-gold/80 hover:text-gold transition-colors no-underline"
                    >
                        Admin
                    </a>
                    <!-- Nome em cima, Saída rápida ABAIXO (pedido do PO, ago/2026):
                         empilhados numa coluna para o Panic Button ser localizável
                         rápido, não mais um controle discreto ao lado do nome. O
                         duplo-Escape e o disco flutuante moram no mesmo componente e
                         valem mesmo se esta barra sair da tela. -->
                    <div class="flex flex-col items-end gap-1">
                        <span class="text-cream">{{ page.props.auth.user?.name }}</span>
                        <PanicButton />
                    </div>
                    <button
                        class="hover:text-cream transition-colors"
                        @click="showLogoutConfirm = true"
                    >
                        Sair
                    </button>
                </nav>
            </div>
        </header>

        <!-- Flash messages -->
        <div v-if="page.props.flash?.success" class="bg-success/10 border-b border-success/30 px-6 py-3 text-sm text-success text-center">
            {{ page.props.flash.success }}
        </div>
        <div v-if="page.props.flash?.error" class="bg-danger/10 border-b border-danger/30 px-6 py-3 text-sm text-danger text-center">
            {{ page.props.flash.error }}
        </div>

        <!-- Content -->
        <main class="mi-page-enter flex-1">
            <slot />
        </main>

        <!-- Footer -->
        <footer class="border-t border-frame/50 py-8">
            <div class="max-w-6xl mx-auto px-6 text-center text-xs text-muted space-y-1">
                <p>© {{ new Date().getFullYear() }} Limen. Todos os direitos reservados.</p>
                <p class="text-gold/70">+18 · Conteúdo adulto verificado.</p>
                <p v-if="page.props.auth.user?.role === 'consumer'" class="pt-2">
                    <a href="/cadastro?tipo=performer" class="text-gold/60 hover:text-gold transition-colors">
                        Quer ser Performer? Clique aqui
                    </a>
                </p>
            </div>
        </footer>

        <!-- Toast global de mensagem recebida (Sprint 15). Listener em qualquer
             página autenticada; escuta o canal user.{id} e nunca mostra o corpo. -->
        <MessageToast />

        <!-- Avisos não-intrusivos do agendamento de chamada (feat/scheduled-call-v1):
             T-5min, "performer entrou" com contador de 2min, no-show/refund. Divide
             o canal user.{id} com o MessageToast sem derrubá-lo. -->
        <ReservationNotice />

        <!-- Logout confirmation -->
        <Modal :show="showLogoutConfirm" max-width="sm" @close="showLogoutConfirm = false">
            <h2 class="font-serif text-xl text-cream mb-2">Sair do Portal?</h2>
            <p class="text-muted text-sm mb-6">Tem certeza que quer encerrar sua sessão?</p>
            <div class="flex gap-3 justify-end">
                <Button variant="ghost" size="sm" @click="showLogoutConfirm = false">Cancelar</Button>
                <Button variant="danger" size="sm" @click="logout">Sair</Button>
            </div>
        </Modal>
    </div>
</template>
