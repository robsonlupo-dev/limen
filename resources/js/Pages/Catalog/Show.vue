<script setup>
import { computed, ref } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import VerifiedBadge from '@/Components/VerifiedBadge.vue'
import CurationSeal from '@/Components/CurationSeal.vue'
import LiveBadge from '@/Components/LiveBadge.vue'
import VoiceIntroPlayer from '@/Components/VoiceIntroPlayer.vue'
import FollowButton from '@/Components/FollowButton.vue'
import FavoriteButton from '@/Components/FavoriteButton.vue'
import Button from '@/Components/Button.vue'
import TipModal from '@/Components/TipModal.vue'
import ReportModal from '@/Components/ReportModal.vue'
import StoryStrip from '@/Components/StoryStrip.vue'
import PerformerAbout from '@/Components/PerformerAbout.vue'
import PhotoCarousel from '@/Components/PhotoCarousel.vue'
import ContentGallery from '@/Components/ContentGallery.vue'
import ComingSoon from '@/Components/ComingSoon.vue'
import ScheduleCallModal from '@/Components/ScheduleCallModal.vue'
import CallRequest from '@/Components/CallRequest.vue'
import PrivateCall from '@/Components/PrivateCall.vue'
import { stateLabel } from '@/lib/performerAttributes'
import { postJson } from '@/lib/http'

const props = defineProps({
    performer: { type: Object, required: true },
    // Stories vivos dela, já com `locked` resolvido pelo servidor. Story fechado
    // chega SEM `image_url` — ver StoryStrip.
    stories: { type: Array, default: () => [] },
    // Galeria de fotos pública (Sprint 10): cada item { id, url }. Público, sem
    // paywall — ver PhotoCarousel.
    photos: { type: Array, default: () => [] },
    // Conteúdo permanente pago (Sprint 14, M.4/M.13.13). Só os níveis que o tier
    // deste membro alcança chegam — o Free recebe só o Aberto. Cada item já vem
    // com locked/price/image_url/can_unlock resolvido pelo servidor.
    contents: { type: Array, default: () => [] },
    // Alvo da denúncia ({ type, id }). Ver PublicCatalogController::show.
    report: { type: Object, default: null },
    // Estado do chat para o CTA "Iniciar conversa" do badge de disponibilidade
    // (Sprint 11). Null = membro sem conversa / performer / admin — chat é
    // interest-gated, não há chat frio. Ver CatalogController::chatStateFor.
    chat: { type: Object, default: null },
})

// Localizações (Sprint 13): UFs → nomes por extenso, "São Paulo · Rio de
// Janeiro". `states` cai em [state] quando há uma só (fallback do resource).
const hasStates = computed(() => (props.performer.states?.length ?? 0) > 0)
const statesLabel = computed(() => (props.performer.states ?? []).map(stateLabel).join(' · '))

const workModeLabels = {
    live: 'Show ao vivo',
    video: 'Vídeos',
    chat: 'Chat privado',
    fotos: 'Fotos',
    privado: 'Sessão privada',
    exclusivo: 'Conteúdo exclusivo',
}

// Esta tela é do grupo `auth`, não de `role:consumer` — performer e admin
// logados também a alcançam. Só o membro pode favoritar (é o que POST
// /favoritos/{slug} exige), então o botão só aparece para ele.
const page = usePage()
const canFavorite = computed(() => page.props.auth?.user?.role === 'consumer')
const myUserId = computed(() => page.props.auth?.user?.id ?? 0)
// Feature flags (Sprint 15). Off em produção → placeholders "Em breve".
const features = computed(() => page.props.features ?? {})

// Chamada 1:1 sob demanda (PR #140). O <CallRequest> pede e aguarda o aceite pelo
// canal user.{id}; no aceite, buscamos o token inicial (call.token-refresh) e
// abrimos a <PrivateCall>. Os minutos 2+ (e o próprio token-refresh de 5min) já
// vivem dentro dela.
const activeCall = ref(null)

async function onCallAccepted(callId) {
    try {
        const { token, wsUrl } = await postJson(route('call.token-refresh', callId))
        activeCall.value = { callId, token, wsUrl, pricePerMinute: props.performer.call_price_per_minute }
    } catch (e) {
        // Aceita mas o token falhou (sessão já caiu/saldo): não abre a sala.
    }
}

function onCallEnded() {
    activeCall.value = null
}

const showTipModal = ref(false)
const showReportModal = ref(false)
const tipsCount = ref(props.performer.tips_count)

// Métrica zerada NUNCA aparece (redesign maison): a contagem de gorjetas só é
// social proof quando existe. Zero vira ausência, não um "0" pendurado.
const showTips = computed(() => Number(tipsCount.value) > 0)

function onTipSent(data) {
    tipsCount.value = data.tips_count
}
</script>

<template>
    <AppLayout :title="performer.stage_name">
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

                <!-- O badge "AO VIVO" só existe com a feature ligada: com a flag
                     off ninguém pode estar ao vivo (Sprint 15). -->
                <div v-if="performer.is_live && features.live_enabled" class="absolute top-4 right-4">
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
                     ÚNICA marca de verificação — o antigo chip verde "Verificada", o
                     chip "Email", o rótulo do mundo e as estrelas 0.0 saíram no
                     redesign maison: redundantes ou métrica zerada). -->
                <div class="mt-5 flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                    <div class="flex items-center gap-2 flex-wrap">
                        <h1 class="font-serif text-4xl text-limen-ink">{{ performer.stage_name }}</h1>
                        <VerifiedBadge v-if="performer.is_verified" :category="performer.category" />
                        <CurationSeal :tier="performer.tier" />
                        <!-- Intro de voz aprovada (feat/voice-intro): botão dourado
                             de play ao lado do nome. `voice_intro_url` só vem
                             preenchida quando há intro aprovada (serving público
                             grátis) — sem ela, nada aqui. -->
                        <VoiceIntroPlayer
                            v-if="performer.voice_intro_url"
                            :url="performer.voice_intro_url"
                            :label="`apresentação de ${performer.stage_name}`"
                        />
                    </div>

                    <div class="flex items-center gap-3">
                        <FollowButton
                            :slug="performer.slug"
                            :following="performer.is_following"
                            :reload-only="['performer']"
                        />
                        <!-- Salvar: bookmark PRIVADO, ao lado de Seguir, que é
                             público. Só para membro — performer e admin também
                             chegam nesta tela e só `role:consumer` alcança a
                             rota do toggle. -->
                        <FavoriteButton
                            v-if="canFavorite"
                            :slug="performer.slug"
                            :saved="!!performer.is_favorited"
                            :reload-only="['performer']"
                            variant="button"
                        />
                        <Button variant="ghost" @click="showTipModal = true">Enviar gorjeta</Button>
                    </div>
                </div>

                <!-- Social proof: só gorjetas, e só quando há (métrica zerada NUNCA
                     aparece). A contagem de seguidores saiu — a faixa "Menos de 5"
                     lia como zero e não agregava. Sem a linha inteira quando não há
                     gorjeta: nenhuma barra vazia. -->
                <div v-if="showTips" class="mt-6 flex items-center gap-8 text-sm text-limen-ink-mute border-y border-limen-line py-4">
                    <div>
                        <span class="text-limen-ink font-medium">{{ tipsCount }}</span> gorjetas recebidas
                    </div>
                </div>

                <!-- Live / Chamada privada (Sprint 15). Em produção as flags estão
                     OFF: mostra o placeholder "Em breve" onde a ação apareceria.
                     Quando o advogado liberar (.env), o placeholder some e a UI real
                     da feature — já implementada — assume, sem deploy de código. -->
                <div v-if="!features.live_enabled || !features.call_enabled" class="mt-4 flex flex-wrap gap-3">
                    <ComingSoon v-if="!features.live_enabled" icon="camera" label="Assistir live" />
                    <ComingSoon v-if="!features.call_enabled" icon="phone" label="Chamada privada" />
                </div>

                <!-- Chamada 1:1: agora (CallRequest) e agendada (ScheduleCallModal).
                     Só para o MEMBRO, com a chamada ligada e a performer aceitando
                     (preço definido). A de agora pede e abre a sala no aceite; a
                     agendada trava um depósito e vai para "Minhas chamadas". -->
                <div
                    v-if="canFavorite && features.call_enabled && performer.call_price_per_minute"
                    class="mt-4 flex flex-wrap gap-3"
                >
                    <CallRequest
                        :performer-profile-id="performer.profile_id"
                        :price-per-minute="performer.call_price_per_minute"
                        :my-user-id="myUserId"
                        @accepted="onCallAccepted"
                    />
                    <ScheduleCallModal
                        :performer-profile-id="performer.profile_id"
                        :price-per-minute="performer.call_price_per_minute"
                    />
                </div>

                <!-- "Online agora" (fix/panel-polish-v1): presença DERIVADA da
                     sessão (is_available = isOnline), com destaque. Some quando
                     is_live (o LiveBadge do topo já sinaliza presença ao vivo). O
                     CTA "Iniciar conversa" só aparece para o membro que já tem
                     conversa aberta (chat interest-gated, sem chat frio) e leva à
                     tela de chat — que resolve a compra de acesso se preciso. -->
                <div
                    v-if="performer.is_available && !performer.is_live"
                    class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-limen-gold/40 bg-limen-gold/5 px-4 py-3"
                >
                    <p class="text-sm text-limen-ink flex items-center gap-2">
                        <span aria-hidden="true" class="inline-block h-2.5 w-2.5 rounded-full bg-success" /> Online agora
                    </p>
                    <Link
                        v-if="chat"
                        :href="route('chat.show', chat.conversation_id)"
                        class="no-underline bg-limen-gold text-limen-bg px-4 py-1.5 rounded-lg text-sm hover:opacity-90 transition-opacity"
                    >
                        Iniciar conversa
                    </Link>
                </div>

                <!-- Estado por extenso; ausente para quem não preencheu. A
                     cidade não existe nesta prop (ver PerformerPublicResource).
                     Ausente enquanto ela está ao vivo OU disponível — mesma regra
                     do card e da página pública: presença em tempo real (selo "ao
                     vivo" ou badge de disponibilidade) mais a UF entregam onde ela
                     está NESTE momento (R2). -->
                <div
                    v-if="!performer.is_live && !performer.is_available && (hasStates || performer.activity_label)"
                    class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-limen-ink-mute"
                >
                    <!-- Múltiplas localizações (Sprint 13): estados por extenso,
                         "São Paulo · Rio de Janeiro". Só a UF; `city` não chega. -->
                    <span v-if="hasStates">
                        {{ performer.states.length > 1 ? 'Estados' : 'Estado' }}:
                        <span class="text-limen-ink">{{ statesLabel }}</span>
                    </span>
                    <span v-if="hasStates && performer.activity_label" aria-hidden="true" class="text-limen-ink-mute/50">·</span>
                    <!-- Última atividade em faixa, ao lado do estado (Sprint 10).
                         Faixa, nunca relógio (ActivitySlot). Some quando is_live
                         — o LiveBadge do topo já diz "agora" — e quando null. -->
                    <span v-if="performer.activity_label">{{ performer.activity_label }}</span>
                </div>

                <!-- Stories (Sprint 9C). O CTA do bloqueado leva a assinar: o
                     membro já tem conta, o que falta é o tier. -->
                <StoryStrip
                    :stories="stories"
                    :performer-name="performer.stage_name"
                    :locked-href="route('subscribe.index')"
                    locked-label="Assine para ver"
                    :can-report="report !== null"
                />

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
                     Componente compartilhado com Performers/Show.vue. -->
                <PerformerAbout :performer="performer" />

                <!-- Galeria de fotos (Sprint 10). Público, sem paywall — separada
                     do avatar/capa e dos stories. Some por inteiro sem foto. -->
                <PhotoCarousel :photos="photos" :performer-name="performer.stage_name" :can-request="canFavorite" />

                <!-- Conteúdo permanente pago (Sprint 14, M.4/M.13.13). O membro
                     desbloqueia com tokens (permanente); a peça bloqueada NÃO traz
                     URL de bytes. O membro logado usa o botão de desbloquear (não
                     há signupHref). Some por inteiro se não há conteúdo do tier. -->
                <ContentGallery :contents="contents" :performer-name="performer.stage_name" />

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

                <!-- Rates -->
                <div class="mt-8 mb-16 space-y-3">
                    <h2 class="font-serif text-xl text-limen-ink">Valores</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div class="rounded-xl border border-limen-line bg-limen-surface p-4 text-center">
                            <p class="text-2xl text-limen-gold font-serif">{{ performer.rate_public }}</p>
                            <p class="text-xs text-limen-ink-mute mt-1">tokens/min · público</p>
                        </div>
                        <div class="rounded-xl border border-limen-line bg-limen-surface p-4 text-center">
                            <p class="text-2xl text-limen-gold font-serif">{{ performer.rate_private }}</p>
                            <p class="text-xs text-limen-ink-mute mt-1">tokens/min · privado</p>
                        </div>
                        <div class="rounded-xl border border-limen-line bg-limen-surface p-4 text-center">
                            <p class="text-2xl text-limen-gold font-serif">{{ performer.rate_camera }}</p>
                            <p class="text-xs text-limen-ink-mute mt-1">tokens/min · câmera</p>
                        </div>
                    </div>
                </div>

                <!-- Denúncia: discreto de propósito (ver Performers/Show.vue). -->
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

        <ReportModal
            v-if="report"
            :show="showReportModal"
            :reportable-type="report.type"
            :reportable-id="report.id"
            @close="showReportModal = false"
        />

        <!-- Tip modal (componente compartilhado com Performers/Show.vue) -->
        <TipModal
            :show="showTipModal"
            :performer-slug="performer.slug"
            :performer-name="performer.stage_name"
            @close="showTipModal = false"
            @sent="onTipSent"
        />

        <!-- Sala da chamada 1:1 aceita (PR #140). Cobre a tela; o 1º minuto já foi
             cobrado no aceite, e a cobrança/renovação seguem dentro da <PrivateCall>. -->
        <div v-if="activeCall" class="fixed inset-0 z-50 bg-black">
            <PrivateCall
                :call-id="activeCall.callId"
                :token="activeCall.token"
                :ws-url="activeCall.wsUrl"
                role="member"
                :price-per-minute="activeCall.pricePerMinute"
                @ended="onCallEnded"
            />
        </div>
    </AppLayout>
</template>
