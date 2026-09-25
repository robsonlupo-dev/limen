<script setup>
import { computed, ref } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ProfileHero from '@/Components/Profile/ProfileHero.vue'
import ProfileTabs from '@/Components/Profile/ProfileTabs.vue'
import ProfileRates from '@/Components/Profile/ProfileRates.vue'
import AboutText from '@/Components/Profile/AboutText.vue'
import MemberProfileActions from '@/Components/Profile/MemberProfileActions.vue'
import VerificationPanel from '@/Components/Profile/VerificationPanel.vue'
import VoiceIntroPlayer from '@/Components/VoiceIntroPlayer.vue'
import TipModal from '@/Components/TipModal.vue'
import GiftModal from '@/Components/GiftModal.vue'
import ReportModal from '@/Components/ReportModal.vue'
import StoryStrip from '@/Components/StoryStrip.vue'
import StoryViewer from '@/Components/StoryViewer.vue'
import Lightbox from '@/Components/Lightbox.vue'
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
    stories: { type: Array, default: () => [] },
    photos: { type: Array, default: () => [] },
    contents: { type: Array, default: () => [] },
    report: { type: Object, default: null },
    chat: { type: Object, default: null },
    chatCost: { type: Number, default: null },
})

const page = usePage()
const canFavorite = computed(() => page.props.auth?.user?.role === 'consumer')
const myUserId = computed(() => page.props.auth?.user?.id ?? 0)
const features = computed(() => page.props.features ?? {})

// Localização + atividade (só na aba Sobre). Some enquanto ao vivo/online: presença
// em tempo real + UF entregaria onde ela está AGORA (mesma regra do card).
const hasStates = computed(() => (props.performer.states?.length ?? 0) > 0)
const statesLabel = computed(() => (props.performer.states ?? []).map(stateLabel).join(' · '))
const showLocationRow = computed(() =>
    !props.performer.is_live && !props.performer.is_available && (hasStates.value || props.performer.activity_label),
)

const workModeLabels = {
    live: 'Show ao vivo', video: 'Vídeos', chat: 'Chat privado',
    fotos: 'Fotos', privado: 'Sessão privada', exclusivo: 'Conteúdo exclusivo',
}

// feat/chat-economy-v2: conversa aberta → chat; senão → compor (paga ao enviar).
const chatHref = computed(() =>
    props.chat ? route('chat.show', props.chat.conversation_id) : route('chat.with', props.performer.slug),
)

// Abas (item 6): Fotos (padrão) · Sobre · Conteúdo. Contagem só quando > 0 — um
// "(0)" seria o zero-manchete que o item 7 proíbe.
const activeTab = ref('fotos')
const tabs = computed(() => [
    { key: 'fotos', label: 'Fotos', count: props.photos.length || null },
    { key: 'sobre', label: 'Sobre' },
    { key: 'conteudo', label: 'Conteúdo', count: props.contents.length || null },
])
const hasAbout = computed(() =>
    !!props.performer.bio || !!props.performer.looking_for || !!props.performer.work_modes?.length || showLocationRow.value,
)

// Barra de ações fixa no mobile: acima da navegação do painel (~58px + safe-area).
const dockOffset = 'calc(4rem + env(safe-area-inset-bottom))'

// Chamada 1:1 sob demanda (PR #140) — inalterada, só reposicionada.
const activeCall = ref(null)
async function onCallAccepted(callId) {
    try {
        const { token, wsUrl } = await postJson(route('call.token-refresh', callId))
        activeCall.value = { callId, token, wsUrl, pricePerMinute: props.performer.call_price_per_minute }
    } catch (e) { /* aceita mas token falhou: não abre a sala */ }
}
function onCallEnded() { activeCall.value = null }

const showTipModal = ref(false)
const showGiftModal = ref(false)
const showReportModal = ref(false)
const showVerified = ref(false)
const tipsCount = ref(props.performer.tips_count)
function onTipSent(data) { tipsCount.value = data.tips_count }

// Avatar → story (viewer em tela cheia) / foto (lightbox). O StoryViewer consome o
// formato de GRUPO (deriva a imagem de route('stories.image', id)); embrulhamos os
// stories VISÍVEIS deste perfil num grupo único. A foto do avatar abre no Lightbox.
const storyGroups = computed(() => {
    const viewable = props.stories.filter((s) => !s.locked)
    if (!viewable.length) return []
    return [{
        performer: {
            stage_name: props.performer.stage_name,
            slug: props.performer.slug,
            avatar_url: props.performer.avatar_url,
        },
        stories: viewable.map((s) => ({
            id: s.id,
            visibility_level: s.visibility_level,
            seen: s.seen,
            is_invite: false,
        })),
    }]
})
const storyViewerOpen = ref(false)
function openStoryViewer() {
    if (storyGroups.value.length) storyViewerOpen.value = true
}
const avatarLightboxIndex = ref(null)
function openAvatarPhoto() {
    if (props.performer.avatar_url) avatarLightboxIndex.value = 0
}
const avatarPhotos = computed(() => (props.performer.avatar_url
    ? [{ id: 'avatar', url: props.performer.avatar_url, full_url: props.performer.avatar_url }]
    : []))
</script>

<template>
    <AppLayout :title="performer.stage_name">
        <!-- Container centralizado, margens iguais dos dois lados (item 10). -->
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
            <ProfileHero
                :performer="performer"
                :features="features"
                :tips-count="Number(tipsCount)"
                :stories="stories"
                @open-verified="showVerified = true"
                @open-story="openStoryViewer"
                @open-avatar-photo="openAvatarPhoto"
            />

            <!-- Desktop: conteúdo à esquerda, ações+valores numa coluna que segue a
                 rolagem à direita. Mobile: tudo empilha; as ações viram barra fixa.
                 FLEX com utilitários padrão (não grid de valor arbitrário) para as
                 duas colunas engancharem de forma confiável no desktop. -->
            <div class="mt-6 lg:flex lg:items-start lg:gap-8">
                <!-- COLUNA PRINCIPAL -->
                <div class="min-w-0 space-y-6 lg:flex-1">
                    <!-- VOZ EM DESTAQUE (item 3): a peça de identidade do perfil.
                         Some por inteiro sem intro aprovada. -->
                    <VoiceIntroPlayer
                        v-if="performer.voice_intro_url"
                        variant="band"
                        :url="performer.voice_intro_url"
                        :label="`apresentação de ${performer.stage_name}`"
                        :performer-name="performer.stage_name"
                    />

                    <!-- Valores no mobile: logo abaixo da voz (no desktop vão para a
                         coluna de ações). -->
                    <ProfileRates :performer="performer" class="lg:hidden" />

                    <!-- Live/Chamada (Sprint 15): "Em breve" com as flags off (produção);
                         UI real quando ligadas. Discreto, abaixo da voz. -->
                    <div v-if="!features.live_enabled || !features.call_enabled" class="flex flex-wrap gap-3">
                        <ComingSoon v-if="!features.live_enabled" icon="camera" label="Assistir live" />
                        <ComingSoon v-if="!features.call_enabled" icon="phone" label="Chamada privada" />
                    </div>
                    <div
                        v-if="canFavorite && features.call_enabled && performer.call_price_per_minute"
                        class="flex flex-wrap gap-3"
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

                    <ProfileTabs v-model="activeTab" :tabs="tabs" />

                    <!-- FOTOS (padrão) -->
                    <div v-show="activeTab === 'fotos'" role="tabpanel" class="space-y-6">
                        <StoryStrip
                            v-if="stories.length"
                            :stories="stories"
                            :performer-name="performer.stage_name"
                            :locked-href="route('subscribe.index')"
                            locked-label="Assine para ver"
                            :can-report="report !== null"
                        />
                        <PhotoCarousel :photos="photos" :performer-name="performer.stage_name" :can-request="canFavorite" />
                        <p v-if="!photos.length && !stories.length" class="rounded-xl border border-limen-line bg-limen-surface px-4 py-8 text-center text-sm text-limen-ink-mute">
                            {{ performer.stage_name }} ainda não publicou fotos.
                        </p>
                    </div>

                    <!-- SOBRE -->
                    <div v-show="activeTab === 'sobre'" role="tabpanel" class="space-y-6">
                        <AboutText v-if="performer.bio" title="Sobre" :text="performer.bio" />
                        <AboutText v-if="performer.looking_for" title="O que procuro" :text="performer.looking_for" />
                        <PerformerAbout :performer="performer" />

                        <section v-if="performer.work_modes?.length" class="space-y-3">
                            <h3 class="font-serif text-xl text-limen-ink">O que ofereço</h3>
                            <div class="flex flex-wrap gap-2">
                                <span
                                    v-for="mode in performer.work_modes"
                                    :key="mode"
                                    class="rounded-full border border-limen-gold/30 bg-limen-surface px-3.5 py-1.5 text-xs text-limen-gold"
                                >{{ workModeLabels[mode] ?? mode }}</span>
                            </div>
                        </section>

                        <div v-if="showLocationRow" class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-limen-ink-mute">
                            <span v-if="hasStates">
                                {{ performer.states.length > 1 ? 'Estados' : 'Estado' }}:
                                <span class="text-limen-ink">{{ statesLabel }}</span>
                            </span>
                            <span v-if="hasStates && performer.activity_label" aria-hidden="true" class="text-limen-ink-mute/50">·</span>
                            <span v-if="performer.activity_label">{{ performer.activity_label }}</span>
                        </div>

                        <p v-if="!hasAbout" class="text-sm text-limen-ink-mute">
                            {{ performer.stage_name }} ainda não preencheu esta seção.
                        </p>
                    </div>

                    <!-- CONTEÚDO. A galeria lidera pelo que o membro PODE ver (item 7);
                         sem nenhuma peça, é convite, não um zero. -->
                    <div v-show="activeTab === 'conteudo'" role="tabpanel">
                        <ContentGallery
                            v-if="contents.length"
                            :contents="contents"
                            :performer-name="performer.stage_name"
                        />
                        <div v-else class="rounded-2xl border border-limen-gold/30 bg-gradient-to-br from-limen-gold/10 to-transparent px-6 py-10 text-center">
                            <p class="font-serif text-xl text-limen-ink">O conteúdo de {{ performer.stage_name }} é só para assinantes</p>
                            <p class="mx-auto mt-2 max-w-sm text-sm text-limen-ink-mute">Assine um Círculo para desbloquear as fotos e os vídeos exclusivos dela.</p>
                            <Link :href="route('subscribe.index')" class="mi-glow mt-4 inline-flex min-h-[44px] items-center rounded-lg bg-limen-gold px-6 text-sm font-semibold text-limen-bg no-underline transition-opacity hover:opacity-90">
                                Ver Círculos
                            </Link>
                        </div>
                    </div>

                    <!-- Denúncia: discreta, no rodapé do conteúdo. -->
                    <div v-if="report" class="pt-4 text-center">
                        <button
                            type="button"
                            class="text-xs text-limen-ink-mute/70 underline underline-offset-4 transition-colors hover:text-limen-ink-mute"
                            @click="showReportModal = true"
                        >Denunciar este perfil</button>
                    </div>

                    <!-- Folga para o conteúdo não ficar sob a barra fixa (mobile). -->
                    <div class="h-28 lg:hidden" aria-hidden="true" />
                </div>

                <!-- COLUNA DE AÇÕES (desktop sticky / mobile barra fixa). -->
                <aside v-if="canFavorite" class="lg:w-80 lg:shrink-0">
                    <MemberProfileActions
                        :performer="performer"
                        :features="features"
                        :chat-href="chatHref"
                        :chat-cost="chatCost"
                        :bottom-offset="dockOffset"
                        @tip="showTipModal = true"
                        @gift="showGiftModal = true"
                    />
                    <ProfileRates :performer="performer" class="mt-4 hidden lg:block" />
                </aside>
            </div>
        </div>

        <ReportModal
            v-if="report"
            :show="showReportModal"
            :reportable-type="report.type"
            :reportable-id="report.id"
            @close="showReportModal = false"
        />

        <TipModal
            :show="showTipModal"
            :performer-slug="performer.slug"
            :performer-name="performer.stage_name"
            @close="showTipModal = false"
            @sent="onTipSent"
        />

        <GiftModal
            :show="showGiftModal"
            :performer-slug="performer.slug"
            :performer-name="performer.stage_name"
            @close="showGiftModal = false"
        />

        <VerificationPanel
            :show="showVerified"
            :performer-name="performer.stage_name"
            :has-voice="!!performer.voice_intro_url"
            @close="showVerified = false"
        />

        <!-- Story em tela cheia a partir do avatar (reusa o viewer do catálogo). -->
        <StoryViewer
            v-if="storyViewerOpen"
            :groups="storyGroups"
            :start-group-index="0"
            @close="storyViewerOpen = false"
        />

        <!-- Foto de perfil em tela cheia. -->
        <Lightbox v-model:index="avatarLightboxIndex" :photos="avatarPhotos" />

        <!-- Sala da chamada 1:1 aceita (PR #140): cobre a tela. -->
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
