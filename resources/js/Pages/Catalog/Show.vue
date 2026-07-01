<script setup>
import { ref } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import VerifiedBadge from '@/Components/VerifiedBadge.vue'
import LiveBadge from '@/Components/LiveBadge.vue'
import StarRating from '@/Components/StarRating.vue'
import FollowButton from '@/Components/FollowButton.vue'
import Button from '@/Components/Button.vue'
import Modal from '@/Components/Modal.vue'
import Input from '@/Components/Input.vue'

const props = defineProps({
    performer: { type: Object, required: true },
})

const categoryLabels = {
    mulheres: 'Mulheres',
    homens: 'Homens',
    casais: 'Casais',
    trans: 'Trans',
    gls: 'GLS',
    swing: 'Swing',
}

const workModeLabels = {
    live: 'Show ao vivo',
    video: 'Vídeos',
    chat: 'Chat privado',
    fotos: 'Fotos',
    privado: 'Sessão privada',
    exclusivo: 'Conteúdo exclusivo',
}

const showTipModal = ref(false)
const tipAmount = ref('')
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
                            <VerifiedBadge />
                        </div>
                        <p class="text-sm text-gold uppercase tracking-wide">
                            {{ categoryLabels[performer.category] ?? performer.category }}
                        </p>
                        <StarRating :rating="performer.rating_avg" />
                    </div>

                    <div class="flex items-center gap-3">
                        <FollowButton
                            :slug="performer.slug"
                            :following="performer.is_following"
                            :reload-only="['performer']"
                        />
                        <Button variant="ghost" @click="showTipModal = true">Enviar gorjeta</Button>
                    </div>
                </div>

                <!-- Counters -->
                <div class="mt-6 flex items-center gap-8 text-sm text-muted border-y border-frame py-4">
                    <div>
                        <span class="text-cream font-medium">{{ performer.followers_count }}</span> seguidores
                    </div>
                    <div>
                        <span class="text-cream font-medium">{{ performer.tips_count }}</span> gorjetas recebidas
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
            </div>
        </div>

        <!-- Tip modal (placeholder — real flow in Fase 9) -->
        <Modal :show="showTipModal" max-width="sm" @close="showTipModal = false">
            <div class="space-y-4">
                <h3 class="font-serif text-2xl text-cream">Enviar gorjeta</h3>
                <p class="text-sm text-muted">
                    Envie uma gorjeta em tokens para {{ performer.stage_name }}. O envio será habilitado em breve.
                </p>
                <Input
                    id="tip-amount"
                    v-model="tipAmount"
                    type="number"
                    label="Valor em tokens"
                    placeholder="Ex: 50"
                />
                <div class="flex justify-end gap-3 pt-2">
                    <Button variant="ghost" @click="showTipModal = false">Cancelar</Button>
                    <Button variant="primary" disabled>Em breve</Button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
