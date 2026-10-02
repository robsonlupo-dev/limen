<script setup>
import { router } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import TokenCoin from '@/Components/TokenCoin.vue'
import { useInsufficientBalance } from '@/composables/useInsufficientBalance'

/**
 * Modal GLOBAL de "Saldo insuficiente" (montado uma vez no AppLayout). Substitui o
 * aviso mudo que não levava a lugar nenhum: traz a CTA grande "Comprar tokens" (leva à
 * Carteira) e um "Agora não" que FECHA e deixa a pessoa exatamente onde estava.
 *
 * O estado vem do singleton `useInsufficientBalance`; cada ação de gasto do membro o
 * abre com uma mensagem de contexto.
 */
const { state, close } = useInsufficientBalance()

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

            <div class="space-y-2">
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
