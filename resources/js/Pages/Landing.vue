<script setup>
import { Link, usePage } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import AgeGateModal from '@/Components/AgeGateModal.vue'
import Button from '@/Components/Button.vue'
import PortalLogo from '@/Components/PortalLogo.vue'

const page = usePage()

const features = [
    {
        icon: '✦',
        title: 'Performers verificados',
        description: 'Todos os criadores passam por verificação de identidade e idade antes de publicar qualquer conteúdo.',
    },
    {
        icon: '◈',
        title: 'Pagamentos via PIX',
        description: 'Compre tokens com PIX instantâneo. Gorjetas chegam ao criador em segundos, com split automático.',
    },
    {
        icon: '◉',
        title: 'Privacidade total',
        description: 'Seus dados são criptografados e nunca compartilhados. Transações discretas, sem julgamentos.',
    },
]
</script>

<template>
    <GuestLayout title="Portal verificado de conteúdo adulto">
        <AgeGateModal :age-accepted="page.props.ageAccepted" />

        <!-- Hero -->
        <section class="relative flex flex-col items-center justify-center min-h-[80vh] px-6 text-center overflow-hidden">
            <!-- Background glow -->
            <div class="absolute inset-0 bg-[radial-gradient(ellipse_60%_60%_at_50%_40%,rgba(201,162,75,0.06),transparent)] pointer-events-none" />

            <div class="relative z-10 flex flex-col items-center gap-8 max-w-2xl mx-auto animate-fade-in">
                <PortalLogo :size="96" />

                <div class="space-y-4">
                    <h1 class="font-serif text-5xl md:text-6xl text-cream leading-tight">
                        O Portal.<br>
                        <em class="text-gold not-italic">Verificado, real.</em>
                    </h1>
                    <p class="text-muted text-lg leading-relaxed max-w-lg mx-auto">
                        Conteúdo adulto premium com performers verificados, pagamentos via PIX e total privacidade.
                    </p>
                </div>

                <div class="flex flex-col sm:flex-row gap-4">
                    <Link :href="route('register')">
                        <Button variant="primary" size="lg">
                            Criar conta gratuita
                        </Button>
                    </Link>
                    <Link :href="route('login')">
                        <Button variant="ghost" size="lg">
                            Já tenho conta
                        </Button>
                    </Link>
                </div>
            </div>

            <!-- Decorative bottom gradient -->
            <div class="absolute bottom-0 left-0 right-0 h-32 bg-gradient-to-t from-background to-transparent pointer-events-none" />
        </section>

        <!-- Value props -->
        <section class="py-24 px-6">
            <div class="max-w-4xl mx-auto">
                <h2 class="font-serif text-3xl text-cream text-center mb-16">
                    Por que o <span class="text-gold">Limen</span>
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    <div
                        v-for="item in features"
                        :key="item.title"
                        class="bg-surface border border-frame rounded-xl p-6 space-y-3"
                    >
                        <div class="text-gold text-2xl">{{ item.icon }}</div>
                        <h3 class="font-serif text-lg text-cream">{{ item.title }}</h3>
                        <p class="text-muted text-sm leading-relaxed">{{ item.description }}</p>
                    </div>
                </div>
            </div>
        </section>
    </GuestLayout>
</template>

<script>
export default {
    data: () => ({
        features: [
            {
                icon: '✦',
                title: 'Performers verificados',
                description: 'Todos os criadores passam por verificação de identidade e idade antes de publicar qualquer conteúdo.',
            },
            {
                icon: '◈',
                title: 'Pagamentos via PIX',
                description: 'Compre tokens com PIX instantâneo. Gorjetas chegam ao criador em segundos, com split automático.',
            },
            {
                icon: '◉',
                title: 'Privacidade total',
                description: 'Seus dados são criptografados e nunca compartilhados. Transações discretas, sem julgamentos.',
            },
        ],
    }),
}
</script>

<style scoped>
@keyframes fade-in {
    from { opacity: 0; transform: translateY(16px); }
    to { opacity: 1; transform: translateY(0); }
}
.animate-fade-in {
    animation: fade-in 0.8s ease-out both;
}
</style>
