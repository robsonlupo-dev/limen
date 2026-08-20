<script setup>
import { ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import Button from '@/Components/Button.vue'
import { postJson } from '@/lib/http'

// Galeria de conteúdo permanente pago (M.4/M.13.13). Cada item chega JÁ resolvido
// pelo servidor (ContentVisibilityService) para ESTE espectador:
//   { id, kind, access_level, price_tokens, locked, state, can_unlock, image_url }
// Só os níveis que o tier do espectador alcança chegam aqui — o Free recebe só o
// Aberto. Bloqueado NÃO traz image_url: blur em CSS não é paywall.
const props = defineProps({
    contents: { type: Array, default: () => [] },
    performerName: { type: String, default: '' },
    // Destino de quem NÃO pode desbloquear aqui (visitante deslogado / performer /
    // admin na página pública): o cadastro. Membro logado recebe null e usa o
    // botão de desbloquear (a peça traz can_unlock).
    signupHref: { type: String, default: null },
})

const LEVEL_LABELS = {
    open: 'Aberto',
    premium: 'Premium',
    exclusive: 'Exclusivo',
    fc_only: 'FC Only',
}

// Cópia local reativa: o desbloqueio troca o item em memória (locked → unlocked,
// agora com image_url) sem recarregar a página. As props do Inertia não mudam
// neste fluxo — a galeria não está no `only` de nenhum reload parcial.
// `imageFailed` começa falso: quando os bytes não carregam (content.image 404 —
// arquivo ausente no disco), o @error liga e a peça cai no placeholder decente,
// em vez do ícone de imagem quebrada + o alt cobrindo o selo de nível.
const items = ref(props.contents.map((c) => ({ ...c, imageFailed: false })))

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
            items.value[idx] = data.content
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
    <!-- Some por inteiro quando a performer não publicou conteúdo permanente. -->
    <div v-if="items.length" class="mt-8 space-y-3">
        <h2 class="font-serif text-xl text-cream">Conteúdo</h2>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
            <template v-for="item in items" :key="item.id">
                <!-- Desbloqueado / grátis / dona: mostra a imagem real. Proporção
                     FIXA (aspect-square) — sem depender da imagem carregar, então
                     não estica nem colapsa quando os bytes faltam. -->
                <div
                    v-if="!item.locked && item.image_url"
                    class="relative aspect-square rounded-xl overflow-hidden border border-frame bg-surface-2"
                >
                    <video
                        v-if="item.kind === 'video' && item.video_url"
                        :src="item.video_url"
                        :poster="item.image_url"
                        controls
                        playsinline
                        class="h-full w-full object-cover bg-black"
                    />
                    <!-- alt="" (decorativo): o rótulo do nível já vem no selo abaixo,
                         e um alt com texto viraria "título" quebrado sobre o selo se a
                         imagem falhasse. @error cai no placeholder antes disso. -->
                    <img
                        v-else-if="!item.imageFailed"
                        :src="item.image_url"
                        alt=""
                        class="h-full w-full object-cover"
                        @error="item.imageFailed = true"
                    />
                    <!-- Imagem indisponível (bytes ausentes → content.image 404):
                         placeholder centrado, mesma proporção, sem cobrir o selo. -->
                    <div
                        v-else
                        class="absolute inset-0 flex flex-col items-center justify-center gap-1.5 bg-surface-2 px-2 text-center"
                    >
                        <svg class="h-7 w-7 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M3 3l18 18M21 15l-5-5L5 21M3 16V5a2 2 0 0 1 2-2h11" />
                        </svg>
                        <span class="text-[11px] text-muted">Imagem indisponível</span>
                    </div>
                    <span
                        class="absolute top-2 left-2 rounded-full bg-background/70 px-2 py-0.5 text-[10px] text-gold backdrop-blur"
                    >
                        {{ LEVEL_LABELS[item.access_level] ?? item.access_level }}
                    </span>
                </div>

                <!-- Bloqueado, e o espectador PODE desbloquear (membro): botão que
                     dispara a compra. -->
                <button
                    v-else-if="item.can_unlock"
                    type="button"
                    class="group relative aspect-square rounded-xl overflow-hidden border border-frame bg-gradient-to-br from-surface-2 to-background flex items-center justify-center"
                    @click="openConfirm(item)"
                >
                    <div class="absolute inset-0 backdrop-blur-sm bg-background/30" />
                    <div class="relative flex flex-col items-center gap-1.5 text-center px-2">
                        <span class="text-2xl" aria-hidden="true">🔒</span>
                        <span class="text-[11px] text-muted group-hover:text-gold transition-colors">
                            {{ LEVEL_LABELS[item.access_level] ?? item.access_level }}
                        </span>
                        <span class="text-xs text-gold font-medium">{{ item.price_tokens }} tokens</span>
                    </div>
                </button>

                <!-- Bloqueado e SEM desbloqueio aqui (visitante / performer / admin):
                     leva ao cadastro. Mesmo caminho de toda ação da página pública. -->
                <Link
                    v-else
                    :href="signupHref ?? '#'"
                    class="group relative aspect-square rounded-xl overflow-hidden border border-frame bg-gradient-to-br from-surface-2 to-background flex items-center justify-center no-underline"
                >
                    <div class="absolute inset-0 backdrop-blur-sm bg-background/30" />
                    <div class="relative flex flex-col items-center gap-1.5 text-center px-2">
                        <span class="text-2xl" aria-hidden="true">🔒</span>
                        <span class="text-[11px] text-muted group-hover:text-gold transition-colors">
                            {{ LEVEL_LABELS[item.access_level] ?? item.access_level }}
                        </span>
                        <span class="text-xs text-gold font-medium">{{ item.price_tokens }} tokens</span>
                    </div>
                </Link>
            </template>
        </div>

        <!-- Confirmação de desbloqueio: gasta tokens, então pede confirmação. -->
        <Modal :show="selected !== null" max-width="sm" @close="closeConfirm">
            <h2 class="font-serif text-xl text-cream mb-2">Desbloquear conteúdo</h2>
            <p class="text-muted text-sm mb-1">
                Desbloqueio permanente de uma peça
                <span class="text-cream">{{ LEVEL_LABELS[selected?.access_level] ?? selected?.access_level }}</span>
                de {{ performerName }}.
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
    </div>
</template>
