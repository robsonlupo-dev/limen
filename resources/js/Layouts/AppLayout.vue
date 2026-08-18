<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import PortalLogo from '@/Components/PortalLogo.vue'
import Modal from '@/Components/Modal.vue'
import Button from '@/Components/Button.vue'
import PanicButton from '@/Components/PanicButton.vue'
import PerformerNav from '@/Components/Performer/PerformerNav.vue'
import PerformerSubnav from '@/Components/Performer/PerformerSubnav.vue'
import MemberNav from '@/Components/Member/MemberNav.vue'
import MemberSubnav from '@/Components/Member/MemberSubnav.vue'
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
            <div class="max-w-6xl mx-auto px-6 py-4 flex items-center justify-between gap-4">
                <Link :href="homeRoute" class="flex items-center gap-3 no-underline shrink-0">
                    <PortalLogo :size="32" :show-text="false" />
                    <span class="font-serif text-[#C9A24B] tracking-widest">Limen</span>
                </Link>

                <!-- Painel da performer ATIVA: navegação reagrupada em 5 seções
                     + menu do avatar, mobile primeiro. Ver PerformerNav. As 9 telas
                     de antes viram Painel · Conteúdo · Mensagens · Pessoas · Ganhos,
                     com Perfil/Interesses/Agendamentos/Segurança/Sair no avatar. -->
                <PerformerNav
                    v-if="isActivePerformer"
                    :nav-counts="navCounts"
                    :features="features"
                    :user-name="page.props.auth.user?.name"
                    @logout="showLogoutConfirm = true"
                />

                <!-- Painel do MEMBRO: navegação reagrupada em 5 seções + menu do
                     avatar, mobile primeiro. Ver MemberNav. As ~11 telas de antes
                     viram Início · Descobrir · Conexões · Mensagens · Carteira,
                     com Meu Perfil/Configurações/Sair no menu do avatar. -->
                <MemberNav
                    v-else-if="isConsumer"
                    :nav-counts="navCounts"
                    :features="features"
                    :user-name="page.props.auth.user?.name"
                    @logout="showLogoutConfirm = true"
                />

                <!-- Demais papéis (admin/moderador e performer ainda em
                     onboarding): navegação inline, como antes. -->
                <nav v-else class="flex items-center gap-6 text-sm text-muted">
                    <!-- Performer em onboarding: painel (→ onboarding) + Segurança,
                         a tela em que o 2FA mais importa durante o KYC. -->
                    <Link
                        v-if="isPerformer"
                        :href="dashboardRoute"
                        class="text-gold/80 hover:text-gold transition-colors no-underline"
                    >
                        Meu Painel
                    </Link>
                    <Link
                        v-if="isPerformer"
                        :href="route('performer.2fa.show')"
                        class="text-gold/80 hover:text-gold transition-colors no-underline"
                    >
                        Segurança
                    </Link>
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
                    <span class="text-cream">{{ page.props.auth.user?.name }}</span>
                    <button
                        class="hover:text-cream transition-colors"
                        @click="showLogoutConfirm = true"
                    >
                        Sair
                    </button>
                </nav>
            </div>
        </header>

        <!-- Subnav da seção ativa (Pessoas: Seguidores · Membros / Ganhos: Saques ·
             Histórico). Some nas seções sem filhos e fora do painel. -->
        <PerformerSubnav v-if="isActivePerformer" />

        <!-- Subnav do membro (Descobrir/Conexões/Mensagens/Carteira). Some na
             seção Início e fora do painel. -->
        <MemberSubnav v-else-if="isConsumer" :nav-counts="navCounts" :features="features" />

        <!-- Flash messages -->
        <div v-if="page.props.flash?.success" class="bg-success/10 border-b border-success/30 px-6 py-3 text-sm text-success text-center">
            {{ page.props.flash.success }}
        </div>
        <div v-if="page.props.flash?.error" class="bg-danger/10 border-b border-danger/30 px-6 py-3 text-sm text-danger text-center">
            {{ page.props.flash.error }}
        </div>

        <!-- Content. No painel da performer, o rodapé fixo (barra de navegação
             mobile) exige folga inferior no celular para não cobrir o conteúdo. -->
        <main class="mi-page-enter flex-1" :class="(isActivePerformer || isConsumer) ? 'pb-[calc(6rem_+_env(safe-area-inset-bottom))] md:pb-0' : ''">
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

        <!-- Saída rápida GLOBAL — ícone flutuante (teleportado) no canto
             SUPERIOR-esquerdo, DESKTOP-ONLY, presente em toda tela autenticada
             (membro E performer). No celular ele não aparece (a saída nativa —
             bloquear o aparelho — é mais rápida); o duplo-Escape segue ativo em
             toda largura. Longe do menu do avatar/"Sair" (topo direito). -->
        <PanicButton />

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
