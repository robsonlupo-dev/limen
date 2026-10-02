<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { useNotificationSound } from '@/composables/useNotificationSound'

/**
 * Toast global de ENCOMENDA sob medida (Onda 4 §4.3). Igual ao MessageToast, mas
 * escuta `.custom_order.changed` no canal privado `user.{id}` — o mesmo evento que o
 * backend já dispara para a OUTRA parte (pedido→performer; aceite/recusa/entrega/
 * resolução→membro; contestação→performer). Antes esse evento chegava sem nenhuma UI,
 * então a performer precisava entrar no painel "toda hora" para ver — agora é
 * sinalizado na hora, como mensagem.
 *
 * Privacidade: o payload é só metadado (`order_id`, `outcome`) — nunca member_id/
 * descrição/valor. O texto do toast é fixo por `outcome`; a rota de destino vem do
 * PAPEL do próprio usuário (performer → painel de encomendas; membro → minhas
 * encomendas). Degrada limpo sem Echo.
 */
const page = usePage()
const { play } = useNotificationSound()

const toast = ref(null)
let channel = null
let handler = null
let timer = null
const AUTO_DISMISS_MS = 13000

const role = page.props?.auth?.user?.role ?? null

// Texto por outcome. Só mostramos os que fazem sentido para o papel que recebe o
// evento; um outcome desconhecido é ignorado (nada de toast vazio).
const COPY = {
    requested: { eyebrow: 'Nova encomenda', body: 'Um membro pediu uma encomenda. Aceite ou recuse no painel.' },
    disputed: { eyebrow: 'Encomenda contestada', body: 'Um membro contestou uma entrega. Veja o motivo.' },
    accepted: { eyebrow: 'Encomenda aceita', body: 'A performer aceitou — ela vai produzir e entregar.' },
    declined: { eyebrow: 'Encomenda recusada', body: 'A performer não pôde aceitar desta vez.' },
    delivered: { eyebrow: 'Encomenda entregue', body: 'Sua encomenda chegou — confira.' },
    released: { eyebrow: 'Encomenda concluída', body: 'Tudo certo com sua encomenda.' },
    refunded: { eyebrow: 'Encomenda estornada', body: 'Os tokens voltaram para a sua carteira.' },
}

function ordersRoute() {
    return role === 'performer' ? route('performer.custom-orders.index') : route('custom-orders.index')
}

function onChanged(e) {
    const copy = COPY[e?.outcome]
    if (!copy) return
    play('message')
    toast.value = { ...copy }
    if (timer) clearTimeout(timer)
    timer = setTimeout(dismiss, AUTO_DISMISS_MS)
}

function dismiss() {
    toast.value = null
    if (timer) { clearTimeout(timer); timer = null }
}

function open() {
    dismiss()
    router.visit(ordersRoute())
}

onMounted(() => {
    const id = page.props?.auth?.user?.id
    if (!window.Echo || !id) return
    channel = window.Echo.private(`user.${id}`)
    handler = (e) => onChanged(e)
    channel.listen('.custom_order.changed', handler)
})

onBeforeUnmount(() => {
    // Só o próprio callback — NUNCA Echo.leave: o canal user.{id} é compartilhado
    // com o chat/lista; derrubá-lo mataria o tempo-real deles.
    if (channel && handler) channel.stopListening('.custom_order.changed', handler)
    if (timer) clearTimeout(timer)
})
</script>

<template>
    <div class="pointer-events-none fixed bottom-4 right-4 z-[100] w-auto max-w-[calc(100vw-2rem)] sm:w-[27rem]">
        <transition name="toast">
            <div
                v-if="toast"
                role="button"
                tabindex="0"
                class="toast-card pointer-events-auto cursor-pointer rounded-xl border p-5 shadow-2xl"
                style="background-color: #0d0d0d; border-color: #262626; color: #F5F0E8"
                @click="open"
                @keydown.enter="open"
                @keydown.space.prevent="open"
            >
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full" style="background-color: #262626; color: #C9A84C">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M4 7l1.5 12.5A2 2 0 0 0 7.5 21h9a2 2 0 0 0 2-1.5L20 7M9 7V5a3 3 0 0 1 6 0v2" /></svg>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold uppercase tracking-wide" style="color: #C9A84C">{{ toast.eyebrow }}</p>
                        <p class="mt-0.5 text-sm" style="color: #F5F0E8">{{ toast.body }}</p>
                    </div>
                    <button
                        type="button"
                        aria-label="Fechar"
                        class="shrink-0 rounded p-1 text-lg leading-none opacity-60 hover:opacity-100"
                        style="color: #F5F0E8"
                        @click.stop="dismiss"
                    >&times;</button>
                </div>
                <div class="mt-3 flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold" style="background-color: #C9A84C; color: #0d0d0d">
                    Ver encomenda
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
                </div>
            </div>
        </transition>
    </div>
</template>

<style scoped>
.toast-card:hover { border-color: #C9A84C !important; }
.toast-enter-active { transition: transform 0.3s ease, opacity 0.3s ease; }
.toast-leave-active { transition: opacity 0.3s ease; }
.toast-enter-from { transform: translateX(110%); opacity: 0; }
.toast-leave-to { opacity: 0; }
</style>
