<script setup>
import { computed } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import PortalLogo from '@/Components/PortalLogo.vue'
import MessageToast from '@/Components/MessageToast.vue'

/**
 * Shell dedicado da área de moderação (feat/moderator-shell). Header + sidebar
 * próprios, no lugar do AppLayout do app — dá ao back-office de Trust & Safety a
 * mesma "cara" dos painéis admin, sem a nav de membro/performer. Tokens do
 * design (gold/frame/muted/surface/cream). Só moderador/admin chegam aqui (gate
 * moderator.access no servidor).
 */
defineProps({
    title: { type: String, default: 'Moderação' },
})

const page = usePage()
const user = computed(() => page.props.auth?.user ?? null)

const nav = [
    { label: 'Visão geral', routeName: 'moderacao.overview', match: 'moderacao.overview' },
    { label: 'Denúncias', routeName: 'moderacao.reports.index', match: 'moderacao.reports.*' },
    { label: 'Fotos de membro', routeName: 'moderacao.member-photos.index', match: 'moderacao.member-photos.*' },
    { label: 'Intros de voz', routeName: 'moderacao.voice-intros.index', match: 'moderacao.voice-intros.*' },
]

function isActive(match) {
    try {
        return route().current(match)
    } catch (e) {
        return false
    }
}

function logout() {
    router.post(route('logout'))
}
</script>

<template>
    <div class="min-h-screen bg-background text-cream flex flex-col">
        <Head :title="title" />

        <!-- Header -->
        <header class="flex-shrink-0 border-b border-frame bg-surface">
            <div class="flex items-center gap-4 px-5 py-3">
                <Link :href="route('moderacao.overview')" class="flex items-center gap-2 no-underline">
                    <PortalLogo :size="28" :show-text="true" />
                </Link>
                <span class="hidden sm:inline h-6 w-px bg-frame"></span>
                <span class="hidden sm:inline rounded-full border border-gold/50 bg-gold/10 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.08em] text-gold">
                    Moderação
                </span>

                <div class="flex-1"></div>

                <div v-if="user" class="flex items-center gap-3">
                    <span class="hidden sm:flex flex-col text-right leading-tight">
                        <span class="text-xs font-semibold text-cream">{{ user.email }}</span>
                        <span class="text-[10px] text-muted">Trust &amp; Safety</span>
                    </span>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border border-frame px-3 py-2 text-xs font-semibold text-muted transition-colors hover:border-gold/60 hover:text-gold"
                        @click="logout"
                    >
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><path d="M16 17l5-5-5-5"></path><path d="M21 12H9"></path></svg>
                        Sair
                    </button>
                </div>
            </div>

            <!-- Nav horizontal (mobile) -->
            <nav class="md:hidden flex gap-1 overflow-x-auto border-t border-frame px-3 py-2">
                <Link
                    v-for="item in nav"
                    :key="item.routeName"
                    :href="route(item.routeName)"
                    :class="[
                        'whitespace-nowrap rounded-lg px-3 py-2 text-sm no-underline transition-colors',
                        isActive(item.match) ? 'bg-surface-2 text-gold font-semibold' : 'text-muted hover:text-cream',
                    ]"
                >
                    {{ item.label }}
                </Link>
            </nav>
        </header>

        <div class="flex flex-1 min-h-0">
            <!-- Sidebar (desktop) -->
            <aside class="hidden md:flex w-56 flex-shrink-0 flex-col gap-1 border-r border-frame bg-surface/60 px-3 py-5">
                <span class="px-3 pb-1 text-[10px] font-bold uppercase tracking-[0.15em] text-muted">Filas</span>
                <Link
                    v-for="item in nav"
                    :key="item.routeName"
                    :href="route(item.routeName)"
                    :class="[
                        'rounded-lg px-3 py-2 text-sm no-underline transition-colors',
                        isActive(item.match) ? 'bg-surface-2 text-gold font-semibold' : 'text-muted hover:bg-white/5 hover:text-cream',
                    ]"
                >
                    {{ item.label }}
                </Link>
            </aside>

            <!-- Conteúdo -->
            <main class="flex-1 min-w-0 overflow-y-auto">
                <div v-if="page.props.flash?.success" class="border-b border-success/30 bg-success/10 px-6 py-3 text-center text-sm text-success">
                    {{ page.props.flash.success }}
                </div>
                <div v-if="page.props.flash?.error" class="border-b border-danger/30 bg-danger/10 px-6 py-3 text-center text-sm text-danger">
                    {{ page.props.flash.error }}
                </div>
                <div v-if="page.props.flash?.info" class="border-b border-sky-500/30 bg-sky-500/10 px-6 py-3 text-center text-sm text-sky-300">
                    {{ page.props.flash.info }}
                </div>

                <slot />
            </main>
        </div>

        <MessageToast />
    </div>
</template>
