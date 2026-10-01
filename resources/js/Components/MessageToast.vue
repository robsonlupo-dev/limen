<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { useNotificationSound } from '@/composables/useNotificationSound'

/**
 * Toast global de mensagem recebida (Sprint 15, PR #144). Registrado no AppLayout,
 * escuta o canal privado `user.{id}` (Reverb, evento `new.message`) e mostra um
 * popup no canto inferior direito — foto/alias do remetente + "Enviou uma
 * mensagem" + "Ler mensagem". Estilo Seeking.
 *
 * Privacidade: o `sender_name`/`sender_avatar_url` já vêm mascarados do backend
 * (à performer, FanAlias label + avatar nulo — nunca o nome/foto reais do membro).
 * O `preview` respeita o paywall NO SERVIDOR: quem lê recebe o trecho normal, quem
 * não pagou recebe só o GANCHO (teaser cortado no backend — nunca o corpo
 * completo). Sem preview, cai em "Enviou uma mensagem". Só aparece para uma
 * mensagem NOVA da outra parte (`increments_unread`) e some quando o membro já está
 * NA conversa daquele remetente (não notifica o que ele está lendo).
 */
const page = usePage()
const { play } = useNotificationSound()

const toasts = ref([])
const MAX_TOASTS = 3
const AUTO_DISMISS_MS = 13000

let seq = 0
let channel = null
let handler = null

// Já estou vendo esta conversa? (não notifica a conversa aberta)
function onOpenConversation(conversationId) {
    return page.component === 'Chat/Show'
        && Number(page.props?.conversation?.id) === Number(conversationId)
}

function onNewMessage(e) {
    // Só mensagem NOVA da outra parte, e não a que já estou lendo.
    if (!e?.increments_unread) return
    if (onOpenConversation(e.conversation_id)) return

    // Som discreto junto do toast (respeita a preferência 'message'; silencioso
    // se o browser bloquear autoplay ou o usuário tiver desligado).
    play('message')

    const id = ++seq
    toasts.value.push({
        id,
        conversationId: e.conversation_id,
        name: e.sender_name ?? 'Nova mensagem',
        avatar: e.sender_avatar_url ?? null,
        // Gancho paywallado (teaser) ou trecho legível — o backend já decidiu qual.
        preview: e.preview ?? null,
        locked: !!e.locked,
        timer: setTimeout(() => dismiss(id), AUTO_DISMISS_MS),
    })

    // Máximo 3 empilhados: os mais antigos saem.
    while (toasts.value.length > MAX_TOASTS) {
        const oldest = toasts.value.shift()
        if (oldest?.timer) clearTimeout(oldest.timer)
    }
}

function dismiss(id) {
    const i = toasts.value.findIndex((t) => t.id === id)
    if (i === -1) return
    if (toasts.value[i].timer) clearTimeout(toasts.value[i].timer)
    toasts.value.splice(i, 1)
}

function open(toast) {
    dismiss(toast.id)
    router.visit(route('chat.show', toast.conversationId))
}

function initial(name) {
    return (name ?? '?').replace(/[^\p{L}\p{N}]/gu, '').charAt(0).toUpperCase() || '?'
}

onMounted(() => {
    const id = page.props?.auth?.user?.id
    // Sem Echo (Reverb não configurado em dev) degrada limpo: sem toast, nada quebra.
    if (!window.Echo || !id) return
    channel = window.Echo.private(`user.${id}`)
    handler = (e) => onNewMessage(e)
    channel.listen('.new.message', handler)
})

onBeforeUnmount(() => {
    // Remove SÓ o próprio callback (não `Echo.leave`) — o canal user.{id} é
    // compartilhado com a lista de conversas (Chat/Index); derrubá-lo mataria a
    // atualização em tempo real dela.
    if (channel && handler) channel.stopListening('.new.message', handler)
    toasts.value.forEach((t) => t.timer && clearTimeout(t.timer))
})
</script>

<template>
    <!-- Empilha no canto inferior direito, acima de tudo. No mobile ocupa a largura. -->
    <div class="pointer-events-none fixed bottom-4 right-4 left-4 sm:left-auto z-[100] flex w-auto sm:w-[27rem] flex-col gap-3">
        <transition-group name="toast">
            <!-- O CARD INTEIRO é clicável (abre a conversa) — alvo grande e óbvio. O ×
                 e o botão param a propagação para não disparar duas vezes. -->
            <div
                v-for="toast in toasts"
                :key="toast.id"
                role="button"
                tabindex="0"
                class="toast-card pointer-events-auto cursor-pointer rounded-2xl border p-5 shadow-2xl transition-colors"
                style="background-color: #0d0d0d; border-color: #262626; color: #F5F0E8"
                @click="open(toast)"
                @keydown.enter="open(toast)"
                @keydown.space.prevent="open(toast)"
            >
                <!-- Cabeçalho: etiqueta "Nova mensagem" + fechar. -->
                <div class="mb-3 flex items-center justify-between">
                    <span class="inline-flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide" style="color: #C9A84C">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" /><path d="m3 7 9 6 9-6" /></svg>
                        Nova mensagem
                    </span>
                    <button
                        type="button"
                        aria-label="Fechar"
                        class="-m-1 shrink-0 rounded p-1 text-xl leading-none opacity-60 hover:opacity-100"
                        style="color: #F5F0E8"
                        @click.stop="dismiss(toast.id)"
                    >
                        &times;
                    </button>
                </div>

                <div class="flex items-start gap-4">
                    <!-- Foto redonda 56px; placeholder com inicial quando não há avatar
                         (ex.: FanAlias do membro, que não tem foto visível à performer).
                         Pontinho dourado = não lida. -->
                    <div class="relative shrink-0">
                        <img
                            v-if="toast.avatar"
                            :src="toast.avatar"
                            alt=""
                            class="h-14 w-14 rounded-full object-cover"
                            @error="toast.avatar = null"
                        />
                        <div
                            v-else
                            class="flex h-14 w-14 items-center justify-center rounded-full text-lg font-semibold"
                            style="background-color: #262626; color: #C9A84C"
                        >{{ initial(toast.name) }}</div>
                        <span class="absolute -right-0.5 -top-0.5 h-3.5 w-3.5 rounded-full border-2" style="background-color: #C9A84C; border-color: #0d0d0d"></span>
                    </div>

                    <div class="min-w-0 flex-1">
                        <p class="truncate text-lg font-semibold" style="color: #C9A84C">{{ toast.name }}</p>
                        <!-- Gancho da mensagem quando há (teaser cortado no servidor p/
                             quem não pagou; trecho normal p/ quem lê). Sem preview, o genérico. -->
                        <p class="mt-1 line-clamp-2 text-sm" style="color: #F5F0E8; opacity: 0.9">{{ toast.preview || 'enviou uma mensagem para você' }}</p>
                    </div>
                </div>

                <!-- Ação primária: botão cheio, alvo grande (≥44px). Abrir conversa. -->
                <button
                    type="button"
                    class="mt-4 inline-flex min-h-[44px] w-full items-center justify-center gap-2 rounded-xl text-sm font-semibold transition-opacity hover:opacity-90"
                    style="background-color: #C9A84C; color: #0d0d0d"
                    @click.stop="open(toast)"
                >
                    Abrir conversa
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
                </button>
            </div>
        </transition-group>
    </div>
</template>

<style scoped>
/* Realce da borda ao passar o mouse (o card inteiro é clicável). */
.toast-card:hover {
    border-color: #C9A84C !important;
}

/* Slide-in da direita ao entrar, fade-out ao sair. */
.toast-enter-active {
    transition: transform 0.3s ease, opacity 0.3s ease;
}
.toast-leave-active {
    transition: opacity 0.3s ease;
    position: absolute;
    right: 0;
}
.toast-enter-from {
    transform: translateX(110%);
    opacity: 0;
}
.toast-leave-to {
    opacity: 0;
}
.toast-move {
    transition: transform 0.3s ease;
}
</style>
