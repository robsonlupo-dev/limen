<script setup>
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'
import { Link } from '@inertiajs/vue3'
import { PERFORMER_SECTIONS, PERFORMER_MENU, isSectionActive, isRouteActive } from '@/lib/performerNav'

// Navegação do painel da performer, MOBILE PRIMEIRO (feat/performer-nav-restructure).
//
//  - Celular: barra superior enxuta (só o menu do avatar) + BARRA FIXA NO RODAPÉ
//    com as 5 seções (ícone + rótulo curto), padrão de app. O logo mora no layout.
//  - Desktop: as 5 seções em linha + o avatar à direita.
//
// A seção ativa é marcada nas DUAS versões. Nenhuma rota/permissão muda — os
// destinos são os de sempre (ver lib/performerNav.js), só reagrupados. O botão de
// Saída rápida NÃO está aqui: virou elemento flutuante global (ver PanicButton.vue).

const props = defineProps({
    // { messages, hearts } — bolinhas de não-vistos (NavBadgeService). Só desenha.
    navCounts: { type: Object, default: () => ({ messages: 0, hearts: 0 }) },
    // Flags Inertia (features.*): gateiam itens de menu sob dark launch (call).
    features: { type: Object, default: () => ({}) },
    // Nome da performer, para o cabeçalho do menu do avatar.
    userName: { type: String, default: '' },
})

// 'logout' não navega: pede ao layout para abrir o modal de confirmação de saída.
const emit = defineEmits(['logout'])

// Ícones (Lucide, stroke). Guardados como descritores {tag, attrs} para renderizar
// rect/circle/path com o mesmo <component>. Só as 5 seções precisam de ícone.
const ICONS = {
    painel: [
        { tag: 'rect', attrs: { width: 7, height: 9, x: 3, y: 3, rx: 1 } },
        { tag: 'rect', attrs: { width: 7, height: 5, x: 14, y: 3, rx: 1 } },
        { tag: 'rect', attrs: { width: 7, height: 9, x: 14, y: 12, rx: 1 } },
        { tag: 'rect', attrs: { width: 7, height: 5, x: 3, y: 16, rx: 1 } },
    ],
    conteudo: [
        { tag: 'rect', attrs: { width: 18, height: 18, x: 3, y: 3, rx: 2 } },
        { tag: 'circle', attrs: { cx: 9, cy: 9, r: 2 } },
        { tag: 'path', attrs: { d: 'm21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21' } },
    ],
    mensagens: [
        { tag: 'path', attrs: { d: 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z' } },
    ],
    pessoas: [
        { tag: 'path', attrs: { d: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2' } },
        { tag: 'circle', attrs: { cx: 9, cy: 7, r: 4 } },
        { tag: 'path', attrs: { d: 'M22 21v-2a4 4 0 0 0-3-3.87' } },
        { tag: 'path', attrs: { d: 'M16 3.13a4 4 0 0 1 0 7.75' } },
    ],
    ganhos: [
        { tag: 'path', attrs: { d: 'M21 12V7H5a2 2 0 0 1 0-4h14v4' } },
        { tag: 'path', attrs: { d: 'M3 5v14a2 2 0 0 0 2 2h16v-5' } },
        { tag: 'path', attrs: { d: 'M18 12a2 2 0 0 0 0 4h4v-4z' } },
    ],
}

const sections = PERFORMER_SECTIONS

// Menu do avatar sem os itens gateados por flag desligada (ex.: Agendamentos sob
// feature:call). O item de logout nunca é gateado.
const menuItems = computed(() =>
    PERFORMER_MENU.filter((item) => !item.feature || props.features?.[item.feature]),
)

function badgeCount(section) {
    if (!section.badge) return 0
    return Number(props.navCounts?.[section.badge] ?? 0)
}

const monogram = computed(() => (props.userName || '?').trim().charAt(0).toUpperCase() || '?')

// ── Menu do avatar: abre/fecha, Escape e clique-fora ─────────────────────────
const menuOpen = ref(false)
const menuRoot = ref(null)

function toggleMenu() {
    menuOpen.value = !menuOpen.value
}

function closeMenu() {
    menuOpen.value = false
}

function onMenuItem(item) {
    closeMenu()
    if (item.action === 'logout') {
        emit('logout')
    }
    // Itens com rota são <Link> — a navegação é do próprio Inertia.
}

// Clique fora fecha. Não mexemos no Escape: um Escape sozinho só fecha o menu; o
// DUPLO-Escape continua sendo a Saída rápida (PanicButton, listener em window) —
// por isso NÃO damos stopPropagation no Escape, senão a saída de emergência
// deixaria de contar os dois toques com o menu aberto.
function onDocClick(event) {
    if (!menuOpen.value) return
    if (menuRoot.value && !menuRoot.value.contains(event.target)) {
        closeMenu()
    }
}

function onKeydown(event) {
    if (event.key === 'Escape' && menuOpen.value) {
        closeMenu()
    }
}

onMounted(() => {
    document.addEventListener('click', onDocClick)
    document.addEventListener('keydown', onKeydown)
})

onBeforeUnmount(() => {
    document.removeEventListener('click', onDocClick)
    document.removeEventListener('keydown', onKeydown)
})
</script>

<template>
    <!-- Barra superior: seções (só desktop) + avatar (todas as larguras). -->
    <div class="flex flex-1 items-center justify-end gap-2 md:justify-between md:gap-4">
        <!-- Seções — desktop. No celular vão para a barra do rodapé. -->
        <nav class="hidden items-center gap-1 md:flex" aria-label="Seções do painel">
            <Link
                v-for="section in sections"
                :key="section.key"
                :href="route(section.route)"
                :aria-current="isSectionActive(section) ? 'page' : undefined"
                class="pnav-top relative rounded-md px-3 py-2 text-sm font-medium no-underline transition-colors"
                :class="isSectionActive(section)
                    ? 'text-gold'
                    : 'text-muted hover:text-cream'"
            >
                {{ section.label }}
                <span
                    v-if="badgeCount(section) > 0"
                    class="ml-1 inline-flex min-w-[18px] items-center justify-center rounded-full bg-gold px-1.5 py-0.5 text-[10px] font-semibold leading-none text-background"
                    :aria-label="`${badgeCount(section)} não vistos`"
                >{{ badgeCount(section) > 99 ? '99+' : badgeCount(section) }}</span>
                <!-- "Vela" dourada da seção ativa (2px). Só cor/opacidade animam. -->
                <span
                    v-if="isSectionActive(section)"
                    class="pnav-marker absolute inset-x-3 -bottom-px h-0.5 rounded-full bg-gold"
                    aria-hidden="true"
                />
            </Link>
        </nav>

        <!-- Menu do avatar -->
        <div ref="menuRoot" class="relative shrink-0">
            <button
                type="button"
                class="flex h-10 w-10 items-center justify-center rounded-full border border-gold/40 bg-surface text-sm font-semibold text-gold transition-colors hover:border-gold focus:outline-none focus-visible:ring-2 focus-visible:ring-gold/60"
                :aria-expanded="menuOpen"
                aria-haspopup="menu"
                aria-label="Menu da conta"
                @click.stop="toggleMenu"
            >
                {{ monogram }}
            </button>

            <transition name="pnav-menu">
                <div
                    v-if="menuOpen"
                    class="absolute right-0 top-12 z-50 w-56 overflow-hidden rounded-xl border border-frame bg-surface shadow-xl shadow-black/40"
                    role="menu"
                    aria-label="Menu da conta"
                >
                    <div class="border-b border-frame/60 px-4 py-3">
                        <p class="text-xs uppercase tracking-wide text-muted">Conectada como</p>
                        <p class="truncate text-sm text-cream">{{ userName || 'Sua conta' }}</p>
                    </div>
                    <div class="py-1">
                        <template v-for="item in menuItems" :key="item.key">
                            <Link
                                v-if="item.route"
                                :href="route(item.route)"
                                role="menuitem"
                                class="block px-4 py-2.5 text-sm no-underline transition-colors"
                                :class="isRouteActive(item.route)
                                    ? 'bg-gold/10 text-gold'
                                    : 'text-cream hover:bg-surface-2'"
                                @click="onMenuItem(item)"
                            >
                                {{ item.label }}
                            </Link>
                            <button
                                v-else
                                type="button"
                                role="menuitem"
                                class="block w-full border-t border-frame/60 px-4 py-2.5 text-left text-sm text-muted transition-colors hover:bg-surface-2 hover:text-cream"
                                @click="onMenuItem(item)"
                            >
                                {{ item.label }}
                            </button>
                        </template>
                    </div>
                </div>
            </transition>
        </div>
    </div>

    <!-- Barra FIXA no rodapé — celular. Padrão de app: 5 seções, ícone + rótulo,
         alvo ≥44px, respeitando a safe-area do iOS. Escondida no desktop. -->
    <nav
        class="pnav-bottom fixed inset-x-0 bottom-0 z-40 flex items-stretch border-t border-frame bg-surface/95 pb-[env(safe-area-inset-bottom)] backdrop-blur md:hidden"
        aria-label="Navegação do painel"
    >
        <Link
            v-for="section in sections"
            :key="section.key"
            :href="route(section.route)"
            :aria-current="isSectionActive(section) ? 'page' : undefined"
            class="relative flex flex-1 flex-col items-center justify-center gap-1 px-1 py-2 text-[11px] font-medium no-underline transition-colors"
            :class="isSectionActive(section) ? 'text-gold' : 'text-muted'"
        >
            <span
                v-if="isSectionActive(section)"
                class="pnav-marker absolute inset-x-4 top-0 h-0.5 rounded-full bg-gold"
                aria-hidden="true"
            />
            <span class="relative">
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.6"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    class="h-6 w-6"
                    aria-hidden="true"
                >
                    <component :is="el.tag" v-for="(el, i) in ICONS[section.key]" :key="i" v-bind="el.attrs" />
                </svg>
                <span
                    v-if="badgeCount(section) > 0"
                    class="absolute -right-2 -top-1 inline-flex min-w-[16px] items-center justify-center rounded-full bg-gold px-1 py-0.5 text-[9px] font-semibold leading-none text-background"
                    :aria-label="`${badgeCount(section)} não vistos`"
                >{{ badgeCount(section) > 9 ? '9+' : badgeCount(section) }}</span>
            </span>
            {{ section.label }}
        </Link>
    </nav>
</template>

<style scoped>
/* Abertura do menu do avatar: fade + leve subida. Desligado sob reduced-motion. */
.pnav-menu-enter-active,
.pnav-menu-leave-active {
    transition: opacity 0.15s ease, transform 0.15s ease;
}
.pnav-menu-enter-from,
.pnav-menu-leave-to {
    opacity: 0;
    transform: translateY(-4px);
}

@media (prefers-reduced-motion: reduce) {
    .pnav-menu-enter-active,
    .pnav-menu-leave-active {
        transition: none;
    }
    .pnav-menu-enter-from,
    .pnav-menu-leave-to {
        transform: none;
    }
}
</style>
