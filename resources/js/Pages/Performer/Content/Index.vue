<script setup>
import { computed, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { getJson, postForm, deleteJson } from '@/lib/http'

const props = defineProps({
    // Estado inicial (o controller já entrega a primeira lista); as mutações
    // recarregam por fetch, mesma fonte do endpoint performer.content.index.
    content: { type: Array, default: () => [] },
    levels: { type: Array, default: () => [] },
    minPrice: { type: Number, default: 5 },
})

// Rótulos dos níveis de acesso (M.4/M.13.13). O slug vem do servidor; a copy é
// da tela, como os demais enums de "Sobre mim".
const LEVEL_LABELS = {
    open: 'Aberto',
    premium: 'Premium',
    exclusive: 'Exclusivo',
    fc_only: 'FC Only',
}

const LEVEL_HINTS = {
    open: 'Grátis para assinantes; não-assinante paga tokens.',
    premium: 'Acesso a partir de Prestige.',
    exclusive: 'Acesso a partir de Black.',
    fc_only: 'Só Founders Circle desbloqueia.',
}

const pieces = ref([...props.content])
const loading = ref(false)
const error = ref('')

// Formulário de publicação.
const file = ref(null)
const filePreview = ref(null)
const nivel = ref(props.levels[0] ?? 'open')
const preco = ref(props.minPrice)
const publishing = ref(false)

const canPublish = computed(
    () => !!file.value && !publishing.value && Number(preco.value) >= props.minPrice,
)

// Vídeo é higienizado por ffmpeg num job — a peça nasce "processando".
const isVideoFile = computed(() => !!file.value && String(file.value.type).startsWith('video/'))

function levelLabel(slug) {
    return LEVEL_LABELS[slug] ?? slug
}

function onFile(event) {
    const chosen = event.target.files[0]
    file.value = chosen ?? null
    filePreview.value = chosen ? URL.createObjectURL(chosen) : null
}

async function refresh() {
    const data = await getJson(route('performer.content.index'))
    pieces.value = data.content ?? []
}

async function publish() {
    if (!canPublish.value) return

    publishing.value = true
    error.value = ''

    const form = new FormData()
    form.append('arquivo', file.value)
    form.append('nivel', nivel.value)
    form.append('preco', String(preco.value))

    try {
        await postForm(route('performer.content.store'), form)
        // Reset e recarrega a lista.
        file.value = null
        filePreview.value = null
        preco.value = props.minPrice
        nivel.value = props.levels[0] ?? 'open'
        await refresh()
    } catch (e) {
        error.value = e.data?.message ?? 'Não foi possível publicar. Tente novamente.'
    } finally {
        publishing.value = false
    }
}

async function remove(piece) {
    if (!window.confirm('Remover esta peça? Quem já desbloqueou perde o acesso.')) return

    error.value = ''
    loading.value = true

    try {
        await deleteJson(route('performer.content.destroy', piece.id))
        await refresh()
    } catch (e) {
        error.value = e.data?.message ?? 'Não foi possível remover.'
    } finally {
        loading.value = false
    }
}
</script>

<template>
    <AppLayout title="Conteúdo permanente">
        <div class="max-w-3xl mx-auto px-6 py-10 space-y-8">
            <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                <div class="space-y-1">
                    <h1 class="font-serif text-4xl text-cream">Conteúdo permanente</h1>
                    <p class="text-muted text-sm">Fotos que ficam no seu perfil, desbloqueadas por tokens.</p>
                </div>
                <Link :href="route('performer.dashboard')" class="text-sm text-gold hover:text-gold-light transition-colors shrink-0">
                    Voltar ao painel
                </Link>
            </div>

            <p v-if="error" class="rounded-lg border border-danger/40 bg-danger/10 px-4 py-3 text-sm text-danger">
                {{ error }}
            </p>

            <!-- Publicar -->
            <div class="rounded-xl border border-frame bg-surface p-6 space-y-4">
                <h2 class="font-serif text-xl text-cream">Publicar nova peça</h2>

                <div class="aspect-[4/3] w-full max-w-sm overflow-hidden rounded-lg bg-surface-2 border border-frame flex items-center justify-center">
                    <video v-if="filePreview && isVideoFile" :src="filePreview" class="h-full w-full object-cover" controls muted />
                    <img v-else-if="filePreview" :src="filePreview" alt="Prévia" class="h-full w-full object-cover" />
                    <span v-else class="text-sm text-muted">Nenhum arquivo escolhido</span>
                </div>

                <div class="space-y-2">
                    <label class="cursor-pointer inline-block">
                        <span class="inline-flex items-center rounded-lg border border-gold text-gold px-4 py-2 text-sm hover:bg-gold/10 transition-colors">
                            {{ file ? 'Trocar arquivo' : 'Escolher arquivo' }}
                        </span>
                        <input
                            type="file"
                            accept="image/jpeg,image/png,video/mp4,video/quicktime,video/webm,video/x-matroska"
                            class="hidden"
                            @change="onFile"
                        />
                    </label>
                    <p class="text-xs text-muted">
                        Foto (JPEG/PNG, até 10 MB) ou vídeo (MP4/MOV/WebM/MKV, até 500 MB, no máx. 10 min).
                        Vídeo passa por processamento antes de ficar disponível.
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block space-y-1">
                        <span class="text-sm text-muted">Nível de acesso</span>
                        <select v-model="nivel" class="w-full rounded-lg border border-frame bg-surface-2 px-3 py-2 text-sm text-cream">
                            <option v-for="lvl in levels" :key="lvl" :value="lvl">{{ levelLabel(lvl) }}</option>
                        </select>
                        <span class="text-xs text-muted">{{ LEVEL_HINTS[nivel] }}</span>
                    </label>

                    <label class="block space-y-1">
                        <span class="text-sm text-muted">Preço (tokens)</span>
                        <input
                            v-model.number="preco"
                            type="number"
                            :min="minPrice"
                            step="5"
                            class="w-full rounded-lg border border-frame bg-surface-2 px-3 py-2 text-sm text-cream"
                        />
                        <span class="text-xs text-muted">Mínimo {{ minPrice }} tokens, em múltiplos de 5.</span>
                    </label>
                </div>

                <button
                    type="button"
                    :disabled="!canPublish"
                    class="inline-flex items-center rounded-lg bg-gold px-5 py-2.5 text-sm font-medium text-background transition-colors hover:bg-gold-light disabled:cursor-not-allowed disabled:opacity-50"
                    @click="publish"
                >
                    {{ publishing ? 'Publicando...' : 'Publicar' }}
                </button>
            </div>

            <!-- Lista -->
            <div class="space-y-4">
                <h2 class="font-serif text-xl text-cream">Suas peças</h2>

                <p v-if="!pieces.length" class="rounded-xl border border-frame bg-surface px-5 py-8 text-center text-sm text-muted">
                    Você ainda não publicou nenhuma peça.
                </p>

                <ul v-else class="space-y-3">
                    <li
                        v-for="piece in pieces"
                        :key="piece.id"
                        class="flex items-center gap-4 rounded-xl border border-frame bg-surface p-4"
                    >
                        <div class="h-16 w-16 shrink-0 rounded-lg border border-frame overflow-hidden bg-surface-2 flex items-center justify-center">
                            <img v-if="piece.image_url" :src="piece.image_url" alt="" class="h-full w-full object-cover" />
                            <span v-else class="text-xl" aria-hidden="true">{{ piece.kind === 'video' ? '🎬' : '🖼️' }}</span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-cream">
                                <span class="rounded bg-surface-2 px-2 py-0.5 text-xs text-gold">{{ levelLabel(piece.access_level) }}</span>
                                <span class="ml-2 text-muted">{{ piece.price_tokens }} tokens</span>
                                <span v-if="piece.kind === 'video'" class="ml-2 text-xs text-muted">🎬 vídeo</span>
                            </p>
                            <p v-if="piece.status === 'processing'" class="mt-1 text-xs text-gold">
                                Processando o vídeo… ficará disponível em breve.
                            </p>
                            <p v-else-if="piece.status === 'failed'" class="mt-1 text-xs text-danger">
                                Falhou: {{ piece.failure_reason }} Envie novamente.
                            </p>
                            <p v-else class="mt-1 text-xs text-muted">{{ piece.unlock_count }} desbloqueio(s)</p>
                        </div>
                        <button
                            type="button"
                            :disabled="loading"
                            class="shrink-0 rounded-lg border border-danger/40 px-3 py-1.5 text-xs text-danger transition-colors hover:bg-danger/10 disabled:opacity-50"
                            @click="remove(piece)"
                        >
                            Remover
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
