<script setup>
import { computed, ref } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import VerifiedBadge from '@/Components/VerifiedBadge.vue'
import LiveBadge from '@/Components/LiveBadge.vue'
import TipModal from '@/Components/TipModal.vue'
import { WORLD_LABELS, WORLD_ICONS } from '@/lib/worlds'

const props = defineProps({
    performer: { type: Object, required: true },
    meta: { type: Object, default: () => ({ title: 'Limen', description: '' }) },
})

// Página pública (GuestLayout), mas acessível também por usuário logado. Só um
// membro (role:consumer) pode gorjetar — é o que o backend exige em
// POST /gorjetas (auth + role:consumer). Performer/admin logados e visitante
// deslogado caem no mesmo caminho: link para o cadastro.
const page = usePage()
const canTip = computed(() => page.props.auth?.user?.role === 'consumer')

const showTipModal = ref(false)

const workModeLabels = {
    live: 'Show ao vivo',
    video: 'Vídeos',
    chat: 'Chat privado',
    fotos: 'Fotos',
    privado: 'Sessão privada',
    exclusivo: 'Conteúdo exclusivo',
}

// Teaser tiles for the locked gallery. No real media is exposed to guests — the
// thumbnails are deliberately blurred placeholders that route to signup.
const lockedTiles = 6
</script>

<template>
    <GuestLayout :title="meta.title">
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
                            <VerifiedBadge />
                        </div>
                        <p class="text-sm text-gold uppercase tracking-wide flex items-center gap-1.5">
                            <span aria-hidden="true">{{ WORLD_ICONS[performer.category] }}</span>
                            {{ WORLD_LABELS[performer.category] ?? performer.category }}
                            <span class="text-muted normal-case tracking-normal">· Verificada</span>
                        </p>
                    </div>

                    <div class="flex items-center gap-3">
                        <!-- Seguir ainda exige conta: leva ao cadastro. -->
                        <Link
                            :href="route('entrada')"
                            class="no-underline border border-gold text-gold px-5 py-2 rounded-lg text-sm hover:bg-gold/10 transition-colors"
                        >
                            Seguir
                        </Link>
                        <!-- Gorjeta: só membro (role:consumer) abre o modal;
                             performer/admin/visitante vão ao cadastro. -->
                        <button
                            v-if="canTip"
                            type="button"
                            class="border border-frame text-muted px-5 py-2 rounded-lg text-sm hover:text-cream hover:border-gold/40 transition-colors"
                            @click="showTipModal = true"
                        >
                            Enviar gorjeta
                        </button>
                        <Link
                            v-else
                            :href="route('entrada')"
                            class="no-underline border border-frame text-muted px-5 py-2 rounded-lg text-sm hover:text-cream hover:border-gold/40 transition-colors"
                        >
                            Enviar gorjeta
                        </Link>
                    </div>
                </div>

                <!-- Counter -->
                <div class="mt-6 flex items-center gap-8 text-sm text-muted border-y border-frame py-4">
                    <div>
                        <span class="text-cream font-medium">{{ performer.followers_count }}</span> apoiadores
                    </div>
                </div>

                <!-- Bio -->
                <div v-if="performer.bio" class="mt-8 space-y-2">
                    <h2 class="font-serif text-xl text-cream">Sobre</h2>
                    <p class="text-muted leading-relaxed whitespace-pre-line">{{ performer.bio }}</p>
                </div>

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

                <!-- Locked gallery -->
                <div class="mt-8 space-y-3">
                    <h2 class="font-serif text-xl text-cream">Conteúdo</h2>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                        <Link
                            v-for="n in lockedTiles"
                            :key="n"
                            :href="route('entrada')"
                            class="group relative aspect-square rounded-xl overflow-hidden border border-frame bg-gradient-to-br from-surface-2 to-background flex items-center justify-center no-underline"
                        >
                            <div class="absolute inset-0 backdrop-blur-sm bg-background/30" />
                            <div class="relative flex flex-col items-center gap-1.5 text-center px-2">
                                <span class="text-2xl" aria-hidden="true">🔒</span>
                                <span class="text-[11px] text-muted group-hover:text-gold transition-colors">
                                    Desbloqueie com tokens
                                </span>
                            </div>
                        </Link>
                    </div>
                </div>

                <!-- CTA -->
                <div class="mt-10 mb-16 rounded-2xl border border-gold/30 bg-gradient-to-br from-gold/10 to-transparent p-8 text-center space-y-3">
                    <h2 class="font-serif text-2xl text-cream">Crie sua conta para interagir</h2>
                    <p class="text-muted text-sm max-w-md mx-auto">
                        Siga {{ performer.stage_name }}, envie gorjetas e desbloqueie conteúdo exclusivo. É rápido e discreto.
                    </p>
                    <Link
                        :href="route('entrada')"
                        class="inline-block no-underline border border-gold text-gold px-6 py-2.5 rounded-lg hover:bg-gold/10 transition-colors"
                    >
                        Criar conta
                    </Link>
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
    </GuestLayout>
</template>
