<script setup>
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'
import { Link } from '@inertiajs/vue3'
import { MEMBER_SECTIONS, MEMBER_MENU, isSectionActive, isRouteActive } from '@/lib/memberNav'

// Navegação do painel do MEMBRO, MOBILE PRIMEIRO (feat/member-nav-restructure).
//
//  - Celular: barra superior enxuta (só o menu do avatar) + BARRA FIXA NO RODAPÉ
//    com as 5 seções (ícone + rótulo curto), padrão de app. O logo mora no layout.
//  - Desktop: as 5 seções em linha + o avatar à direita.
//
// A seção ativa é marcada nas DUAS versões pela CURVA dourada do portal (o
// elemento-assinatura), não por um traço reto. Nenhuma rota/permissão muda — os
// destinos são os de sempre (ver lib/memberNav.js), só reagrupados. O botão de
// Saída rápida NÃO está aqui: é elemento flutuante global (ver PanicButton.vue).

const props = defineProps({
    // { messages, hearts } — bolinhas de não-vistos (NavBadgeService). Só desenha.
    navCounts: { type: Object, default: () => ({ messages: 0, hearts: 0 }) },
    // Flags Inertia (features.*): gateiam itens de subnav sob dark launch (call).
    features: { type: Object, default: () => ({}) },
    // Nome do membro, para o cabeçalho do menu do avatar.
    userName: { type: String, default: '' },
})

// 'logout' não navega: pede ao layout para abrir o modal de confirmação de saída.
const emit = defineEmits(['logout'])

// Ícones (Lucide, stroke), como descritores {tag, attrs} renderizados por
// <component>. O de "Início" é um ARCO de portal — a assinatura entra já na nav.
const ICONS = {
    inicio: [
        { tag: 'path', attrs: { d: 'M3 21h18' } },
        { tag: 'path', attrs: { d: 'M5 21V11a7 7 0 0 1 14 0v10' } },
        { tag: 'path', attrs: { d: 'M10 21v-5a2 2 0 0 1 4 0v5' } },
    ],
    descobrir: [
        { tag: 'circle', attrs: { cx: 12, cy: 12, r: 9 } },
        { tag: 'path', attrs: { d: 'm15.5 8.5-2 5-5 2 2-5 5-2z' } },
    ],
    conexoes: [
        { tag: 'path', attrs: { d: 'M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z' } },
    ],
    mensagens: [
        { tag: 'path', attrs: { d: 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z' } },
    ],
    carteira: [
        { tag: 'path', attrs: { d: 'M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4' } },
        { tag: 'path', attrs: { d: 'M4 6v12c0 1.1.9 2 2 2h14v-4' } },
        { tag: 'path', attrs: { d: 'M18 12a2 2 0 0 0 0 4h4v-4Z' } },
    ],
}

const sections = MEMBER_SECTIONS
const menuItems = MEMBER_MENU

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

// Clique fora fecha. Não damos stopPropagation no Escape: um Escape sozinho só
// fecha o menu; o DUPLO-Escape continua sendo a Saída rápida (PanicButton,
// listener em window) — engolir o Escape aqui derrubaria a saída de emergência.
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
                class="relative rounded-md px-3 py-2 text-sm font-medium no-underline transition-colors"
                :class="isSectionActive(section) ? 'text-gold' : 'text-muted hover:text-cream'"
            >
                {{ section.label }}
                <span
                    v-if="badgeCount(section) > 0"
                    class="ml-1 inline-flex min-w-[18px] items-center justify-center rounded-full bg-gold px-1.5 py-0.5 text-[10px] font-semibold leading-none text-background"
                    :aria-label="`${badgeCount(section)} não vistos`"
                >{{ badgeCount(section) > 99 ? '99+' : badgeCount(section) }}</span>
                <!-- Curva dourada do portal marcando a seção ativa (a assinatura,
                     no lugar do traço reto). Só opacidade anima. -->
                <svg
                    v-if="isSectionActive(section)"
                    class="mnav-arc absolute inset-x-3 -bottom-0.5 h-2 w-auto text-gold"
                    viewBox="0 0 28 8"
                    fill="none"
                    preserveAspectRatio="none"
                    aria-hidden="true"
                >
                    <path d="M1 7C1 7 6 1 14 1C22 1 27 7 27 7" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" />
                </svg>
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

            <transition name="mnav-menu">
                <div
                    v-if="menuOpen"
                    class="absolute right-0 top-12 z-50 w-56 overflow-hidden rounded-xl border border-frame bg-surface shadow-xl shadow-black/40"
                    role="menu"
                    aria-label="Menu da conta"
                >
                    <div class="border-b border-gold/15 px-4 py-3">
                        <p class="text-[11px] uppercase tracking-[0.22em] text-muted">Conectado como</p>
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
                                class="block w-full border-t border-gold/15 px-4 py-2.5 text-left text-sm text-muted transition-colors hover:bg-surface-2 hover:text-cream"
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
        class="fixed inset-x-0 bottom-0 z-40 flex items-stretch border-t border-gold/15 bg-surface/95 pb-[env(safe-area-inset-bottom)] backdrop-blur md:hidden"
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
            <!-- Curva dourada do portal sobre a seção ativa. -->
            <svg
                v-if="isSectionActive(section)"
                class="mnav-arc absolute inset-x-6 top-0 h-1.5 w-auto text-gold"
                viewBox="0 0 28 8"
                fill="none"
                preserveAspectRatio="none"
                aria-hidden="true"
            >
                <path d="M1 7C1 7 6 1 14 1C22 1 27 7 27 7" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" />
            </svg>
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
/* A curva da seção ativa surge com um fade suave. */
.mnav-arc {
    animation: mnav-arc-in 0.25s ease;
}
@keyframes mnav-arc-in {
    from { opacity: 0; }
    to { opacity: 1; }
}

/* Abertura do menu do avatar: fade + leve subida. */
.mnav-menu-enter-active,
.mnav-menu-leave-active {
    transition: opacity 0.15s ease, transform 0.15s ease;
}
.mnav-menu-enter-from,
.mnav-menu-leave-to {
    opacity: 0;
    transform: translateY(-4px);
}

@media (prefers-reduced-motion: reduce) {
    .mnav-arc {
        animation: none;
    }
    .mnav-menu-enter-active,
    .mnav-menu-leave-active {
        transition: none;
    }
    .mnav-menu-enter-from,
    .mnav-menu-leave-to {
        transform: none;
    }
}
</style>
