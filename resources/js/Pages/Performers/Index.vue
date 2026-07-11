<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import PublicPerformerCard from '@/Components/PublicPerformerCard.vue'
import PortalLogo from '@/Components/PortalLogo.vue'
import { WORLD_FILTERS } from '@/lib/worlds'

const props = defineProps({
    performers: { type: Object, required: true },
    filters: { type: Object, default: () => ({ mundo: null }) },
})

const loading = ref(false)
let removeStart, removeFinish

onMounted(() => {
    removeStart = router.on('start', () => (loading.value = true))
    removeFinish = router.on('finish', () => (loading.value = false))
})

onUnmounted(() => {
    removeStart?.()
    removeFinish?.()
})

function selectWorld(value) {
    // Reactive filter: patches only the catalog props and reflects the choice in
    // the URL query string (/performers?mundo=mulheres) without a full reload.
    router.get(
        route('performers.public'),
        value ? { mundo: value } : {},
        { preserveScroll: true, preserveState: true, only: ['performers', 'filters'] },
    )
}

const isActive = (value) => (props.filters.mundo ?? null) === value
</script>

<template>
    <Head>
        <title>Performers verificadas · Limen</title>
        <meta name="description" content="Descubra performers verificadas no Limen. Conteúdo adulto premium, privacidade total. Crie sua conta para interagir." />
        <meta property="og:title" content="Performers verificadas · Limen" />
        <meta property="og:description" content="Descubra performers verificadas no Limen. Conteúdo adulto premium, privacidade total." />
        <meta property="og:type" content="website" />
    </Head>

    <GuestLayout title="Performers">
        <div class="max-w-6xl mx-auto px-6 py-10 space-y-8">
            <div class="space-y-2 text-center">
                <h1 class="font-serif text-4xl text-cream">Performers verificadas</h1>
                <p class="text-muted text-sm max-w-lg mx-auto">
                    Conheça quem faz parte do Portal. Crie sua conta para seguir, conversar e enviar gorjetas.
                </p>
            </div>

            <!-- World filter pills -->
            <div class="flex flex-wrap justify-center gap-2">
                <button
                    v-for="world in WORLD_FILTERS"
                    :key="world.value ?? 'todos'"
                    type="button"
                    class="rounded-full border px-4 py-1.5 text-sm transition-colors flex items-center gap-1.5"
                    :class="isActive(world.value)
                        ? 'border-gold text-gold bg-gold/10'
                        : 'border-frame text-muted hover:border-gold/50 hover:text-cream'"
                    @click="selectWorld(world.value)"
                >
                    <span aria-hidden="true">{{ world.icon }}</span>
                    {{ world.label }}
                </button>
            </div>

            <!-- Skeleton loading -->
            <div v-if="loading" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-5">
                <div v-for="n in 8" :key="n" class="rounded-xl border border-frame bg-surface overflow-hidden animate-pulse">
                    <div class="aspect-[4/3] bg-surface-2" />
                    <div class="p-4 space-y-2">
                        <div class="h-4 w-3/4 bg-surface-2 rounded" />
                        <div class="h-3 w-1/2 bg-surface-2 rounded" />
                    </div>
                </div>
            </div>

            <!-- Empty state -->
            <div v-else-if="performers.data.length === 0" class="flex flex-col items-center justify-center text-center py-24 gap-4">
                <PortalLogo :size="72" :show-text="false" />
                <p class="font-serif text-2xl text-cream">O Portal ainda está abrindo suas portas.</p>
                <p class="text-muted text-sm max-w-sm">
                    Em breve, as primeiras performers deste mundo estarão aqui.
                </p>
            </div>

            <!-- Grid -->
            <template v-else>
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-5">
                    <PublicPerformerCard
                        v-for="performer in performers.data"
                        :key="performer.slug"
                        :performer="performer"
                    />
                </div>

                <!-- Pagination -->
                <div v-if="performers.meta.links.length > 3" class="flex flex-wrap justify-center gap-2 pt-4">
                    <template v-for="(link, i) in performers.meta.links" :key="i">
                        <span
                            v-if="!link.url"
                            class="px-3 py-1.5 text-sm text-muted/50"
                            v-html="link.label"
                        />
                        <Link
                            v-else
                            :href="link.url"
                            preserve-scroll
                            :class="[
                                'px-3 py-1.5 rounded-lg text-sm transition-colors',
                                link.active
                                    ? 'bg-gold text-background'
                                    : 'text-muted hover:text-cream border border-frame',
                            ]"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </template>

            <!-- Bottom CTA -->
            <div class="rounded-2xl border border-gold/30 bg-gradient-to-br from-gold/10 to-transparent p-8 text-center space-y-3">
                <h2 class="font-serif text-2xl text-cream">Crie sua conta para interagir</h2>
                <p class="text-muted text-sm max-w-md mx-auto">
                    Seguir performers, enviar gorjetas e desbloquear conteúdo exige uma conta verificada. É rápido e discreto.
                </p>
                <Link
                    :href="route('entrada')"
                    class="inline-block no-underline border border-gold text-gold px-6 py-2.5 rounded-lg hover:bg-gold/10 transition-colors"
                >
                    Criar conta
                </Link>
            </div>
        </div>
    </GuestLayout>
</template>
