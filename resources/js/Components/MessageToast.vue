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
 * Privacidade: NUNCA mostra o corpo. O `sender_name`/`sender_avatar_url` já vêm
 * mascarados do backend (à performer, FanAlias label + avatar nulo — nunca o
 * nome/foto reais do membro). Só aparece para uma mensagem NOVA da outra parte
 * (`increments_unread`) e some quando o membro já está NA conversa daquele
 * remetente (não notifica o que ele está lendo).
 */
const page = usePage()
const { play } = useNotificationSound()

const toasts = ref([])
const MAX_TOASTS = 3
const AUTO_DISMISS_MS = 8000

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
    <!-- Empilha no canto inferior direito, acima de tudo. -->
    <div class="pointer-events-none fixed bottom-4 right-4 z-[100] flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-3">
        <transition-group name="toast">
            <div
                v-for="toast in toasts"
                :key="toast.id"
                class="pointer-events-auto flex items-center gap-3 rounded-xl border p-3 shadow-xl"
                style="background-color: #0d0d0d; border-color: #262626; color: #F5F0E8"
            >
                <!-- Foto redonda 40px; placeholder com inicial quando não há avatar
                     (ex.: FanAlias do membro, que não tem foto visível à performer). -->
                <img
                    v-if="toast.avatar"
                    :src="toast.avatar"
                    alt=""
                    class="h-10 w-10 shrink-0 rounded-full object-cover"
                    @error="toast.avatar = null"
                />
                <div
                    v-else
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-sm font-semibold"
                    style="background-color: #262626; color: #C9A84C"
                >{{ initial(toast.name) }}</div>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold" style="color: #C9A84C">{{ toast.name }}</p>
                    <p class="text-xs" style="color: #F5F0E8">Enviou uma mensagem</p>
                    <button
                        type="button"
                        class="mt-1 text-xs font-medium underline-offset-2 hover:underline"
                        style="color: #C9A84C"
                        @click="open(toast)"
                    >
                        Ler mensagem
                    </button>
                </div>

                <button
                    type="button"
                    aria-label="Fechar"
                    class="shrink-0 self-start rounded p-1 text-lg leading-none opacity-60 hover:opacity-100"
                    style="color: #F5F0E8"
                    @click="dismiss(toast.id)"
                >
                    &times;
                </button>
            </div>
        </transition-group>
    </div>
</template>

<style scoped>
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
