<script setup>
import { computed, ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import { postForm, deleteJson } from '@/lib/http'
import { downscaleImage } from '@/lib/clientImage'
import FaceBlurPicker from '@/Components/FaceBlurPicker.vue'

const props = defineProps({
    settings: { type: Object, default: () => ({ is_open: false, price_public_tokens: null, vip_enabled: false, price_vip_tokens: null }) },
    set: { type: Array, default: () => [] },
    roster: { type: Object, default: () => ({ below_floor: true, count_label: 'Menos de 5', supporters: [] }) },
    priceConfig: { type: Object, default: () => ({ min: 20, step: 5, max: 2000 }) },
    payoutRatePerToken: { type: Number, default: 0.6 },
    performerSharePct: { type: Number, default: 80 },
    anonymityFloor: { type: Number, default: 5 },
})

// ── Config do clube (editor) ──────────────────────────────────────────────────
const form = useForm({
    is_open: props.settings.is_open,
    price_public_tokens: props.settings.price_public_tokens,
    vip_enabled: props.settings.vip_enabled,
    price_vip_tokens: props.settings.price_vip_tokens,
})

// O que a performer SACA por uma assinatura de N tokens: 80% × R$0,60. Display honesto
// do spread (docs/FORK_ASSINATURA.md §3.1) — o preço é em token, o R$ é só leitura.
function netBrl(tokens) {
    const t = Number(tokens) || 0
    const reais = t * (props.performerSharePct / 100) * props.payoutRatePerToken
    return reais.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}
// Preço de compra (âncora R$1,00/token) — o que o membro paga em reais, aproximado.
function memberBrl(tokens) {
    return (Number(tokens) || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}
// Modo inverso: "quero receber R$X líquido" → preço em tokens (arredonda pro passo).
const targetNet = ref(null)
function applyTargetNet() {
    const alvo = Number(targetNet.value) || 0
    if (alvo <= 0) return
    const perToken = (props.performerSharePct / 100) * props.payoutRatePerToken // R$ por token sacado
    let tokens = Math.ceil(alvo / perToken)
    const step = props.priceConfig.step || 5
    tokens = Math.ceil(tokens / step) * step
    form.price_public_tokens = Math.min(Math.max(tokens, props.priceConfig.min), props.priceConfig.max)
}

const settingsError = computed(() => form.errors.fanclub || form.errors.price_public_tokens || form.errors.price_vip_tokens)

function saveSettings() {
    form.post(route('performer.fanclub.settings'), { preserveScroll: true })
}

// ── Publicar no set ───────────────────────────────────────────────────────────
const file = ref(null)
const filePreview = ref(null)
const publishing = ref(false)
const pubError = ref('')

const isVideoFile = computed(() => !!file.value && String(file.value.type).startsWith('video/'))
const canPublish = computed(() => !!file.value && !publishing.value)

// Rosto borrado (Onda B, achado do UAT): o set também oferece o oval.
const FACE_DEFAULT = { cx: 0.5, cy: 0.38, rx: 0.18 }
const blurFace = ref(false)
const faceRegion = ref({ ...FACE_DEFAULT })

// Lightbox do set (achado do UAT): tocar numa peça abre em tamanho grande para
// conferir se ficou boa — mesmo gesto de "Suas peças" no Conteúdo.
const lightbox = ref(null)

function openLightbox(piece) {
    if (piece.status !== 'ready' || !piece.image_url) return
    lightbox.value = piece
}

function closeLightbox() {
    lightbox.value = null
}

async function onFile(event) {
    const chosen = event.target.files[0] ?? null
    // Reduz FOTO no navegador antes do upload (vídeo passa direto).
    file.value = chosen ? await downscaleImage(chosen) : null
    filePreview.value = file.value ? URL.createObjectURL(file.value) : null
}

async function publish() {
    if (!canPublish.value) return
    publishing.value = true
    pubError.value = ''
    const fd = new FormData()
    fd.append('arquivo', file.value)
    const blurring = blurFace.value && !isVideoFile.value
    fd.append('borrar_rosto', blurring ? '1' : '0')
    if (blurring) {
        fd.append('rosto_cx', String(faceRegion.value.cx))
        fd.append('rosto_cy', String(faceRegion.value.cy))
        fd.append('rosto_rx', String(faceRegion.value.rx))
        fd.append('rosto_ry', String(Math.min(0.49, faceRegion.value.rx * 1.3)))
    }
    try {
        await postForm(route('performer.fanclub.content'), fd)
        file.value = null
        filePreview.value = null
        blurFace.value = false
        faceRegion.value = { ...FACE_DEFAULT }
        router.reload({ only: ['set'] })
    } catch (e) {
        pubError.value = e.data?.message ?? 'Não foi possível publicar. Tente novamente.'
    } finally {
        publishing.value = false
    }
}

async function removePiece(piece) {
    if (!window.confirm('Remover esta peça do fã-clube? Os assinantes deixam de vê-la.')) return
    pubError.value = ''
    try {
        await deleteJson(route('performer.content.destroy', piece.id))
        router.reload({ only: ['set'] })
    } catch (e) {
        pubError.value = e.data?.message ?? 'Não foi possível remover.'
    }
}

// ── Roster (sinal de baleia) ──────────────────────────────────────────────────
const BAND_LABELS = { novo: 'Novo', recorrente: 'Recorrente', alto: 'Alto apoiador' }
const BAND_CLASS = {
    alto: 'bg-gold/15 text-gold border-gold/30',
    recorrente: 'bg-sky-500/10 text-sky-400 border-sky-500/30',
    novo: 'bg-surface-2 text-muted border-frame',
}
function bandLabel(b) { return BAND_LABELS[b] ?? b }
function bandClass(b) { return BAND_CLASS[b] ?? BAND_CLASS.novo }
</script>

<template>
    <AppLayout title="Fã-clube">
        <div class="max-w-3xl mx-auto px-6 py-10 space-y-8">
            <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                <div class="space-y-1">
                    <h1 class="font-serif text-4xl text-cream">Fã-clube</h1>
                    <p class="text-muted text-sm">Assinatura mensal sua, em tokens. Quem assina vê o seu set enquanto estiver ativo.</p>
                </div>
                <Link :href="route('performer.content')" class="text-sm text-gold hover:text-gold-light transition-colors shrink-0">
                    Voltar ao conteúdo
                </Link>
            </div>

            <!-- Config do clube -->
            <div class="rounded-xl border border-frame bg-surface p-6 space-y-5">
                <h2 class="font-serif text-xl text-cream">Seu clube</h2>

                <label class="flex items-center gap-3">
                    <input v-model="form.is_open" type="checkbox" class="h-4 w-4 rounded border-frame bg-surface-2 text-gold focus:ring-gold" />
                    <span class="text-sm text-cream">Fã-clube aberto para assinaturas</span>
                </label>

                <div v-if="form.is_open" class="space-y-5">
                    <div class="space-y-1.5">
                        <label class="text-sm text-muted">Preço mensal (tokens)</label>
                        <input
                            v-model.number="form.price_public_tokens"
                            type="number" :min="priceConfig.min" :max="priceConfig.max" :step="priceConfig.step"
                            class="w-full max-w-xs rounded-lg border border-frame bg-surface-2 px-3 py-2 text-sm text-cream"
                        />
                        <p class="text-xs text-muted">
                            Mínimo {{ priceConfig.min }}, em múltiplos de {{ priceConfig.step }}, até {{ priceConfig.max }}.
                        </p>
                        <p v-if="Number(form.price_public_tokens) > 0" class="text-xs text-cream">
                            O membro paga <span class="text-gold">{{ form.price_public_tokens }} tokens</span>
                            (~{{ memberBrl(form.price_public_tokens) }}) · você saca
                            <span class="text-gold">~{{ netBrl(form.price_public_tokens) }}</span>
                        </p>
                    </div>

                    <div class="rounded-lg border border-frame bg-limen-bg/30 p-3 space-y-1.5">
                        <label class="text-xs uppercase tracking-wide text-muted">Prefere pensar no líquido?</label>
                        <div class="flex items-center gap-2">
                            <input
                                v-model.number="targetNet" type="number" min="0" placeholder="R$ a sacar"
                                class="min-w-0 flex-1 rounded-lg border border-frame bg-surface-2 px-3 py-2 text-sm text-cream"
                            />
                            <button type="button" class="rounded-lg border border-gold px-3 py-2 text-xs text-gold hover:bg-gold/10" @click="applyTargetNet">
                                Calcular tokens
                            </button>
                        </div>
                        <p class="text-xs text-muted">Converte o valor líquido desejado no preço em tokens (o membro paga um pouco mais).</p>
                    </div>

                    <label class="flex items-center gap-3">
                        <input v-model="form.vip_enabled" type="checkbox" class="h-4 w-4 rounded border-frame bg-surface-2 text-gold focus:ring-gold" />
                        <span class="text-sm text-cream">Oferecer preço VIP menor para Black e FC (opcional)</span>
                    </label>

                    <div v-if="form.vip_enabled" class="space-y-1.5">
                        <label class="text-sm text-muted">Preço VIP (tokens) — só Black/FC veem</label>
                        <input
                            v-model.number="form.price_vip_tokens"
                            type="number" :min="priceConfig.min" :max="priceConfig.max" :step="priceConfig.step"
                            class="w-full max-w-xs rounded-lg border border-frame bg-surface-2 px-3 py-2 text-sm text-cream"
                        />
                        <p class="text-xs text-muted">Precisa ser menor ou igual ao preço público. Eles já pagam em tokens mais baratos — o desconto é opcional e seu.</p>
                        <p v-if="Number(form.price_vip_tokens) > 0" class="text-xs text-cream">
                            VIP paga <span class="text-gold">{{ form.price_vip_tokens }} tokens</span> · você saca
                            <span class="text-gold">~{{ netBrl(form.price_vip_tokens) }}</span>
                        </p>
                    </div>
                </div>

                <p v-if="settingsError" class="rounded-lg border border-danger/40 bg-danger/10 px-4 py-2 text-sm text-danger">{{ settingsError }}</p>

                <button
                    type="button" :disabled="form.processing"
                    class="inline-flex items-center rounded-lg bg-gold px-5 py-2.5 text-sm font-medium text-background transition-colors hover:bg-gold-light disabled:opacity-50"
                    @click="saveSettings"
                >
                    {{ form.processing ? 'Salvando...' : 'Salvar clube' }}
                </button>
            </div>

            <!-- Publicar no set -->
            <div v-if="form.is_open" class="rounded-xl border border-frame bg-surface p-6 space-y-4">
                <h2 class="font-serif text-xl text-cream">Conteúdo do set</h2>

                <p v-if="pubError" class="rounded-lg border border-danger/40 bg-danger/10 px-4 py-2 text-sm text-danger">{{ pubError }}</p>

                <div class="aspect-[4/3] w-full max-w-sm overflow-hidden rounded-lg bg-surface-2 border border-frame flex items-center justify-center">
                    <video v-if="filePreview && isVideoFile" :src="filePreview" class="h-full w-full object-cover" controls muted />
                    <img v-else-if="filePreview" :src="filePreview" alt="Prévia" class="h-full w-full object-cover" />
                    <span v-else class="text-sm text-muted">Nenhum arquivo escolhido</span>
                </div>

                <label class="cursor-pointer inline-block">
                    <span class="inline-flex items-center rounded-lg border border-gold text-gold px-4 py-2 text-sm hover:bg-gold/10 transition-colors">
                        {{ file ? 'Trocar arquivo' : 'Escolher arquivo' }}
                    </span>
                    <input type="file" accept="image/jpeg,image/png,video/mp4,video/quicktime,video/webm,video/x-matroska" class="hidden" @change="onFile" />
                </label>
                <p class="text-xs text-muted">Foto (JPEG/PNG, até 10 MB) ou vídeo (MP4/MOV/WebM/MKV, até 500 MB, 10 min). Vídeo passa por processamento.</p>

                <!-- Rosto borrado (Onda B): só FOTO; oval permanente, mesmo do conteúdo. -->
                <template v-if="!isVideoFile">
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input v-model="blurFace" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-frame bg-surface-2 accent-gold" />
                        <span class="space-y-0.5">
                            <span class="block text-sm text-cream">Borrar meu rosto nesta foto</span>
                            <span class="block text-xs text-muted">Cobre só o rosto com um borrão, no oval que você posicionar. É permanente nesta foto.</span>
                        </span>
                    </label>
                    <FaceBlurPicker v-if="blurFace && filePreview" v-model="faceRegion" :preview-url="filePreview" />
                </template>

                <button
                    type="button" :disabled="!canPublish"
                    class="inline-flex items-center rounded-lg bg-gold px-5 py-2.5 text-sm font-medium text-background transition-colors hover:bg-gold-light disabled:opacity-50"
                    @click="publish"
                >
                    {{ publishing ? 'Publicando...' : 'Publicar no set' }}
                </button>

                <div class="space-y-3 pt-2">
                    <p v-if="!set.length" class="rounded-lg border border-frame bg-surface-2 px-4 py-6 text-center text-sm text-muted">O set está vazio.</p>
                    <ul v-else class="grid grid-cols-3 gap-3 sm:grid-cols-4">
                        <li v-for="piece in set" :key="piece.id" class="relative aspect-square overflow-hidden rounded-lg border border-frame bg-surface-2">
                            <button
                                v-if="piece.image_url"
                                type="button"
                                :title="piece.status === 'ready' ? 'Ver em tamanho grande' : null"
                                class="block h-full w-full enabled:cursor-zoom-in"
                                :disabled="piece.status !== 'ready'"
                                @click="openLightbox(piece)"
                            >
                                <img :src="piece.image_url" alt="" class="h-full w-full object-cover" />
                            </button>
                            <div v-else class="flex h-full w-full items-center justify-center text-xs text-muted">
                                {{ piece.status === 'processing' ? 'Processando…' : (piece.kind === 'video' ? 'Vídeo' : '—') }}
                            </div>
                            <button
                                type="button" title="Remover"
                                class="absolute right-1 top-1 rounded-full bg-background/70 p-1 text-danger hover:bg-background"
                                @click="removePiece(piece)"
                            >
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6" /></svg>
                            </button>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Assinantes (sinal de baleia) -->
            <div class="rounded-xl border border-frame bg-surface p-6 space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="font-serif text-xl text-cream">Assinantes</h2>
                    <span class="text-sm text-muted">{{ roster.count_label }}</span>
                </div>

                <p v-if="roster.below_floor" class="rounded-lg border border-frame bg-surface-2 px-4 py-6 text-center text-sm text-muted">
                    Para proteger o anonimato dos membros, a lista aparece a partir de {{ anonymityFloor }} assinantes.
                </p>

                <ul v-else class="space-y-2">
                    <li v-for="(s, i) in roster.supporters" :key="i" class="flex items-center justify-between gap-3 rounded-lg border border-frame bg-surface-2 px-4 py-2.5">
                        <span class="text-sm text-cream">{{ s.alias }}</span>
                        <span class="flex items-center gap-2">
                            <span class="rounded-full border border-frame px-2 py-0.5 text-xs text-muted">{{ s.tier }}</span>
                            <span class="rounded-full border px-2 py-0.5 text-xs" :class="bandClass(s.band)">{{ bandLabel(s.band) }}</span>
                        </span>
                    </li>
                </ul>
                <p class="text-xs text-muted">Você vê só um apelido por fã e a faixa de apoio — nunca o nome real.</p>
            </div>
        </div>

        <!-- Lightbox do set: a dona confere a peça em tamanho grande (vídeo mostra o
             quadro de capa; a reprodução é do fluxo do assinante). -->
        <Modal :show="!!lightbox" max-width="2xl" @close="closeLightbox">
            <div v-if="lightbox" class="space-y-4">
                <div class="flex items-center justify-between gap-3">
                    <span class="rounded bg-surface-2 px-2 py-0.5 text-xs text-gold">Fã-clube</span>
                    <button
                        type="button"
                        class="rounded-lg border border-frame px-3 py-1.5 text-xs text-muted transition-colors hover:text-cream hover:bg-surface-2"
                        @click="closeLightbox"
                    >
                        Fechar
                    </button>
                </div>
                <div class="overflow-hidden rounded-lg border border-frame bg-black">
                    <img :src="lightbox.image_url" alt="" class="mx-auto max-h-[70vh] w-auto object-contain" />
                </div>
                <p v-if="lightbox.kind === 'video'" class="text-xs text-muted">
                    Este é o quadro de capa do vídeo. A reprodução completa fica com o assinante.
                </p>
            </div>
        </Modal>
    </AppLayout>
</template>
