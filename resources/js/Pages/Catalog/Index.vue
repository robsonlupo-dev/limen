<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import FilterBar from '@/Components/FilterBar.vue'
import PerformerCard from '@/Components/PerformerCard.vue'

const props = defineProps({
    performers: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
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

function clearAllFilters() {
    router.get(route('catalog'), {}, { preserveScroll: true })
}
</script>

<template>
    <AppLayout title="Catálogo">
        <div class="max-w-6xl mx-auto px-6 py-10 space-y-8">
            <div class="space-y-2">
                <h1 class="font-serif text-4xl text-cream">Catálogo</h1>
                <p class="text-muted text-sm">Performers verificados, ao vivo agora ou disponíveis para conteúdo.</p>
            </div>

            <FilterBar :filters="filters" />

            <!-- Skeleton loading -->
            <div v-if="loading" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-5">
                <div v-for="n in 8" :key="n" class="rounded-xl border border-frame bg-surface overflow-hidden animate-pulse">
                    <div class="aspect-[4/3] bg-surface-2" />
                    <div class="p-4 space-y-2">
                        <div class="h-4 w-3/4 bg-surface-2 rounded" />
                        <div class="h-3 w-1/2 bg-surface-2 rounded" />
                        <div class="h-8 w-full bg-surface-2 rounded mt-3" />
                    </div>
                </div>
            </div>

            <!-- Empty state -->
            <div v-else-if="performers.data.length === 0" class="flex flex-col items-center justify-center text-center py-24 gap-3">
                <p class="font-serif text-2xl text-cream">Nenhum performer encontrado.</p>
                <p class="text-muted text-sm max-w-sm">
                    Tente remover alguns filtros ou buscar por outro termo para ver mais resultados.
                </p>
                <button
                    type="button"
                    class="mt-2 text-sm text-gold hover:text-gold-light transition-colors"
                    @click="clearAllFilters"
                >
                    Limpar filtros
                </button>
            </div>

            <!-- Grid -->
            <template v-else>
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-5">
                    <PerformerCard
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
        </div>
    </AppLayout>
</template>
