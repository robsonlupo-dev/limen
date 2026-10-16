<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { PERFORMER_SECTIONS, isSectionActive, isRouteActive } from '@/lib/performerNav'

// Subnav DENTRO da seção (feat/performer-nav-restructure): quando a seção ativa
// tem filhos — Painel (Resumo · Stories · Canal · Insights · Ajustes), Pessoas
// (Seguidores · Membros · Solicitações), Ganhos (Saques · Histórico) —, esta
// barra fina aparece sob o header com as telas-irmãs. Some por completo nas
// seções sem filhos e fora do painel.
//
// Mesma fonte da barra principal (lib/performerNav.js): a seção ativa e seus
// filhos concordam por construção. O `badge` de um filho (ex.: Solicitações)
// lê nav_counts da página — o mesmo número que a barra de cima soma —, sem
// prop nova na montagem do layout.

const page = usePage()
const navCounts = computed(() => page.props.nav_counts ?? {})

const activeSection = computed(() =>
    PERFORMER_SECTIONS.find((section) => section.children && isSectionActive(section)),
)

function childBadge(child) {
    if (!child.badge) return 0
    return Number(navCounts.value[child.badge] ?? 0)
}
</script>

<template>
    <div v-if="activeSection" class="border-b border-frame/50 bg-surface/40">
        <div class="mx-auto flex max-w-6xl items-center gap-2 overflow-x-auto px-6 py-2">
            <Link
                v-for="child in activeSection.children"
                :key="child.route"
                :href="route(child.route)"
                :aria-current="isRouteActive(child.route) ? 'page' : undefined"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-xs font-medium no-underline transition-colors"
                :class="isRouteActive(child.route)
                    ? 'border-gold/50 bg-gold/10 text-gold'
                    : 'border-frame bg-surface-2 text-muted hover:border-gold/40 hover:text-cream'"
            >
                {{ child.label }}
                <span
                    v-if="childBadge(child) > 0"
                    :aria-label="`${childBadge(child)} pendente(s)`"
                    class="inline-flex min-w-[1.125rem] items-center justify-center rounded-full bg-gold px-1.5 text-[10px] font-semibold leading-[1.125rem] text-background"
                >
                    {{ childBadge(child) > 9 ? '9+' : childBadge(child) }}
                </span>
            </Link>
        </div>
    </div>
</template>
