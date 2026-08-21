<script setup>
import { computed, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import Button from '@/Components/Button.vue'
import { postJson } from '@/lib/http'

// Galeria de conteúdo permanente (M.4/M.13.13). Cada item chega JÁ resolvido pelo
// servidor (ContentVisibilityService) para ESTE espectador:
//   { id, kind, access_level, price_tokens, locked, state, can_unlock,
//     image_url, video_url, required_tier_label, blur_url }
// Bloqueado NÃO traz image_url/video_url (paywall server-side). `blur_url`, quando
// existe, é uma PRÉVIA borrada gerada no servidor (baixa resolução + blur pesado,
// irreversível) — nunca a imagem original com filtro CSS.
const props = defineProps({
    contents: { type: Array, default: () => [] },
    performerName: { type: String, default: '' },
    // Destino de quem NÃO pode desbloquear aqui (visitante deslogado / performer /
    // admin): o cadastro. Membro logado recebe null e usa o botão de desbloquear.
    signupHref: { type: String, default: null },
})

const LEVEL_LABELS = {
    open: 'Aberto',
    premium: 'Premium',
    exclusive: 'Exclusivo',
    fc_only: 'FC Only',
}

// Cópia local reativa: o desbloqueio troca o item em memória (locked → unlocked, com
// image_url) sem recarregar. `imageFailed`/`blurFailed` caem no placeholder decente
// quando os bytes não carregam, sem quebrar e sem vazar.
const items = ref(props.contents.map((c) => ({ ...c, imageFailed: false, blurFailed: false })))

// AGRUPAMENTO POR ACESSO (item 6): com 40 peças bloqueadas, uma pilha única de
// cadeados faz o membro concluir que não há nada para ele. Agrupar mostra primeiro
// o que ele PODE ter.
//  - "Disponível para você": acessível (não bloqueado) OU comprável avulso.
//  - blocos bloqueados por tier: agrupados pelo Círculo que os destrava.
const available = computed(() => items.value.filter((i) => ! i.locked || i.can_unlock))

const TIER_ORDER = { Black: 1, 'Círculo de Fundadores': 2 }
const tierGroups = computed(() => {
    const groups = {}
    for (const i of items.value) {
        if (i.required_tier_label) {
            ;(groups[i.required_tier_label] ??= []).push(i)
        }
    }
    return Object.entries(groups)
        .map(([tier, list]) => ({ tier, list }))
        .sort((a, b) => (TIER_ORDER[a.tier] ?? 99) - (TIER_ORDER[b.tier] ?? 99))
})

const total = computed(() => items.value.length)
const tierHref = computed(() => props.signupHref ?? route('subscribe.index'))

function fotos(n) {
    return `${n} ${n === 1 ? 'foto' : 'fotos'}`
}

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
            items.value[idx] = { ...data.content, imageFailed: false, blurFailed: false }
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
    <div v-if="items.length" class="mt-8 space-y-8">
        <!-- Resumo no topo: total e quanto está disponível para o espectador. -->
        <div>
            <h2 class="font-serif text-xl text-cream">Conteúdo</h2>
            <p class="mt-0.5 text-sm text-muted">
                {{ fotos(total) }} · {{ available.length }} disponíve{{ available.length === 1 ? 'l' : 'is' }} para você
            </p>
        </div>

        <!-- (a) DISPONÍVEL PARA VOCÊ — vitrine de venda: uma peça por linha, imagem
             grande, preço em destaque. Aberto + Premium (e o que o tier alcança). -->
        <section v-if="available.length" class="space-y-4">
            <h3 class="text-[11px] uppercase tracking-[0.28em] text-gold/80">Disponível para você</h3>

            <div class="space-y-4">
                <div
                    v-for="item in available"
                    :key="item.id"
                    class="relative aspect-[4/3] overflow-hidden rounded-2xl border border-frame bg-surface-2"
                >
                    <!-- Acessível: mídia real. -->
                    <template v-if="!item.locked && item.image_url">
                        <video
                            v-if="item.kind === 'video' && item.video_url"
                            :src="item.video_url"
                            :poster="item.image_url"
                            controls
                            playsinline
                            class="h-full w-full object-cover bg-black"
                        />
                        <img v-else-if="!item.imageFailed" :src="item.image_url" alt="" class="h-full w-full object-cover" @error="item.imageFailed = true" />
                        <div v-else class="absolute inset-0 flex items-center justify-center text-sm text-muted">Imagem indisponível</div>
                        <span class="absolute top-3 left-3 rounded-full bg-background/70 px-2.5 py-1 text-[11px] text-gold backdrop-blur">
                            {{ LEVEL_LABELS[item.access_level] ?? item.access_level }}
                        </span>
                    </template>

                    <!-- Comprável: prévia BORRADA (servidor) + preço em destaque + comprar. -->
                    <button
                        v-else
                        type="button"
                        class="group absolute inset-0 h-full w-full text-left"
                        @click="openConfirm(item)"
                    >
                        <img v-if="item.blur_url && !item.blurFailed" :src="item.blur_url" alt="" class="h-full w-full object-cover" @error="item.blurFailed = true" />
                        <div v-else class="h-full w-full bg-gradient-to-br from-surface-2 to-background" />
                        <div class="absolute inset-0 bg-background/40" />
                        <div class="absolute inset-0 flex flex-col items-center justify-center gap-2 px-4 text-center">
                            <span class="rounded-full bg-background/70 px-2.5 py-1 text-[11px] text-gold backdrop-blur">
                                {{ LEVEL_LABELS[item.access_level] ?? item.access_level }}
                            </span>
                            <span class="font-serif text-2xl text-gold">{{ item.price_tokens }} tokens</span>
                            <span class="rounded-lg bg-gold px-4 py-2 text-sm font-semibold text-background transition-opacity group-hover:opacity-90">
                                Desbloquear
                            </span>
                        </div>
                    </button>
                </div>
            </div>
        </section>

        <!-- (b)(c) BLOQUEADO POR TIER — grade compacta 3 colunas; o grupo inteiro leva
             aos Círculos. Prévia borrada quando existe, senão placeholder. -->
        <section v-for="g in tierGroups" :key="g.tier" class="space-y-3">
            <Link :href="tierHref" class="flex items-center justify-between gap-3 no-underline">
                <h3 class="text-sm text-cream">No {{ g.tier }} — {{ fotos(g.list.length) }}</h3>
                <span class="shrink-0 text-sm text-gold transition-colors hover:text-gold-light">Assinar &rarr;</span>
            </Link>

            <Link :href="tierHref" class="grid grid-cols-3 gap-2 no-underline">
                <div
                    v-for="item in g.list"
                    :key="item.id"
                    class="relative aspect-square overflow-hidden rounded-lg border border-frame bg-surface-2"
                >
                    <img v-if="item.blur_url && !item.blurFailed" :src="item.blur_url" alt="" class="h-full w-full object-cover" @error="item.blurFailed = true" />
                    <div v-else class="h-full w-full bg-gradient-to-br from-surface-2 to-background" />
                    <div class="absolute inset-0 flex items-center justify-center bg-background/40">
                        <span class="text-lg" aria-hidden="true">🔒</span>
                    </div>
                </div>
            </Link>
        </section>

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
