<script setup>
import { computed, ref } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import VerifiedBadge from '@/Components/VerifiedBadge.vue'
import VerificationBadges from '@/Components/VerificationBadges.vue'
import LiveBadge from '@/Components/LiveBadge.vue'
import StarRating from '@/Components/StarRating.vue'
import FollowButton from '@/Components/FollowButton.vue'
import FavoriteButton from '@/Components/FavoriteButton.vue'
import Button from '@/Components/Button.vue'
import TipModal from '@/Components/TipModal.vue'
import ReportModal from '@/Components/ReportModal.vue'
import StoryStrip from '@/Components/StoryStrip.vue'
import PerformerAbout from '@/Components/PerformerAbout.vue'
import PhotoCarousel from '@/Components/PhotoCarousel.vue'
import { stateLabel } from '@/lib/performerAttributes'

const props = defineProps({
    performer: { type: Object, required: true },
    // Stories vivos dela, já com `locked` resolvido pelo servidor. Story fechado
    // chega SEM `image_url` — ver StoryStrip.
    stories: { type: Array, default: () => [] },
    // Galeria de fotos pública (Sprint 10): cada item { id, url }. Público, sem
    // paywall — ver PhotoCarousel.
    photos: { type: Array, default: () => [] },
    // Alvo da denúncia ({ type, id }). Ver PublicCatalogController::show.
    report: { type: Object, default: null },
    // Estado do chat para o CTA "Iniciar conversa" do badge de disponibilidade
    // (Sprint 11). Null = membro sem conversa / performer / admin — chat é
    // interest-gated, não há chat frio. Ver CatalogController::chatStateFor.
    chat: { type: Object, default: null },
})

const categoryLabels = {
    mulheres: 'Mulheres',
    homens: 'Homens',
    casais: 'Casais',
    trans: 'Trans',
}

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

const showTipModal = ref(false)
const showReportModal = ref(false)
const tipsCount = ref(props.performer.tips_count)

function onTipSent(data) {
    tipsCount.value = data.tips_count
}
</script>

<template>
    <AppLayout :title="performer.stage_name">
        <div>
            <!-- Hero / cover -->
            <div class="relative h-64 md:h-80 bg-surface-2 overflow-hidden">
                <img
                    v-if="performer.cover_url"
                    :src="performer.cover_url"
                    :alt="performer.stage_name"
                    class="h-full w-full object-cover"
                />
                <div v-else class="h-full w-full bg-gradient-to-br from-gold/25 via-surface-2 to-background" />
                <div class="absolute inset-0 bg-gradient-to-t from-background via-background/20 to-transparent" />

                <div v-if="performer.is_live" class="absolute top-4 right-4">
                    <LiveBadge />
                </div>
            </div>

            <div class="max-w-4xl mx-auto px-6">
                <!-- Avatar -->
                <div class="-mt-16 flex items-end gap-5">
                    <div class="h-32 w-32 rounded-full border-4 border-gold bg-surface-2 overflow-hidden flex items-center justify-center shrink-0 shadow-2xl">
                        <img
                            v-if="performer.avatar_url"
                            :src="performer.avatar_url"
                            :alt="performer.stage_name"
                            class="h-full w-full object-cover"
                        />
                        <span v-else class="font-serif text-5xl text-gold">{{ performer.stage_name?.charAt(0) }}</span>
                    </div>
                </div>

                <!-- Identity -->
                <div class="mt-5 flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                    <div class="space-y-2">
                        <div class="flex items-center gap-2 flex-wrap">
                            <h1 class="font-serif text-4xl text-cream">{{ performer.stage_name }}</h1>
                            <VerifiedBadge :category="performer.category" />
                        </div>
                        <p class="text-sm text-gold uppercase tracking-wide">
                            {{ categoryLabels[performer.category] ?? performer.category }}
                        </p>
                        <VerificationBadges
                            :is-verified="performer.is_verified"
                            :email-verified="performer.email_verified"
                            :category="performer.category"
                            :tier="performer.tier"
                            size="md"
                        />
                        <StarRating :rating="performer.rating_avg" />
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

                <!-- Counters -->
                <div class="mt-6 flex items-center gap-8 text-sm text-muted border-y border-frame py-4">
                    <div>
                        <span class="text-cream font-medium">{{ performer.followers_label }}</span> seguidores
                    </div>
                    <div>
                        <span class="text-cream font-medium">{{ tipsCount }}</span> gorjetas recebidas
                    </div>
                </div>

                <!-- "Disponível para conversa" (Sprint 11), com destaque. Some
                     quando is_live (o LiveBadge do topo já sinaliza presença). O
                     CTA "Iniciar conversa" só aparece para o membro que já tem
                     conversa aberta (chat interest-gated, sem chat frio) e leva à
                     tela de chat — que resolve a compra de acesso se preciso. -->
                <div
                    v-if="performer.is_available && !performer.is_live"
                    class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gold/40 bg-gold/5 px-4 py-3"
                >
                    <p class="text-sm text-gold flex items-center gap-2">
                        <span aria-hidden="true">💬</span> Disponível para conversa
                    </p>
                    <Link
                        v-if="chat"
                        :href="route('chat.show', chat.conversation_id)"
                        class="no-underline bg-gold text-background px-4 py-1.5 rounded-lg text-sm hover:bg-gold-light transition-colors"
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
                    v-if="!performer.is_live && !performer.is_available && (performer.state || performer.activity_label)"
                    class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted"
                >
                    <span v-if="performer.state">
                        Estado: <span class="text-cream">{{ stateLabel(performer.state) }}</span>
                    </span>
                    <span v-if="performer.state && performer.activity_label" aria-hidden="true" class="text-muted/50">·</span>
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
                    <h2 class="font-serif text-xl text-cream">Sobre</h2>
                    <p class="text-muted leading-relaxed whitespace-pre-line">{{ performer.bio }}</p>
                </div>

                <!-- O que procuro: parágrafo logo abaixo da bio. Opt-in — some
                     por inteiro para quem não preencheu. -->
                <div v-if="performer.looking_for" class="mt-6 space-y-2">
                    <h2 class="font-serif text-xl text-cream">O que procuro</h2>
                    <p class="text-muted leading-relaxed whitespace-pre-line">{{ performer.looking_for }}</p>
                </div>

                <!-- Sobre mim: tags, idiomas, altura, bebida e fumo. Componente
                     compartilhado com Performers/Show.vue. -->
                <PerformerAbout :performer="performer" />

                <!-- Galeria de fotos (Sprint 10). Público, sem paywall — separada
                     do avatar/capa e dos stories. Some por inteiro sem foto. -->
                <PhotoCarousel :photos="photos" :performer-name="performer.stage_name" />

                <!-- Work modes -->
                <div v-if="performer.work_modes?.length" class="mt-8 space-y-3">
                    <h2 class="font-serif text-xl text-cream">O que ofereço</h2>
                    <div class="flex flex-wrap gap-2">
                        <span
                            v-for="mode in performer.work_modes"
                            :key="mode"
                            class="rounded-full border border-gold/30 bg-surface px-3.5 py-1.5 text-xs text-gold"
                        >
                            {{ workModeLabels[mode] ?? mode }}
                        </span>
                    </div>
                </div>

                <!-- Rates -->
                <div class="mt-8 mb-16 space-y-3">
                    <h2 class="font-serif text-xl text-cream">Valores</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div class="rounded-xl border border-frame bg-surface p-4 text-center">
                            <p class="text-2xl text-gold font-serif">{{ performer.rate_public }}</p>
                            <p class="text-xs text-muted mt-1">tokens/min · público</p>
                        </div>
                        <div class="rounded-xl border border-frame bg-surface p-4 text-center">
                            <p class="text-2xl text-gold font-serif">{{ performer.rate_private }}</p>
                            <p class="text-xs text-muted mt-1">tokens/min · privado</p>
                        </div>
                        <div class="rounded-xl border border-frame bg-surface p-4 text-center">
                            <p class="text-2xl text-gold font-serif">{{ performer.rate_camera }}</p>
                            <p class="text-xs text-muted mt-1">tokens/min · câmera</p>
                        </div>
                    </div>
                </div>

                <!-- Denúncia: discreto de propósito (ver Performers/Show.vue). -->
                <div v-if="report" class="mb-16 text-center">
                    <button
                        type="button"
                        class="text-xs text-muted/70 underline underline-offset-4 hover:text-muted transition-colors"
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
    </AppLayout>
</template>
