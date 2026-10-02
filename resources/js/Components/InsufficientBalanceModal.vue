<script setup>
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import TokenCoin from '@/Components/TokenCoin.vue'
import { getJson } from '@/lib/http'
import { useInsufficientBalance } from '@/composables/useInsufficientBalance'

/**
 * Modal GLOBAL de "Saldo insuficiente" (montado uma vez no AppLayout). Substitui o
 * aviso mudo que não levava a lugar nenhum: mostra os PACOTES de token (quick-buy) e,
 * no clique, leva à Carteira já naquele pacote. "Agora não" FECHA e deixa a pessoa
 * exatamente onde estava.
 *
 * Os pacotes são buscados SOB DEMANDA quando o modal abre (rota wallet.packages) —
 * nunca no carregamento das páginas, para não acrescentar query ao caminho crítico
 * (um prop compartilhado global quebrava a garantia de N+1 do catálogo). Busca uma
 * única vez por sessão de página. A compra em si (CPF + PIX) acontece na carteira;
 * o modal só encurta o caminho. Sem pacotes (falha de rede), cai no botão
 * "Comprar tokens".
 *
 * O estado vem do singleton `useInsufficientBalance`; cada ação de gasto do membro o
 * abre com uma mensagem de contexto.
 */
const { state, close } = useInsufficientBalance()

const packages = ref([])
let loaded = false

// Busca os pacotes na PRIMEIRA vez que o modal abre (lazy). Falha em silêncio —
// o template cai no botão genérico "Comprar tokens".
watch(() => state.open, async (open) => {
    if (!open || loaded) return
    loaded = true
    try {
        const data = await getJson(route('wallet.packages'))
        packages.value = data?.packages ?? []
    } catch {
        packages.value = []
    }
})

function buyPackage(pkg) {
    close()
    router.visit(route('wallet.index', { package: pkg.id }))
}

function buy() {
    close()
    router.visit(route('wallet.index'))
}
</script>

<template>
    <Modal :show="state.open" max-width="sm" @close="close">
        <div class="space-y-5 text-center">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full" style="background-color: #262626">
                <TokenCoin class="h-7 w-7" />
            </div>

            <div class="space-y-1.5">
                <h3 class="font-serif text-2xl text-cream">Saldo insuficiente</h3>
                <p class="text-sm text-muted">
                    {{ state.message || 'Você não tem tokens suficientes para esta ação. Recarregue sua carteira e continue de onde parou.' }}
                </p>
            </div>

            <!-- Quick-buy: pacotes direto no pop. Clique leva à carteira já naquele
                 pacote (a cobrança PIX/CPF acontece lá). -->
            <div v-if="packages.length" class="space-y-2">
                <button
                    v-for="pkg in packages"
                    :key="pkg.id"
                    type="button"
                    class="mi-press flex min-h-[44px] w-full items-center justify-between gap-3 rounded-lg border border-frame px-4 py-3 text-left transition-colors hover:border-gold/60 hover:bg-surface-2"
                    @click="buyPackage(pkg)"
                >
                    <span class="flex items-center gap-2 text-sm font-semibold text-cream">
                        <TokenCoin class="h-5 w-5 shrink-0" />
                        {{ pkg.tokens }} tokens<span v-if="pkg.bonus" class="text-xs font-normal text-gold">+{{ pkg.bonus }} bônus</span>
                    </span>
                    <span class="shrink-0 text-sm text-gold">{{ pkg.price_formatted }}</span>
                </button>
                <button
                    type="button"
                    class="min-h-[44px] w-full rounded-lg px-5 py-2 text-sm text-muted transition-colors hover:text-cream"
                    @click="close"
                >
                    Agora não
                </button>
            </div>

            <!-- Fallback sem pacotes compartilhados: a CTA antiga para a carteira. -->
            <div v-else class="space-y-2">
                <button
                    type="button"
                    class="mi-press flex min-h-[44px] w-full items-center justify-center gap-2 rounded-lg bg-gold px-5 py-3 text-sm font-semibold text-background transition-colors hover:bg-gold/90"
                    @click="buy"
                >
                    <TokenCoin class="h-5 w-5" /> Comprar tokens
                </button>
                <button
                    type="button"
                    class="min-h-[44px] w-full rounded-lg border border-frame px-5 py-2.5 text-sm text-muted transition-colors hover:bg-surface-2 hover:text-cream"
                    @click="close"
                >
                    Agora não
                </button>
            </div>
        </div>
    </Modal>
</template>
