<script setup>
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import Button from '@/Components/Button.vue'
import { postJson } from '@/lib/http'

// "Salvos" do membro (roadmap social, Onda 3 §3.1). Lista PRIVADA de peças que o
// membro salvou — a performer nunca sabe. Cada item chega resolvido pelo servidor
// para ESTE membro (ContentPresenter::feedItem) + saved:true.
const props = defineProps({
    saved: { type: Object, required: true },
})

const LEVEL_LABELS = {
    open: 'Aberto',
    premium: 'Premium',
    exclusive: 'Exclusivo',
    fc_only: 'FC Only',
}

const items = ref(props.saved.data.map((c) => ({ ...c })))
const currentPage = ref(props.saved.current_page)
const hasMore = ref(props.saved.has_more)
const loadingMore = ref(false)

function relativeDate(iso) {
    if (!iso) return ''
    const secs = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 1000))
    if (secs < 60) return 'agora'
    const mins = Math.floor(secs / 60)
    if (mins < 60) return `há ${mins} min`
    const hrs = Math.floor(mins / 60)
    if (hrs < 24) return `há ${hrs} h`
    const days = Math.floor(hrs / 24)
    if (days < 7) return `há ${days} d`
    const weeks = Math.floor(days / 7)
    if (weeks < 5) return `há ${weeks} sem`
    const months = Math.floor(days / 30)
    if (months < 12) return `há ${months} m`
    return `há ${Math.floor(days / 365)} a`
}

function loadMore() {
    if (loadingMore.value || !hasMore.value) return
    loadingMore.value = true
    router.get(
        route('saved.index'),
        { page: currentPage.value + 1 },
        {
            only: ['saved'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: (page) => {
                const s = page.props.saved
                items.value.push(...s.data.map((c) => ({ ...c })))
                currentPage.value = s.current_page
                hasMore.value = s.has_more
            },
            onFinish: () => {
                loadingMore.value = false
            },
        },
    )
}

// Desafixar dos salvos: remove da lista na hora (esta tela É a lista de salvos).
const removingId = ref(null)
async function unsave(item) {
    if (removingId.value) return
    removingId.value = item.id
    try {
        await postJson(route('content.save.toggle', item.id), { on: false })
        items.value = items.value.filter((c) => c.id !== item.id)
    } catch {
        // Silencioso: mantém o item se falhar.
    } finally {
        removingId.value = null
    }
}

// Desbloqueio (reusa content.unlock — mesma da galeria/feed): um salvo cujo acesso
// expirou volta a aparecer bloqueado e pode ser desbloqueado de novo aqui.
const selected = ref(null)
const unlocking = ref(false)
const error = ref('')

function openConfirm(item) {
    selected.value = item
    error.value = ''
}

function closeConfirm() {
    if (unlocking.value) return
    selected.value = null
    error.value = ''
}

async function confirmUnlock() {
    if (unlocking.value || !selected.value) return
    unlocking.value = true
    error.value = ''
    try {
        const data = await postJson(route('content.unlock', selected.value.id))
        const idx = items.value.findIndex((c) => c.id === selected.value.id)
        if (idx !== -1 && data.content) {
            items.value[idx] = { ...items.value[idx], ...data.content }
        }
        selected.value = null
    } catch (e) {
        error.value =
            e.status === 422 && e.data?.reason === 'insufficient_balance'
                ? 'Saldo insuficiente. Compre tokens na sua carteira.'
                : (e.data?.message ?? 'Não foi possível desbloquear este conteúdo.')
    } finally {
        unlocking.value = false
    }
}
</script>

<template>
    <AppLayout title="Salvos">
        <div class="bg-limen-bg min-h-screen">
            <div class="max-w-2xl mx-auto px-6 py-10 space-y-6">
                <div class="space-y-1">
                    <h1 class="font-serif text-4xl text-limen-ink">Salvos</h1>
                    <p class="text-limen-ink-mute text-sm">Conteúdo que você guardou para ver depois. Só você vê esta lista.</p>
                </div>

                <div
                    v-if="!items.length"
                    class="rounded-2xl border border-limen-line bg-limen-surface p-10 text-center space-y-3"
                >
                    <span class="text-4xl" aria-hidden="true">✦</span>
                    <h2 class="font-serif text-2xl text-limen-ink">Nada salvo ainda</h2>
                    <p class="text-limen-ink-mute text-sm max-w-sm mx-auto">
                        Toque no marcador de uma peça que você já pode ver para guardá-la aqui.
                    </p>
                    <Link
                        :href="route('catalog')"
                        class="inline-block no-underline border border-limen-gold text-limen-gold px-6 py-2.5 rounded-lg hover:bg-limen-gold/10 transition-colors"
                    >
                        Explore o catálogo
                    </Link>
                </div>

                <div v-else class="space-y-5">
                    <article
                        v-for="item in items"
                        :key="item.id"
                        class="rounded-2xl border border-limen-line bg-limen-surface overflow-hidden"
                    >
                        <header class="flex items-center gap-3 px-4 py-3">
                            <Link
                                :href="route('catalog.show', item.performer.slug)"
                                class="flex items-center gap-3 no-underline min-w-0"
                            >
                                <span class="h-9 w-9 rounded-full overflow-hidden bg-limen-surface-2 border border-limen-line grid place-items-center shrink-0">
                                    <img
                                        v-if="item.performer.avatar_url"
                                        :src="item.performer.avatar_url"
                                        :alt="item.performer.stage_name"
                                        class="h-full w-full object-contain"
                                    />
                                    <span v-else class="font-serif text-sm text-limen-gold">{{ item.performer.stage_name?.charAt(0) }}</span>
                                </span>
                                <span class="font-serif text-limen-ink truncate hover:text-limen-gold transition-colors">
                                    {{ item.performer.stage_name }}
                                </span>
                            </Link>
                            <span class="ml-auto text-xs text-limen-ink-mute shrink-0">{{ relativeDate(item.published_at) }}</span>
                        </header>

                        <div class="relative aspect-square bg-limen-surface-2">
                            <video
                                v-if="!item.locked && item.kind === 'video' && item.video_url"
                                :src="item.video_url"
                                :poster="item.image_url"
                                controls
                                playsinline
                                class="h-full w-full object-contain bg-black"
                            />
                            <img
                                v-else-if="!item.locked && item.image_url"
                                :src="item.image_url"
                                :alt="item.performer.stage_name"
                                class="h-full w-full object-contain bg-limen-bg"
                            />
                            <button
                                v-else
                                type="button"
                                class="group absolute inset-0 flex items-center justify-center bg-gradient-to-br from-limen-surface-2 to-limen-bg"
                                @click="openConfirm(item)"
                            >
                                <div class="absolute inset-0 backdrop-blur-sm bg-limen-bg/30" />
                                <div class="relative flex flex-col items-center gap-2 text-center px-4">
                                    <svg class="h-8 w-8 text-limen-ink-mute" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" /></svg>
                                    <span class="text-xs text-limen-ink-mute group-hover:text-limen-gold transition-colors">
                                        {{ LEVEL_LABELS[item.access_level] ?? item.access_level }}
                                    </span>
                                    <span class="text-sm text-limen-gold font-medium">{{ item.price_tokens }} tokens</span>
                                    <span class="text-[11px] text-limen-ink-mute">Toque para desbloquear</span>
                                </div>
                            </button>

                            <span class="absolute top-2 left-2 rounded-full bg-limen-bg/70 px-2 py-0.5 text-[10px] text-limen-gold backdrop-blur">
                                {{ LEVEL_LABELS[item.access_level] ?? item.access_level }}
                            </span>

                            <!-- Remover dos salvos (marcador preenchido = salvo). -->
                            <button
                                type="button"
                                :disabled="removingId === item.id"
                                aria-label="Remover dos salvos"
                                class="absolute bottom-2 right-2 z-10 inline-flex h-9 w-9 items-center justify-center rounded-full bg-limen-bg/70 text-limen-gold backdrop-blur transition-colors hover:text-limen-ink disabled:opacity-50"
                                @click="unsave(item)"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 4h12a1 1 0 0 1 1 1v15l-7-4-7 4V5a1 1 0 0 1 1-1Z" /></svg>
                            </button>
                        </div>

                        <footer v-if="item.locked" class="flex items-center justify-between gap-3 px-4 py-3">
                            <span class="text-sm text-limen-ink-mute">
                                <span class="text-limen-gold font-medium">{{ item.price_tokens }} tokens</span> · permanente
                            </span>
                            <button
                                type="button"
                                class="rounded-lg bg-limen-gold text-limen-bg px-4 py-1.5 text-sm hover:opacity-90 transition-opacity"
                                @click="openConfirm(item)"
                            >
                                Desbloquear
                            </button>
                        </footer>
                    </article>

                    <div v-if="hasMore" class="pt-2 text-center">
                        <button
                            type="button"
                            :disabled="loadingMore"
                            class="rounded-lg border border-limen-line text-limen-ink-mute px-6 py-2.5 text-sm hover:text-limen-ink hover:border-limen-gold/40 transition-colors disabled:opacity-40"
                            @click="loadMore"
                        >
                            {{ loadingMore ? 'Carregando…' : 'Carregar mais' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <Modal :show="selected !== null" max-width="sm" @close="closeConfirm">
            <h2 class="font-serif text-xl text-cream mb-2">Desbloquear conteúdo</h2>
            <p class="text-muted text-sm mb-1">
                Desbloqueio permanente de uma peça
                <span class="text-cream">{{ LEVEL_LABELS[selected?.access_level] ?? selected?.access_level }}</span>
                de {{ selected?.performer?.stage_name }}.
            </p>
            <p class="text-muted text-sm mb-4">
                Custo: <span class="text-gold font-medium">{{ selected?.price_tokens }} tokens</span>.
            </p>
            <p v-if="error" class="text-danger text-sm mb-4">{{ error }}</p>
            <div class="flex gap-3 justify-end">
                <Button variant="ghost" size="sm" :disabled="unlocking" @click="closeConfirm">Cancelar</Button>
                <Button variant="primary" size="sm" :disabled="unlocking" @click="confirmUnlock">
                    {{ unlocking ? 'Desbloqueando…' : 'Desbloquear' }}
                </Button>
            </div>
        </Modal>
    </AppLayout>
</template>
