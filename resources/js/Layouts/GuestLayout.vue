<script setup>
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import PortalLogo from '@/Components/PortalLogo.vue'
import AgeGateModal from '@/Components/AgeGateModal.vue'
import IntroAnimation from '@/Components/IntroAnimation.vue'

defineProps({
    title: String,
})

const page = usePage()

// Single source of truth for whether the age gate / intro appear. A logged-in
// visitor already declared age at registration, so both are suppressed. Guests
// see each at most once per device, driven by the server-read cookie flags
// (ageConfirmed / introSeen) shared in HandleInertiaRequests. Because these are
// v-if guards, the components never even mount once the cookie is present.
const isLoggedIn = computed(() => Boolean(page.props.auth?.user))
const showAgeGate = computed(() => !isLoggedIn.value && !page.props.ageConfirmed)
const showIntro = computed(() => !isLoggedIn.value && !page.props.introSeen)
</script>

<template>
    <Head :title="title" />
    <div class="min-h-screen bg-background flex flex-col">
        <IntroAnimation v-if="showIntro" />
        <AgeGateModal v-if="showAgeGate" />

        <!-- Header -->
        <header class="border-b border-frame/50">
            <div class="max-w-6xl mx-auto px-6 py-4 flex items-center justify-between">
                <Link :href="route('landing')" class="flex items-center gap-3 no-underline">
                    <PortalLogo :size="32" :show-text="false" />
                    <span class="font-serif text-xl tracking-widest text-gold uppercase">Limen</span>
                </Link>
                <nav class="flex items-center gap-6 text-sm text-muted">
                    <Link :href="route('login')" class="hover:text-cream transition-colors">Entrar</Link>
                    <Link
                        :href="route('entrada')"
                        class="border border-gold text-gold px-4 py-1.5 rounded-lg hover:bg-gold/10 transition-colors"
                    >
                        Criar conta
                    </Link>
                </nav>
            </div>
        </header>

        <!-- Flash messages -->
        <div v-if="page.props.flash?.success" class="bg-success/10 border-b border-success/30 px-6 py-3 text-sm text-success text-center">
            {{ page.props.flash.success }}
        </div>
        <div v-if="page.props.flash?.error" class="bg-danger/10 border-b border-danger/30 px-6 py-3 text-sm text-danger text-center">
            {{ page.props.flash.error }}
        </div>

        <!-- Content -->
        <main class="flex-1">
            <slot />
        </main>

        <!-- Footer -->
        <footer class="border-t border-frame/50 py-8">
            <div class="max-w-6xl mx-auto px-6 text-center text-xs text-muted space-y-1">
                <p>© {{ new Date().getFullYear() }} Limen. Todos os direitos reservados.</p>
                <p class="text-gold/70">+18 · Conteúdo adulto verificado · Proibido para menores de 18 anos.</p>
            </div>
        </footer>
    </div>
</template>
