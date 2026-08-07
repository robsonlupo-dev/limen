<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import FilterPanel from '@/Components/Catalog/FilterPanel.vue'
import PerformerCard from '@/Components/PerformerCard.vue'
import PortalLogo from '@/Components/PortalLogo.vue'
import Modal from '@/Components/Modal.vue'
import OnboardingTutorial from '@/Components/OnboardingTutorial.vue'
import NowStrip from '@/Components/NowStrip.vue'
import StoryViewer from '@/Components/StoryViewer.vue'
import { getJson } from '@/lib/http'

const props = defineProps({
    performers: { type: Object, required: true },
    // Trilha "Agora": lives ativas do mundo atual (vazio com a feature off).
    lives: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    currentWorld: { type: String, default: 'mulheres' },
    userWorld: { type: String, default: null },
    // Buscas salvas do membro (Sprint 12). Só o catálogo autenticado as recebe.
    savedSearches: { type: Array, default: () => [] },
})

// Slot de Destaque (Boost): a PRIMEIRA performer boostada da página vira um card
// largo (2 colunas) no topo do grid, e sai da grade normal para não duplicar. A
// POSIÇÃO já vem do servidor (scopePublicCatalog põe boostados primeiro); aqui só
// promovemos o primeiro à vitrine larga. Sem boost ativo → sem slot, grid normal.
const featuredPerformer = computed(() => props.performers.data.find((p) => p.is_boosted) ?? null)
const gridPerformers = computed(() =>
    featuredPerformer.value
        ? props.performers.data.filter((p) => p.slug !== featuredPerformer.value.slug)
        : props.performers.data,
)

// Feed de Stories (Sprint 13). Buscado por FETCH no mount, não como prop: o
// feedFor roda canView por story (O(stories) em queries) e servi-lo junto do
// catálogo quebraria a garantia de N+1 do pontinho. O round-trip separado o
// mantém fora do caminho crítico; o carrossel some até chegar (v-if no feed).
// Só para membro — a rota `stories.feed` é role:consumer, e performer/admin que
// navegam o catálogo receberiam 403.
const page = usePage()
const isConsumer = page.props.auth?.user?.role === 'consumer'
const feed = ref([])
const viewerOpen = ref(false)
const viewerStart = ref(0)

async function loadFeed() {
    if (!isConsumer) return
    try {
        const data = await getJson(route('stories.feed'))
        feed.value = data.performers ?? []
    } catch {
        // Sem rede / sessão: mantém o estado atual, o próximo load reconcilia.
    }
}

function openViewer(index) {
    viewerStart.value = index
    viewerOpen.value = true
}

// Trilha "Agora": clique num círculo de live entra na transmissão. O gate de
// feature/permissão vive na rota (LiveViewController) — o front só navega.
function enterLive(slug) {
    router.visit(route('live.show', slug))
}

// Ao fechar o viewer, recarrega para o anel dourado→cinza acompanhar as views
// recém-gravadas (para Ghost Mode nada é gravado e o anel continua dourado — é
// o perk, ver § dos Stories no CLAUDE.md).
function closeViewer() {
    viewerOpen.value = false
    loadFeed()
}

const worlds = [
    { value: 'mulheres', label: 'Mulheres' },
    { value: 'homens', label: 'Homens' },
    { value: 'casais', label: 'Casais' },
    { value: 'trans', label: 'Trans' },
]

const worldLabel = (v) => worlds.find((w) => w.value === v)?.label ?? v

const loading = ref(false)
const showWorldPicker = ref(false)
let removeStart, removeFinish

// First-run tutorial. Read once into local state (not a computed over the prop)
// so dismissing it closes the overlay immediately — the cookie the component
// writes is client-side, and the shared `tutorialSeen` prop only catches up on
// the next server response. (`page` já declarado acima, para o feed de Stories.)
const showTutorial = ref(
    !page.props.tutorialSeen && page.props.auth?.user?.role === 'consumer'
)

onMounted(() => {
    loadFeed()
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
        <OnboardingTutorial v-if="showTutorial" @close="showTutorial = false" />

        <div class="bg-limen-bg">
        <div class="max-w-screen-2xl mx-auto px-6 py-10 space-y-8">
            <div class="flex items-start justify-between gap-4">
                <div class="space-y-2">
                    <h1 class="font-serif text-4xl text-limen-ink">Catálogo</h1>
                    <p class="text-limen-ink-soft text-sm">Performers verificados, ao vivo agora ou disponíveis para conteúdo.</p>
                    <p class="text-xs text-limen-ink-mute flex items-center gap-1.5">
                        🌐 Mundo: <span class="text-limen-gold">{{ worldLabel(currentWorld) }}</span>
                    </p>
                </div>
                <button
                    type="button"
                    class="shrink-0 text-xs text-limen-ink-mute hover:text-limen-gold transition-colors border border-limen-line rounded-lg px-3 py-2"
                    @click="showWorldPicker = true"
                >
                    🌐 Mudar Mundo
                </button>
            </div>

            <!-- Trilha "Agora" (redesign): lives (anel vermelho) + stories (anel
                 dourado) no topo do catálogo. Some inteira quando não há nem live
                 nem story. Clique em live entra na transmissão; em story abre o
                 viewer. -->
            <NowStrip :lives="lives" :feed="feed" @open-live="enterLive" @open-story="openViewer" />

            <FilterPanel :filters="filters" :saved-searches="savedSearches" :can-save="true" />

            <!-- Skeleton loading -->
            <div v-if="loading" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3.5">
                <div v-for="n in 8" :key="n" class="rounded-xl bg-limen-surface-2 aspect-[3/4] animate-pulse" />
            </div>

            <!-- Empty state -->
            <div v-else-if="performers.data.length === 0" class="flex flex-col items-center justify-center text-center py-24 gap-4">
                <PortalLogo :size="72" :show-text="false" />
                <p class="font-serif text-2xl text-limen-ink">O Portal ainda está abrindo suas portas.</p>
                <p class="text-limen-ink-soft text-sm max-w-sm">
                    Em breve, os primeiros performers deste mundo estarão aqui.
                </p>
            </div>

            <!-- Grid -->
            <template v-else>
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3.5">
                    <!-- Slot de Destaque (Boost): card largo (2 colunas) no topo,
                         só quando há performer boostada. Sai do grid normal abaixo
                         para não duplicar. -->
                    <div v-if="featuredPerformer" class="col-span-2">
                        <PerformerCard :performer="featuredPerformer" featured />
                    </div>

                    <PerformerCard
                        v-for="performer in gridPerformers"
                        :key="performer.slug"
                        :performer="performer"
                    />
                </div>

                <!-- Pagination -->
                <div v-if="performers.meta.links.length > 3" class="flex flex-wrap justify-center gap-2 pt-4">
                    <template v-for="(link, i) in performers.meta.links" :key="i">
                        <span
                            v-if="!link.url"
                            class="px-3 py-1.5 text-sm text-limen-ink-mute/50"
                            v-html="link.label"
                        />
                        <Link
                            v-else
                            :href="link.url"
                            preserve-scroll
                            :class="[
                                'px-3 py-1.5 rounded-lg text-sm transition-colors',
                                link.active
                                    ? 'bg-limen-gold text-limen-bg'
                                    : 'text-limen-ink-mute hover:text-limen-ink border border-limen-line',
                            ]"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </template>
        </div>
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

        <!-- Visualizador fullscreen dos Stories (Sprint 13). Recarrega o feed ao
             fechar para o anel refletir as views recém-registradas. -->
        <StoryViewer
            v-if="viewerOpen"
            :groups="feed"
            :start-group-index="viewerStart"
            @close="closeViewer"
        />
    </AppLayout>
</template>
