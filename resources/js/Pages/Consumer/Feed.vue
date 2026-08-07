<script setup>
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import Button from '@/Components/Button.vue'
import { postJson } from '@/lib/http'

// Feed de conteúdo permanente das performers seguidas (Sprint 16). Cada item já
// chega resolvido pelo servidor para ESTE membro (ContentPresenter::feedItem):
//   { id, kind, access_level, price_tokens, locked, state, can_unlock, image_url,
//     performer: { stage_name, slug, avatar_url }, published_at }
// Bloqueado NÃO traz image_url — blur em CSS não é paywall. O desbloqueio reusa
// a rota content.unlock (mesma da galeria do perfil).
const props = defineProps({
    feed: { type: Object, required: true },
    // false = o membro não segue ninguém → estado vazio manda ao catálogo.
    followsAnyone: { type: Boolean, default: false },
})

const LEVEL_LABELS = {
    open: 'Aberto',
    premium: 'Premium',
    exclusive: 'Exclusivo',
    fc_only: 'FC Only',
}

// Cópia local reativa: o desbloqueio troca o item em memória e o "carregar mais"
// acrescenta páginas — as duas coisas sem recarregar a página inteira.
const items = ref(props.feed.data.map((c) => ({ ...c })))
const currentPage = ref(props.feed.current_page)
const lastPage = ref(props.feed.last_page)
const hasMore = ref(props.feed.has_more)
const loadingMore = ref(false)

// Data relativa em FAIXA (coarse) — data de publicação da performer, dado
// público; formatada no cliente para não depender de locale no servidor.
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

// Paginação por Inertia partial reload: preserveState mantém `items` e a página
// só devolve `feed`. Acrescento os novos itens ao acumulado (não substituo).
function loadMore() {
    if (loadingMore.value || !hasMore.value) return
    loadingMore.value = true
    router.get(
        route('feed'),
        { page: currentPage.value + 1 },
        {
            only: ['feed'],
            preserveState: true,
            preserveScroll: true,
            // Não empilha histórico a cada "carregar mais" (o Voltar não deve
            // percorrer as páginas do feed).
            replace: true,
            onSuccess: (page) => {
                const f = page.props.feed
                items.value.push(...f.data.map((c) => ({ ...c })))
                currentPage.value = f.current_page
                lastPage.value = f.last_page
                hasMore.value = f.has_more
            },
            onFinish: () => {
                loadingMore.value = false
            },
        },
    )
}

// ── Desbloqueio (reusa content.unlock — mesma da ContentGallery) ─────────────
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
        // Preserva performer/published (a resposta do unlock é só a peça base);
        // atualiza locked/state/image_url.
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
    <AppLayout title="Feed">
        <div class="bg-limen-bg min-h-screen">
            <div class="max-w-2xl mx-auto px-6 py-10 space-y-6">
                <div class="space-y-1">
                    <h1 class="font-serif text-4xl text-limen-ink">Feed</h1>
                    <p class="text-limen-ink-mute text-sm">Conteúdo novo de quem você segue, do mais recente ao mais antigo.</p>
                </div>

                <!-- Estado vazio. Segue ninguém → convida ao catálogo; segue mas
                     nada publicado → mesma saída, outra copy. -->
                <div
                    v-if="!items.length"
                    class="rounded-2xl border border-limen-line bg-limen-surface p-10 text-center space-y-3"
                >
                    <span class="text-4xl" aria-hidden="true">✦</span>
                    <h2 class="font-serif text-2xl text-limen-ink">
                        {{ followsAnyone ? 'Nada novo por aqui ainda' : 'Seu feed está vazio' }}
                    </h2>
                    <p class="text-limen-ink-mute text-sm max-w-sm mx-auto">
                        {{ followsAnyone
                            ? 'Quem você segue ainda não publicou conteúdo. Descubra mais performers no catálogo.'
                            : 'Siga performers para ver o conteúdo delas aqui. Comece pelo catálogo.' }}
                    </p>
                    <Link
                        :href="route('catalog')"
                        class="inline-block no-underline border border-limen-gold text-limen-gold px-6 py-2.5 rounded-lg hover:bg-limen-gold/10 transition-colors"
                    >
                        Explore o catálogo
                    </Link>
                </div>

                <!-- Feed -->
                <div v-else class="space-y-5">
                    <article
                        v-for="item in items"
                        :key="item.id"
                        class="rounded-2xl border border-limen-line bg-limen-surface overflow-hidden"
                    >
                        <!-- Cabeçalho: performer (link ao perfil) + data relativa. -->
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
                                        class="h-full w-full object-cover"
                                    />
                                    <span v-else class="font-serif text-sm text-limen-gold">{{ item.performer.stage_name?.charAt(0) }}</span>
                                </span>
                                <span class="font-serif text-limen-ink truncate hover:text-limen-gold transition-colors">
                                    {{ item.performer.stage_name }}
                                </span>
                            </Link>
                            <span class="ml-auto text-xs text-limen-ink-mute shrink-0">{{ relativeDate(item.published_at) }}</span>
                        </header>

                        <!-- Corpo: imagem real (desbloqueado/grátis/dona) OU placeholder
                             bloqueado com cadeado + nível + preço + botão. -->
                        <div class="relative aspect-square bg-limen-surface-2">
                            <img
                                v-if="!item.locked && item.image_url"
                                :src="item.image_url"
                                :alt="item.performer.stage_name"
                                class="h-full w-full object-cover"
                            />
                            <button
                                v-else
                                type="button"
                                class="group absolute inset-0 flex items-center justify-center bg-gradient-to-br from-limen-surface-2 to-limen-bg"
                                @click="openConfirm(item)"
                            >
                                <div class="absolute inset-0 backdrop-blur-sm bg-limen-bg/30" />
                                <div class="relative flex flex-col items-center gap-2 text-center px-4">
                                    <span class="text-3xl" aria-hidden="true">🔒</span>
                                    <span class="text-xs text-limen-ink-mute group-hover:text-limen-gold transition-colors">
                                        {{ LEVEL_LABELS[item.access_level] ?? item.access_level }}
                                    </span>
                                    <span class="text-sm text-limen-gold font-medium">{{ item.price_tokens }} tokens</span>
                                    <span class="text-[11px] text-limen-ink-mute">Toque para desbloquear</span>
                                </div>
                            </button>

                            <span
                                class="absolute top-2 left-2 rounded-full bg-limen-bg/70 px-2 py-0.5 text-[10px] text-limen-gold backdrop-blur"
                            >
                                {{ LEVEL_LABELS[item.access_level] ?? item.access_level }}
                            </span>
                        </div>

                        <!-- Rodapé: preço/estado + ação de desbloqueio para o item
                             bloqueado (redundante com o toque na imagem, para achabilidade). -->
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

                    <!-- Carregar mais -->
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

        <!-- Confirmação de desbloqueio: gasta tokens, então confirma. Mesmo fluxo
             da ContentGallery. -->
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
