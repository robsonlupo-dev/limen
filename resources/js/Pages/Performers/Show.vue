<script setup>
import { computed, ref } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import ProfileHero from '@/Components/Profile/ProfileHero.vue'
import ProfileTabs from '@/Components/Profile/ProfileTabs.vue'
import ProfileRates from '@/Components/Profile/ProfileRates.vue'
import AboutText from '@/Components/Profile/AboutText.vue'
import GuestProfileActions from '@/Components/Profile/GuestProfileActions.vue'
import VerificationPanel from '@/Components/Profile/VerificationPanel.vue'
import VoiceIntroPlayer from '@/Components/VoiceIntroPlayer.vue'
import TipModal from '@/Components/TipModal.vue'
import ReportModal from '@/Components/ReportModal.vue'
import StoryStrip from '@/Components/StoryStrip.vue'
import PerformerAbout from '@/Components/PerformerAbout.vue'
import PhotoCarousel from '@/Components/PhotoCarousel.vue'
import ContentGallery from '@/Components/ContentGallery.vue'
import Modal from '@/Components/Modal.vue'
import Button from '@/Components/Button.vue'
import { stateLabel } from '@/lib/performerAttributes'
import { postJson } from '@/lib/http'

const props = defineProps({
    performer: { type: Object, required: true },
    chat: { type: Object, default: null },
    stories: { type: Array, default: () => [] },
    photos: { type: Array, default: () => [] },
    report: { type: Object, default: null },
    favorite: { type: Object, default: null },
    contents: { type: Array, default: () => [] },
    meta: { type: Object, default: () => ({ title: 'Limen', description: '' }) },
})

const page = usePage()
const canTip = computed(() => page.props.auth?.user?.role === 'consumer')

const hasStates = computed(() => (props.performer.states?.length ?? 0) > 0)
const statesLabel = computed(() => (props.performer.states ?? []).map(stateLabel).join(' · '))
const showLocationRow = computed(() =>
    !props.performer.is_live && !props.performer.is_available && (hasStates.value || props.performer.activity_label),
)

const workModeLabels = {
    live: 'Show ao vivo', video: 'Vídeos', chat: 'Chat privado',
    fotos: 'Fotos', privado: 'Sessão privada', exclusivo: 'Conteúdo exclusivo',
}

const activeTab = ref('fotos')
const tabs = computed(() => [
    { key: 'fotos', label: 'Fotos', count: props.photos.length || null },
    { key: 'sobre', label: 'Sobre' },
    { key: 'conteudo', label: 'Conteúdo', count: props.contents.length || null },
])
const hasAbout = computed(() =>
    !!props.performer.bio || !!props.performer.looking_for || !!props.performer.work_modes?.length || showLocationRow.value,
)

const showTipModal = ref(false)
const showReportModal = ref(false)
const showVerified = ref(false)

// Acesso ao chat (só quando há conversa aberta — prop `chat`).
const showChatAccessModal = ref(false)
const unlockingChat = ref(false)
const chatError = ref('')

async function unlockChat() {
    if (unlockingChat.value) return
    unlockingChat.value = true
    chatError.value = ''
    try {
        await postJson(route('chat.access.open', props.chat.conversation_id), { idempotency_key: crypto.randomUUID() })
        router.visit(route('chat.show', props.chat.conversation_id))
    } catch (e) {
        chatError.value = e.status === 422 && e.data?.reason === 'insufficient_balance'
            ? 'Saldo insuficiente. Compre tokens na sua carteira.'
            : (e.data?.message ?? 'Não foi possível desbloquear o chat.')
    } finally {
        unlockingChat.value = false
    }
}
</script>

<template>
    <GuestLayout :title="meta.title">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
            <ProfileHero
                :performer="performer"
                :tips-count="0"
                @open-verified="showVerified = true"
            />

            <div class="mt-6 lg:grid lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start lg:gap-8">
                <!-- COLUNA PRINCIPAL -->
                <div class="min-w-0 space-y-6">
                    <VoiceIntroPlayer
                        v-if="performer.voice_intro_url"
                        variant="band"
                        :url="performer.voice_intro_url"
                        :label="`apresentação de ${performer.stage_name}`"
                        :performer-name="performer.stage_name"
                    />

                    <ProfileRates :performer="performer" class="lg:hidden" />

                    <ProfileTabs v-model="activeTab" :tabs="tabs" />

                    <!-- FOTOS (padrão) -->
                    <div v-show="activeTab === 'fotos'" role="tabpanel" class="space-y-6">
                        <StoryStrip
                            v-if="stories.length"
                            :stories="stories"
                            :performer-name="performer.stage_name"
                            :locked-href="canTip ? route('subscribe.index') : route('entrada')"
                            :locked-label="canTip ? 'Assine para ver' : 'Crie sua conta'"
                            :can-report="report !== null"
                        />
                        <PhotoCarousel :photos="photos" :performer-name="performer.stage_name" :can-request="canTip" />
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

                    <!-- CONTEÚDO -->
                    <div v-show="activeTab === 'conteudo'" role="tabpanel">
                        <ContentGallery
                            v-if="contents.length"
                            :contents="contents"
                            :performer-name="performer.stage_name"
                            :signup-href="route('entrada')"
                        />
                        <div v-else class="rounded-2xl border border-limen-gold/30 bg-gradient-to-br from-limen-gold/10 to-transparent px-6 py-10 text-center">
                            <p class="font-serif text-xl text-limen-ink">O conteúdo de {{ performer.stage_name }} é para membros</p>
                            <p class="mx-auto mt-2 max-w-sm text-sm text-limen-ink-mute">Crie sua conta para desbloquear as fotos e os vídeos exclusivos dela.</p>
                            <Link :href="route('entrada')" class="mi-glow mt-4 inline-flex min-h-[44px] items-center rounded-lg bg-limen-gold px-6 text-sm font-semibold text-limen-bg no-underline transition-opacity hover:opacity-90">
                                Criar conta
                            </Link>
                        </div>
                    </div>

                    <div v-if="report" class="pt-4 text-center">
                        <button
                            type="button"
                            class="text-xs text-limen-ink-mute/70 underline underline-offset-4 transition-colors hover:text-limen-ink-mute"
                            @click="showReportModal = true"
                        >Denunciar este perfil</button>
                    </div>

                    <div class="h-28 lg:hidden" aria-hidden="true" />
                </div>

                <!-- AÇÕES (desktop sticky / mobile barra fixa). -->
                <aside class="lg:col-start-2">
                    <GuestProfileActions
                        :performer="performer"
                        :chat="chat"
                        :can-tip="canTip"
                        :favorite="favorite"
                        @tip="showTipModal = true"
                        @unlock-chat="showChatAccessModal = true"
                    />
                    <ProfileRates :performer="performer" class="mt-4 hidden lg:block" />
                </aside>
            </div>
        </div>

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

        <VerificationPanel
            :show="showVerified"
            :performer-name="performer.stage_name"
            :has-voice="!!performer.voice_intro_url"
            @close="showVerified = false"
        />

        <Modal
            v-if="chat && !chat.can_access"
            :show="showChatAccessModal"
            max-width="sm"
            @close="showChatAccessModal = false"
        >
            <h2 class="mb-2 font-serif text-xl text-cream">Desbloquear o chat</h2>
            <p class="mb-4 text-sm text-muted">
                {{ chat.cost }} tokens dão <span class="text-cream">30 dias</span> de acesso ao chat com
                {{ performer.stage_name }} — texto livre dentro da janela.
            </p>
            <p v-if="chatError" class="mb-3 text-xs text-danger">{{ chatError }}</p>
            <div class="flex justify-end gap-3">
                <Button variant="ghost" size="sm" @click="showChatAccessModal = false">Cancelar</Button>
                <Button variant="primary" size="sm" :loading="unlockingChat" @click="unlockChat">
                    {{ chat.cost }} tokens / 30 dias
                </Button>
            </div>
        </Modal>
    </GuestLayout>
</template>
