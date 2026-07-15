<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import PortalLogo from '@/Components/PortalLogo.vue'
import Modal from '@/Components/Modal.vue'
import Button from '@/Components/Button.vue'

defineProps({
    title: String,
})

const page = usePage()

const isPerformer = computed(() => page.props.auth.user?.role === 'performer')
const isConsumer = computed(() => page.props.auth.user?.role === 'consumer')
// Espelha o gate 'performer-active' (AppServiceProvider): performer ainda em
// onboarding não vê links que o gate recusaria com 403.
const isActivePerformer = computed(() => isPerformer.value && page.props.auth.user?.status === 'active')
const dashboardRoute = computed(() =>
    isActivePerformer.value ? route('performer.dashboard') : route('performer.onboarding'),
)

const showLogoutConfirm = ref(false)

function logout() {
    showLogoutConfirm.value = false
    router.post(route('logout'))
}
</script>

<template>
    <Head :title="title" />
    <div class="min-h-screen bg-background flex flex-col">
        <!-- Header -->
        <header class="border-b border-frame/50">
            <div class="max-w-6xl mx-auto px-6 py-4 flex items-center justify-between">
                <Link :href="route('catalog')" class="flex items-center gap-3 no-underline">
                    <PortalLogo :size="32" :show-text="false" />
                    <span class="font-serif text-xl tracking-widest text-gold uppercase">Limen</span>
                </Link>
                <nav class="flex items-center gap-6 text-sm text-muted">
                    <Link
                        v-if="isPerformer"
                        :href="dashboardRoute"
                        class="text-gold/80 hover:text-gold transition-colors no-underline"
                    >
                        Meu Painel
                    </Link>
                    <template v-if="isActivePerformer">
                        <Link
                            :href="route('performer.followers')"
                            class="text-gold/80 hover:text-gold transition-colors no-underline"
                        >
                            Seguidores
                        </Link>
                        <Link
                            :href="route('performer.payouts.index')"
                            class="text-gold/80 hover:text-gold transition-colors no-underline"
                        >
                            Saques
                        </Link>
                    </template>
                    <template v-if="isConsumer">
                        <Link
                            :href="route('consumer.dashboard')"
                            class="text-gold/80 hover:text-gold transition-colors no-underline"
                        >
                            Meu Painel
                        </Link>
                        <Link
                            :href="route('interests.index')"
                            class="text-gold/80 hover:text-gold transition-colors no-underline"
                        >
                            Interesses
                        </Link>
                        <Link
                            :href="route('wallet.index')"
                            class="text-gold/80 hover:text-gold transition-colors no-underline"
                        >
                            Carteira
                        </Link>
                    </template>
                    <span class="text-cream">{{ page.props.auth.user?.name }}</span>
                    <button
                        class="hover:text-cream transition-colors"
                        @click="showLogoutConfirm = true"
                    >
                        Sair
                    </button>
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
                <p class="text-gold/70">+18 · Conteúdo adulto verificado.</p>
                <p v-if="page.props.auth.user?.role === 'consumer'" class="pt-2">
                    <a href="/cadastro?tipo=performer" class="text-gold/60 hover:text-gold transition-colors">
                        Quer ser Performer? Clique aqui
                    </a>
                </p>
            </div>
        </footer>

        <!-- Logout confirmation -->
        <Modal :show="showLogoutConfirm" max-width="sm" @close="showLogoutConfirm = false">
            <h2 class="font-serif text-xl text-cream mb-2">Sair do Portal?</h2>
            <p class="text-muted text-sm mb-6">Tem certeza que quer encerrar sua sessão?</p>
            <div class="flex gap-3 justify-end">
                <Button variant="ghost" size="sm" @click="showLogoutConfirm = false">Cancelar</Button>
                <Button variant="danger" size="sm" @click="logout">Sair</Button>
            </div>
        </Modal>
    </div>
</template>
