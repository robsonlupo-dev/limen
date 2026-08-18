<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { MEMBER_SECTIONS, isSectionActive, isRouteActive } from '@/lib/memberNav'

// Subnav DENTRO da seção (feat/member-nav-restructure): quando a seção ativa tem
// filhos — Descobrir (Catálogo · Feed), Conexões (Interesses · Interessadas ·
// Quem me visitou · Salvos), Mensagens (Mensagens · Chamadas) e Carteira (Tokens
// · Círculos) —, esta barra fina aparece sob o header com as telas-irmãs. Some
// por completo na seção sem filhos (Início) e fora do painel.
//
// Mesma fonte da barra principal (lib/memberNav.js): a seção ativa e seus filhos
// concordam por construção.

const props = defineProps({
    // { messages, hearts } — reusa as bolinhas da nav no filho correspondente.
    navCounts: { type: Object, default: () => ({ messages: 0, hearts: 0 }) },
    // Flags Inertia (features.*): esconde o filho gateado (Chamadas sob call).
    features: { type: Object, default: () => ({}) },
})

const activeSection = computed(() =>
    MEMBER_SECTIONS.find((section) => section.children && isSectionActive(section)),
)

// Filhos visíveis: descarta os gateados por uma flag desligada (Chamadas).
const children = computed(() =>
    (activeSection.value?.children ?? []).filter((child) => !child.feature || props.features?.[child.feature]),
)

function badgeCount(child) {
    if (!child.badge) return 0
    return Number(props.navCounts?.[child.badge] ?? 0)
}
</script>

<template>
    <div v-if="activeSection" class="border-b border-gold/12 bg-surface/40">
        <div class="mx-auto flex max-w-6xl items-center gap-2 overflow-x-auto px-6 py-2">
            <Link
                v-for="child in children"
                :key="child.route"
                :href="route(child.route)"
                :aria-current="isRouteActive(child.route) ? 'page' : undefined"
                class="flex shrink-0 items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-xs font-medium no-underline transition-colors"
                :class="isRouteActive(child.route)
                    ? 'border-gold/50 bg-gold/10 text-gold'
                    : 'border-frame bg-surface-2 text-muted hover:border-gold/40 hover:text-cream'"
            >
                {{ child.label }}
                <span
                    v-if="badgeCount(child) > 0"
                    class="inline-flex min-w-[16px] items-center justify-center rounded-full bg-gold px-1 py-0.5 text-[9px] font-semibold leading-none text-background"
                    :aria-label="`${badgeCount(child)} não vistos`"
                >{{ badgeCount(child) > 9 ? '9+' : badgeCount(child) }}</span>
            </Link>
        </div>
    </div>
</template>
