<script setup>
import { Head, Link } from '@inertiajs/vue3'
import PortalLogo from '@/Components/PortalLogo.vue'

defineProps({
    title: String,
})
</script>

<template>
    <Head :title="title" />
    <div class="min-h-screen bg-background flex flex-col">
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
                        :href="route('register')"
                        class="border border-gold text-gold px-4 py-1.5 rounded-lg hover:bg-gold/10 transition-colors"
                    >
                        Criar conta
                    </Link>
                </nav>
            </div>
        </header>

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
