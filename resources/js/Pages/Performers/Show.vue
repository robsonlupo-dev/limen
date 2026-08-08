<script setup>
import { computed, ref } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import VerifiedBadge from '@/Components/VerifiedBadge.vue'
import CurationSeal from '@/Components/CurationSeal.vue'
import LiveBadge from '@/Components/LiveBadge.vue'
import TipModal from '@/Components/TipModal.vue'
import ReportModal from '@/Components/ReportModal.vue'
import StoryStrip from '@/Components/StoryStrip.vue'
import PerformerAbout from '@/Components/PerformerAbout.vue'
import PhotoCarousel from '@/Components/PhotoCarousel.vue'
import ContentGallery from '@/Components/ContentGallery.vue'
import Modal from '@/Components/Modal.vue'
import Button from '@/Components/Button.vue'
import FavoriteButton from '@/Components/FavoriteButton.vue'
import { stateLabel } from '@/lib/performerAttributes'
import { postJson } from '@/lib/http'

const props = defineProps({
    performer: { type: Object, required: true },
    // Chat é interest-gated: só vem preenchido para um membro que JÁ tem conversa
    // com esta performer (a performer mandou Interesse + o membro desbloqueou).
    // Null = guest / performer / membro sem conversa → sem botão de chat aqui.
    chat: { type: Object, default: null },
    // Stories vivos dela, com `locked` já resolvido pelo servidor para ESTE
    // espectador (visitante deslogado: todos fechados, nenhum com URL).
    stories: { type: Array, default: () => [] },
    // Galeria de fotos pública (Sprint 10): cada item { id, url }. Público, sem
    // paywall — o visitante deslogado também vê. Ver PhotoCarousel.
    photos: { type: Array, default: () => [] },
    // Alvo da denúncia ({ type, id }) ou null para visitante deslogado — a rota
    // POST /reportar exige auth.
    report: { type: Object, default: null },
    // Estado do favorito para ESTE espectador, ou null quando ele não pode
    // favoritar (visitante, performer, admin). Ver PublicCatalogController::show.
    favorite: { type: Object, default: null },
    // Conteúdo permanente pago (Sprint 14, M.4/M.13.13). Só os níveis que o tier
    // do espectador alcança chegam; visitante deslogado vê só o Aberto, bloqueado
    // e sem URL. Ver PublicCatalogController::show.
    contents: { type: Array, default: () => [] },
    meta: { type: Object, default: () => ({ title: 'Limen', description: '' }) },
})

// Página pública (GuestLayout), mas acessível também por usuário logado. Só um
// membro (role:consumer) pode gorjetar — é o que o backend exige em
// POST /gorjetas (auth + role:consumer). Performer/admin logados e visitante
// deslogado caem no mesmo caminho: link para o cadastro.
const page = usePage()
const canTip = computed(() => page.props.auth?.user?.role === 'consumer')

// Localizações (Sprint 13): lista de UFs → nomes por extenso, "São Paulo · Rio
// de Janeiro". `states` cai em [state] quando há uma só (fallback do resource).
const hasStates = computed(() => (props.performer.states?.length ?? 0) > 0)
const statesLabel = computed(() => (props.performer.states ?? []).map(stateLabel).join(' · '))

const showTipModal = ref(false)
const showReportModal = ref(false)

// Acesso ao chat (só quando há conversa aberta — ver prop `chat`).
const showChatAccessModal = ref(false)
const unlockingChat = ref(false)
const chatError = ref('')

async function unlockChat() {
    if (unlockingChat.value) return
    unlockingChat.value = true
    chatError.value = ''
    try {
        await postJson(route('chat.access.open', props.chat.conversation_id), {
            idempotency_key: crypto.randomUUID(),
        })
        // Acesso comprado → vai direto para a conversa.
        router.visit(route('chat.show', props.chat.conversation_id))
    } catch (e) {
        chatError.value = e.status === 422 && e.data?.reason === 'insufficient_balance'
            ? 'Saldo insuficiente. Compre tokens na sua carteira.'
            : (e.data?.message ?? 'Não foi possível desbloquear o chat.')
    } finally {
        unlockingChat.value = false
    }
}

const workModeLabels = {
    live: 'Show ao vivo',
    video: 'Vídeos',
    chat: 'Chat privado',
    fotos: 'Fotos',
    privado: 'Sessão privada',
    exclusivo: 'Conteúdo exclusivo',
}
</script>

<template>
    <GuestLayout :title="meta.title">
        <div class="bg-limen-bg">
            <!-- Hero / cover 1200x400 (crop interativo no upload) -->
            <div class="relative h-64 md:h-80 bg-limen-surface-2 overflow-hidden">
                <img
                    v-if="performer.cover_url"
                    :src="performer.cover_url"
                    :alt="performer.stage_name"
                    class="h-full w-full object-cover"
                />
                <div v-else class="h-full w-full bg-gradient-to-br from-limen-gold/20 via-limen-surface-2 to-limen-bg" />
                <div class="absolute inset-0 bg-gradient-to-t from-limen-bg via-limen-bg/20 to-transparent" />

                <div v-if="performer.is_live" class="absolute top-4 right-4">
                    <LiveBadge />
                </div>
            </div>

            <div class="max-w-4xl mx-auto px-6">
                <!-- Avatar circular -->
                <div class="-mt-16 flex items-end gap-5">
                    <div class="h-32 w-32 rounded-full border-4 border-limen-gold bg-limen-surface-2 overflow-hidden flex items-center justify-center shrink-0 shadow-2xl">
                        <img
                            v-if="performer.avatar_url"
                            :src="performer.avatar_url"
                            :alt="performer.stage_name"
                            class="h-full w-full object-cover"
                        />
                        <span v-else class="font-serif text-5xl text-limen-gold">{{ performer.stage_name?.charAt(0) }}</span>
                    </div>
                </div>

                <!-- Identity. Nome em Cormorant + o selo dourado de verificação (a
                     ÚNICA marca — o chip verde "Verificada", o chip "Email" e o
                     rótulo do mundo saíram no redesign maison: eram redundantes com
                     o selo e com o mundo que o membro já está navegando). -->
                <div class="mt-5 flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                    <div class="flex items-center gap-2 flex-wrap">
                        <h1 class="font-serif text-4xl text-limen-ink">{{ performer.stage_name }}</h1>
                        <VerifiedBadge v-if="performer.is_verified" :category="performer.category" />
                        <CurationSeal :tier="performer.tier" />
                    </div>

                    <div class="flex items-center gap-3 flex-wrap">
                        <!-- Chat: só aparece para membro que já desbloqueou o Interesse
                             desta performer (prop `chat`). Com acesso em dia → link;
                             sem acesso → modal de compra. Não há chat iniciado a frio. -->
                        <template v-if="chat">
                            <Link
                                v-if="chat.can_access"
                                :href="route('chat.show', chat.conversation_id)"
                                class="no-underline bg-limen-gold text-limen-bg px-5 py-2 rounded-lg text-sm hover:opacity-90 transition-opacity"
                            >
                                Enviar mensagem
                            </Link>
                            <button
                                v-else
                                type="button"
                                class="bg-limen-gold text-limen-bg px-5 py-2 rounded-lg text-sm hover:opacity-90 transition-opacity"
                                @click="showChatAccessModal = true"
                            >
                                Enviar mensagem
                            </button>
                        </template>
                        <!-- Seguir ainda exige conta: leva ao cadastro. -->
                        <Link
                            :href="route('entrada')"
                            class="no-underline border border-limen-gold text-limen-gold px-5 py-2 rounded-lg text-sm hover:bg-limen-gold/10 transition-colors"
                        >
                            Seguir
                        </Link>
                        <!-- Gorjeta: só membro (role:consumer) abre o modal;
                             performer/admin/visitante vão ao cadastro. -->
                        <button
                            v-if="canTip"
                            type="button"
                            class="border border-limen-line text-limen-ink-mute px-5 py-2 rounded-lg text-sm hover:text-limen-ink hover:border-limen-gold/40 transition-colors"
                            @click="showTipModal = true"
                        >
                            Enviar gorjeta
                        </button>
                        <Link
                            v-else
                            :href="route('entrada')"
                            class="no-underline border border-limen-line text-limen-ink-mute px-5 py-2 rounded-lg text-sm hover:text-limen-ink hover:border-limen-gold/40 transition-colors"
                        >
                            Enviar gorjeta
                        </Link>
                        <!-- Salvar (Sprint 10): bookmark PRIVADO. A prop chega
                             null para visitante deslogado, performer e admin —
                             só `role:consumer` alcança a rota, e um botão que
                             levaria a 403 não é oferecido. Sem link de cadastro
                             no lugar, ao contrário de Seguir/Gorjeta: "salvar
                             para ver depois" não é chamariz de conversão, e o
                             visitante já tem dois botões que levam à entrada. -->
                        <FavoriteButton
                            v-if="favorite"
                            :slug="performer.slug"
                            :saved="favorite.saved"
                            :reload-only="['favorite']"
                            variant="button"
                        />
                    </div>
                </div>

                <!-- "Disponível para conversa" (Sprint 11) — com destaque no
                     perfil, ao contrário do card. Some quando is_live: o LiveBadge
                     do topo já sinaliza presença agora. O CTA "Iniciar conversa"
                     só aparece para quem tem conversa aberta (chat interest-gated
                     — não há chat frio): com acesso em dia vira link, sem acesso
                     abre o modal de compra. Visitante/membro sem conversa vê só o
                     sinal. -->
                <div
                    v-if="performer.is_available && !performer.is_live"
                    class="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-limen-gold/40 bg-limen-gold/5 px-4 py-3"
                >
                    <p class="text-sm text-limen-gold flex items-center gap-2">
                        <span aria-hidden="true">💬</span> Disponível para conversa
                    </p>
                    <template v-if="chat">
                        <Link
                            v-if="chat.can_access"
                            :href="route('chat.show', chat.conversation_id)"
                            class="no-underline bg-limen-gold text-limen-bg px-4 py-1.5 rounded-lg text-sm hover:opacity-90 transition-opacity"
                        >
                            Iniciar conversa
                        </Link>
                        <button
                            v-else
                            type="button"
                            class="bg-limen-gold text-limen-bg px-4 py-1.5 rounded-lg text-sm hover:opacity-90 transition-opacity"
                            @click="showChatAccessModal = true"
                        >
                            Iniciar conversa
                        </button>
                    </template>
                </div>

                <!-- Estado por extenso. Some por inteiro para quem não preencheu
                     — o campo é opt-in, então "não informado" não é uma lacuna a
                     anunciar. A cidade NÃO chega nesta prop, por construção:
                     PerformerPublicResource não a expõe.

                     Some TAMBÉM enquanto ela está ao vivo, que é a mesma regra
                     do card: esta página carrega o selo "ao vivo" no topo, então
                     sem o `!performer.is_live` a correlação "está transmitindo
                     AGORA + está em SP" continuava de pé aqui depois de ter sido
                     fechada no catálogo — e é a página que um link direto abre. -->
                <div
                    v-if="!performer.is_live && !performer.is_available && (hasStates || performer.activity_label)"
                    class="mt-6 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-limen-ink-mute"
                >
                    <!-- Múltiplas localizações (Sprint 13): estados por extenso,
                         separados por " · " ("São Paulo · Rio de Janeiro"). Só a
                         UF vira nome — `city` não chega a esta prop. `states` cai
                         em [state] quando há uma só. -->
                    <span v-if="hasStates">
                        {{ performer.states.length > 1 ? 'Estados' : 'Estado' }}:
                        <span class="text-limen-ink">{{ statesLabel }}</span>
                    </span>
                    <span v-if="hasStates && performer.activity_label" aria-hidden="true" class="text-limen-ink-mute/50">·</span>
                    <!-- Última atividade em faixa, ao lado do estado (Sprint 10).
                         Faixa, nunca relógio (ActivitySlot). Some quando is_live
                         — o LiveBadge do topo já diz "agora" — e quando null.
                         Some TAMBÉM quando is_available: presença (querer conversar
                         agora) + localização é a correlação do R2, a mesma razão
                         de o estado ceder ao "ao vivo". -->
                    <span v-if="performer.activity_label">{{ performer.activity_label }}</span>
                </div>

                <!-- Bio -->
                <div v-if="performer.bio" class="mt-8 space-y-2">
                    <h2 class="font-serif text-xl text-limen-ink">Sobre</h2>
                    <p class="text-limen-ink-soft leading-relaxed whitespace-pre-line">{{ performer.bio }}</p>
                </div>

                <!-- O que procuro: parágrafo logo abaixo da bio. Opt-in — some
                     por inteiro para quem não preencheu. -->
                <div v-if="performer.looking_for" class="mt-6 space-y-2">
                    <h2 class="font-serif text-xl text-limen-ink">O que procuro</h2>
                    <p class="text-limen-ink-soft leading-relaxed whitespace-pre-line">{{ performer.looking_for }}</p>
                </div>

                <!-- Sobre mim / interesses: tags, idiomas, altura, bebida e fumo.
                     Componente compartilhado com Catalog/Show.vue. -->
                <PerformerAbout :performer="performer" />

                <!-- Galeria de fotos (Sprint 10). Pública — o visitante deslogado
                     também vê. Separada do avatar/capa e dos stories. -->
                <PhotoCarousel :photos="photos" :performer-name="performer.stage_name" :can-request="canTip" />

                <!-- Work modes -->
                <div v-if="performer.work_modes?.length" class="mt-8 space-y-3">
                    <h2 class="font-serif text-xl text-limen-ink">O que ofereço</h2>
                    <div class="flex flex-wrap gap-2">
                        <span
                            v-for="mode in performer.work_modes"
                            :key="mode"
                            class="rounded-full border border-limen-gold/30 bg-limen-surface px-3.5 py-1.5 text-xs text-limen-gold"
                        >
                            {{ workModeLabels[mode] ?? mode }}
                        </span>
                    </div>
                </div>

                <!-- Stories (Sprint 9C). Nesta porta o CTA do bloqueado leva ao
                     cadastro, não a assinar: o visitante ainda não tem conta, e
                     é o mesmo caminho de toda ação desta página. Para o membro
                     logado que chegar aqui por link direto, o `locked` já vem
                     resolvido pelo tier dele. -->
                <StoryStrip
                    :stories="stories"
                    :performer-name="performer.stage_name"
                    :locked-href="canTip ? route('subscribe.index') : route('entrada')"
                    :locked-label="canTip ? 'Assine para ver' : 'Crie sua conta'"
                    :can-report="report !== null"
                />

                <!-- Conteúdo permanente pago (Sprint 14, M.4/M.13.13). Substitui os
                     tiles-teaser fixos: agora são as peças REAIS que o tier do
                     espectador alcança. Visitante/performer/admin caem no cadastro
                     (signupHref); membro logado que chegue aqui usa o desbloqueio
                     da própria peça. Some por inteiro sem conteúdo do tier. -->
                <ContentGallery
                    :contents="contents"
                    :performer-name="performer.stage_name"
                    :signup-href="route('entrada')"
                />

                <!-- CTA -->
                <div class="mt-10 mb-16 rounded-2xl border border-limen-gold/30 bg-gradient-to-br from-limen-gold/10 to-transparent p-8 text-center space-y-3">
                    <h2 class="font-serif text-2xl text-limen-ink">Crie sua conta para interagir</h2>
                    <p class="text-limen-ink-mute text-sm max-w-md mx-auto">
                        Siga {{ performer.stage_name }}, envie gorjetas e desbloqueie conteúdo exclusivo. É rápido e discreto.
                    </p>
                    <Link
                        :href="route('entrada')"
                        class="inline-block no-underline border border-limen-gold text-limen-gold px-6 py-2.5 rounded-lg hover:bg-limen-gold/10 transition-colors"
                    >
                        Criar conta
                    </Link>
                </div>

                <!-- Denúncia: deliberadamente discreto (texto pequeno, cor
                     neutra, rodapé). Precisa existir e ser achável, não competir
                     com o conteúdo. Só para quem está logado — ver prop `report`. -->
                <div v-if="report" class="mb-16 text-center">
                    <button
                        type="button"
                        class="text-xs text-limen-ink-mute/70 underline underline-offset-4 hover:text-limen-ink-mute transition-colors"
                        @click="showReportModal = true"
                    >
                        Denunciar este perfil
                    </button>
                </div>
            </div>
        </div>

        <!-- Tip modal (componente compartilhado com Catalog/Show.vue). Só é
             montado para membro (role:consumer); os demais nem o abrem. -->
        <TipModal
            v-if="canTip"
            :show="showTipModal"
            :performer-slug="performer.slug"
            :performer-name="performer.stage_name"
            @close="showTipModal = false"
        />

        <ReportModal
            v-if="report"
            :show="showReportModal"
            :reportable-type="report.type"
            :reportable-id="report.id"
            @close="showReportModal = false"
        />

        <!-- Modal de desbloqueio do chat: só montado quando há conversa sem acesso
             em dia (chat && !chat.can_access). Compra a janela de acesso (50t/30d). -->
        <Modal
            v-if="chat && !chat.can_access"
            :show="showChatAccessModal"
            max-width="sm"
            @close="showChatAccessModal = false"
        >
            <h2 class="font-serif text-xl text-cream mb-2">Desbloquear o chat</h2>
            <p class="text-muted text-sm mb-4">
                {{ chat.cost }} tokens dão <span class="text-cream">30 dias</span> de acesso ao chat com
                {{ performer.stage_name }} — texto livre dentro da janela.
            </p>
            <p v-if="chatError" class="text-xs text-danger mb-3">{{ chatError }}</p>
            <div class="flex gap-3 justify-end">
                <Button variant="ghost" size="sm" @click="showChatAccessModal = false">Cancelar</Button>
                <Button variant="primary" size="sm" :loading="unlockingChat" @click="unlockChat">
                    {{ chat.cost }} tokens / 30 dias
                </Button>
            </div>
        </Modal>
    </GuestLayout>
</template>
