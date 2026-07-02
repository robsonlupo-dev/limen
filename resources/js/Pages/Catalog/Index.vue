<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import FilterPanel from '@/Components/Catalog/FilterPanel.vue'
import PerformerCard from '@/Components/PerformerCard.vue'
import PortalLogo from '@/Components/PortalLogo.vue'
import Modal from '@/Components/Modal.vue'

const props = defineProps({
    performers: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    currentWorld: { type: String, default: 'mulheres' },
    userWorld: { type: String, default: null },
})

const worlds = [
    { value: 'mulheres', label: 'Mulheres' },
    { value: 'homens', label: 'Homens' },
    { value: 'casais', label: 'Casais' },
    { value: 'trans', label: 'Trans' },
    { value: 'gls', label: 'GLS' },
    { value: 'swing', label: 'Swing' },
]

const worldLabel = (v) => worlds.find((w) => w.value === v)?.label ?? v

const loading = ref(false)
const showWorldPicker = ref(false)
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
    showWorldPicker.value = false
    router.patch(route('preferences.update'), { preferred_world: value }, { preserveScroll: true })
}
</script>

<template>
    <AppLayout title="Catálogo">
        <div class="max-w-6xl mx-auto px-6 py-10 space-y-8">
            <div class="flex items-start justify-between gap-4">
                <div class="space-y-2">
                    <h1 class="font-serif text-4xl text-cream">Catálogo</h1>
                    <p class="text-muted text-sm">Performers verificados, ao vivo agora ou disponíveis para conteúdo.</p>
                    <p class="text-xs text-muted flex items-center gap-1.5">
                        🌐 Mundo: <span class="text-gold">{{ worldLabel(currentWorld) }}</span>
                    </p>
                </div>
                <button
                    type="button"
                    class="shrink-0 text-xs text-muted hover:text-gold transition-colors border border-frame rounded-lg px-3 py-2"
                    @click="showWorldPicker = true"
                >
                    🌐 Mudar Mundo
                </button>
            </div>

            <FilterPanel :filters="filters" />

            <!-- Skeleton loading -->
            <div v-if="loading" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-5">
                <div v-for="n in 10" :key="n" class="rounded-xl border border-frame bg-surface overflow-hidden animate-pulse">
                    <div class="aspect-[4/3] bg-surface-2" />
                    <div class="p-4 space-y-2">
                        <div class="h-4 w-3/4 bg-surface-2 rounded" />
                        <div class="h-3 w-1/2 bg-surface-2 rounded" />
                        <div class="h-8 w-full bg-surface-2 rounded mt-3" />
                    </div>
                </div>
            </div>

            <!-- Empty state -->
            <div v-else-if="performers.data.length === 0" class="flex flex-col items-center justify-center text-center py-24 gap-4">
                <PortalLogo :size="72" :show-text="false" />
                <p class="font-serif text-2xl text-cream">O Portal ainda está abrindo suas portas.</p>
                <p class="text-muted text-sm max-w-sm">
                    Em breve, os primeiros performers deste mundo estarão aqui.
                </p>
            </div>

            <!-- Grid -->
            <template v-else>
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-5">
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

        <!-- World picker modal -->
        <Modal :show="showWorldPicker" max-width="md" @close="showWorldPicker = false">
            <h2 class="font-serif text-2xl text-cream mb-1">Escolha seu mundo</h2>
            <p class="text-muted text-sm mb-6">Você verá performers apenas do mundo selecionado.</p>
            <div class="grid grid-cols-2 gap-3">
                <button
                    v-for="world in worlds"
                    :key="world.value"
                    type="button"
                    class="rounded-xl border px-4 py-4 text-sm transition-colors"
                    :class="currentWorld === world.value
                        ? 'border-gold text-gold bg-gold/10'
                        : 'border-frame text-muted hover:border-gold/50'"
                    @click="selectWorld(world.value)"
                >
                    {{ world.label }}
                </button>
            </div>
        </Modal>
    </AppLayout>
</template>
