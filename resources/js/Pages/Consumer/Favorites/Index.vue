<script setup>
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PerformerCard from '@/Components/PerformerCard.vue'
import PortalLogo from '@/Components/PortalLogo.vue'

/**
 * Favoritos do membro — "salvar para ver depois".
 *
 * Mesmo card do catálogo de propósito: a tela é a mesma vitrine, filtrada pela
 * escolha do membro. O coração já chega preenchido (o controller marca a página
 * inteira), e desfavoritar daqui recarrega só `performers` — o card some da
 * grade sem recarregar a página.
 *
 * A tela é do MEMBRO e só dele: não existe contrapartida do lado da performer,
 * e nada que se acrescente aqui pode passar a existir lá.
 */
defineProps({
    performers: { type: Object, required: true },
})
</script>

<template>
    <AppLayout title="Salvos">
        <div class="max-w-6xl mx-auto px-6 py-10 space-y-8">
            <div class="space-y-2">
                <h1 class="font-serif text-3xl text-cream">Salvos</h1>
                <!-- A copy diz o que a feature garante, e nada além disso.
                     "Só você vê" é literalmente verdade: não há contador, lista
                     nem notificação do outro lado (ver FavoriteService). -->
                <p class="text-sm text-muted">
                    Perfis que você guardou para ver depois. Só você vê esta lista —
                    a performer não é avisada.
                </p>
            </div>

            <!-- Empty state -->
            <div
                v-if="performers.data.length === 0"
                class="flex flex-col items-center justify-center text-center py-24 gap-4"
            >
                <PortalLogo :size="72" :show-text="false" />
                <p class="font-serif text-2xl text-cream">Você ainda não salvou nenhum perfil.</p>
                <p class="text-muted text-sm max-w-sm">
                    Toque no coração de um perfil no catálogo para guardá-lo aqui.
                </p>
                <Link
                    :href="route('catalog')"
                    class="mt-2 rounded-lg border border-frame px-4 py-2 text-sm text-cream no-underline transition-colors hover:border-gold/40 hover:text-gold"
                >
                    Explorar o catálogo
                </Link>
            </div>

            <template v-else>
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-5">
                    <PerformerCard
                        v-for="performer in performers.data"
                        :key="performer.slug"
                        :performer="performer"
                        :favorite-reload-only="['performers']"
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
        </div>
    </AppLayout>
</template>
