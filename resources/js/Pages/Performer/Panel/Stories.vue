<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import StoryPanel from '@/Components/StoryPanel.vue'
import HighlightsManager from '@/Components/HighlightsManager.vue'

// Painel › Stories (reorg do Painel, UAT Fase 21). O "Meus Stories" saiu do fim
// da rolagem do Resumo e virou aba: publicar (no topo do StoryPanel), os ativos
// com enquete, e os Destaques (a mesma UI da tela de editar perfil).
//
// `view_count` já vem em FAIXA (ou null no exclusivo) — o número cru não trafega.
defineProps({
    stories: { type: Array, default: () => [] },
    storyVisibilityLevels: { type: Array, default: () => [] },
    storySubscriberMinTiers: { type: Array, default: () => [] },
    storyInviteLimit: { type: Number, default: 2 },
    highlights: { type: Array, default: () => [] },
    highlightStories: { type: Array, default: () => [] },
})
</script>

<template>
    <AppLayout title="Stories">
        <div class="max-w-6xl mx-auto px-6 py-10 space-y-8">
            <div class="space-y-1">
                <h1 class="font-serif text-4xl text-cream">Stories</h1>
                <p class="text-muted text-sm">
                    Somem em 24 horas. Você vê quantas pessoas viram, em faixa — nunca quem viu.
                </p>
            </div>

            <StoryPanel
                :stories="stories"
                :visibility-levels="storyVisibilityLevels"
                :subscriber-min-tiers="storySubscriberMinTiers"
                :invite-limit="storyInviteLimit"
            />

            <!-- Destaques (Onda 1): coleções permanentes a partir de stories
                 PÚBLICOS. Mesmo componente e mesmas props da tela de editar perfil. -->
            <section class="space-y-3">
                <h2 class="font-serif text-xl text-cream">Destaques</h2>
                <HighlightsManager :initial-highlights="highlights" :stories="highlightStories" />
            </section>
        </div>
    </AppLayout>
</template>
