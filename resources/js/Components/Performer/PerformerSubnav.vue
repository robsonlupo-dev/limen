<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { PERFORMER_SECTIONS, isSectionActive, isRouteActive } from '@/lib/performerNav'

// Subnav DENTRO da seção (feat/performer-nav-restructure): quando a seção ativa
// tem filhos — Pessoas (Seguidores · Membros) e Ganhos (Saques · Histórico) —,
// esta barra fina aparece sob o header com as telas-irmãs. Some por completo nas
// seções sem filhos (Painel, Conteúdo, Mensagens) e fora do painel.
//
// Mesma fonte da barra principal (lib/performerNav.js): a seção ativa e seus
// filhos concordam por construção.

const activeSection = computed(() =>
    PERFORMER_SECTIONS.find((section) => section.children && isSectionActive(section)),
)
</script>

<template>
    <div v-if="activeSection" class="border-b border-frame/50 bg-surface/40">
        <div class="mx-auto flex max-w-6xl items-center gap-2 overflow-x-auto px-6 py-2">
            <Link
                v-for="child in activeSection.children"
                :key="child.route"
                :href="route(child.route)"
                :aria-current="isRouteActive(child.route) ? 'page' : undefined"
                class="shrink-0 rounded-full border px-3.5 py-1.5 text-xs font-medium no-underline transition-colors"
                :class="isRouteActive(child.route)
                    ? 'border-gold/50 bg-gold/10 text-gold'
                    : 'border-frame bg-surface-2 text-muted hover:border-gold/40 hover:text-cream'"
            >
                {{ child.label }}
            </Link>
        </div>
    </div>
</template>
