<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Button from '@/Components/Button.vue'
import { postJson } from '@/lib/http'

const props = defineProps({
    conversation: { type: Object, required: true },
    messages: { type: Object, required: true }, // paginator: { data, current_page, last_page, total }
    access: { type: Object, required: true }, // { state, can_send, can_read, locked, days_remaining, expires_at }
    accessCost: { type: Number, required: true },
    balance: { type: Number, required: true },
})

const page = usePage()
const myId = computed(() => page.props.auth.user?.id)

// O backend entrega a página mais recente em ordem decrescente (id desc). Para o
// chat lemos de cima (mais antiga) para baixo (mais nova).
const orderedMessages = computed(() => [...props.messages.data].reverse())
const hasOlder = computed(() => props.messages.current_page < props.messages.last_page)

const draft = ref('')
const sending = ref(false)
const sendError = ref('')
const renewing = ref(false)
const renewError = ref('')
const scroller = ref(null)

const isMember = computed(() => ['none', 'active', 'grace', 'expired'].includes(props.access.state))
const showTimer = computed(() => props.access.state === 'active' && props.access.days_remaining !== null)
// Banner de expiração: só ao membro sem janela vigente. Assinante/performer nunca.
const showAccessBanner = computed(() => ['grace', 'expired', 'none'].includes(props.access.state))
const bannerCopy = computed(() => {
    if (props.access.state === 'grace') {
        return 'Seu acesso expirou. Renove para continuar lendo e enviando mensagens.'
    }
    if (props.access.state === 'expired') {
        return 'Seu acesso expirou e o histórico foi arquivado. Renove para reabrir a conversa.'
    }
    return 'Desbloqueie este chat para ler e enviar mensagens.'
})
const renewLabel = computed(() =>
    props.access.state === 'none'
        ? `Desbloquear acesso — ${props.accessCost} tokens`
        : `Renovar acesso — ${props.accessCost} tokens`,
)

function scrollToBottom() {
    nextTick(() => {
        const el = scroller.value
        if (el) el.scrollTop = el.scrollHeight
    })
}

function isMine(message) {
    return message.sender_id === myId.value
}

async function send() {
    const body = draft.value.trim()
    if (!body || sending.value) return

    sending.value = true
    sendError.value = ''
    try {
        await postJson(route('chat.messages.store', props.conversation.id), { body })
        draft.value = ''
        // Recarrega só as mensagens (corpo gateado no servidor) + estado/saldo.
        // A nossa própria mensagem entra pelo reload; o parceiro recebe via Echo.
        reloadThread()
    } catch (e) {
        sendError.value = e.data?.message ?? 'Não foi possível enviar. Tente novamente.'
    } finally {
        sending.value = false
    }
}

async function renew() {
    if (renewing.value) return
    renewing.value = true
    renewError.value = ''
    try {
        await postJson(route('chat.access.open', props.conversation.id), {
            idempotency_key: crypto.randomUUID(),
        })
        // Reabre o acesso: recarrega props (access/messages/balance).
        router.reload({ only: ['access', 'messages', 'balance'], onSuccess: scrollToBottom })
    } catch (e) {
        renewError.value = e.status === 422 && e.data?.reason === 'insufficient_balance'
            ? 'Saldo insuficiente. Compre tokens na sua carteira.'
            : (e.data?.message ?? 'Não foi possível renovar o acesso.')
    } finally {
        renewing.value = false
    }
}

function reloadThread() {
    router.reload({ only: ['messages', 'access', 'balance'], onSuccess: scrollToBottom })
}

function loadOlder() {
    // Carrega a página anterior (mais antiga) via visita Inertia preservando o
    // scroll. Simples: navega para ?page=n+1 (id desc → páginas maiores = mais
    // antigas). Mantém a rolagem para o usuário não perder o contexto.
    router.get(
        route('chat.show', props.conversation.id),
        { page: props.messages.current_page + 1 },
        { only: ['messages'], preserveState: true, preserveScroll: true },
    )
}

let channel = null

onMounted(() => {
    scrollToBottom()

    // Tempo real: assina o canal privado da conversa. Sem Echo (Reverb não
    // configurado) o chat segue funcional, só sem push — o reload no envio ainda
    // atualiza o próprio lado.
    if (window.Echo) {
        channel = window.Echo.private(`conversation.${props.conversation.id}`)
        // broadcastAs() = 'message.sent' → o ponto inicial ignora o namespace.
        channel.listen('.message.sent', (payload) => {
            // O broadcast traz só metadados (nunca o corpo). Recarrega o thread
            // pelo show(), que aplica o paywall de leitura server-side.
            if (payload.sender_id !== myId.value) reloadThread()
        })
    }
})

onBeforeUnmount(() => {
    if (channel) window.Echo?.leave(`conversation.${props.conversation.id}`)
})

// Nova mensagem própria/recarga → cola no fim.
watch(() => props.messages.data.length, scrollToBottom)
</script>

<template>
    <AppLayout :title="`Chat com ${conversation.performer.stage_name}`">
        <div class="max-w-2xl mx-auto px-4 sm:px-6 py-6 flex flex-col h-[calc(100vh-9rem)]">
            <!-- Cabeçalho -->
            <div class="flex items-center justify-between pb-4 border-b border-frame/60">
                <div class="flex items-center gap-3">
                    <Link :href="route('chat.index')" class="text-muted hover:text-cream transition-colors no-underline">←</Link>
                    <h1 class="font-serif text-xl text-cream">{{ conversation.performer.stage_name }}</h1>
                </div>
                <span
                    v-if="showTimer"
                    class="text-xs rounded-full border border-gold/30 bg-gold/5 px-3 py-1 text-gold"
                    :title="access.expires_at"
                >
                    Acesso expira em {{ access.days_remaining }} {{ access.days_remaining === 1 ? 'dia' : 'dias' }}
                </span>
                <span
                    v-else-if="access.state === 'subscriber'"
                    class="text-xs rounded-full border border-gold/30 bg-gold/5 px-3 py-1 text-gold"
                >
                    Chat livre · Círculo ativo
                </span>
            </div>

            <!-- Banner de expiração / desbloqueio -->
            <div
                v-if="showAccessBanner"
                class="mt-4 rounded-xl border border-gold/30 bg-gradient-to-br from-gold/10 to-transparent p-4 space-y-3"
            >
                <p class="text-sm text-cream">{{ bannerCopy }}</p>
                <p class="text-xs text-muted">
                    {{ accessCost }} tokens dão 30 dias de acesso. Seu saldo: <span class="text-gold">{{ balance }}</span> tokens.
                </p>
                <div class="flex items-center gap-3">
                    <Button variant="primary" size="sm" :loading="renewing" @click="renew">{{ renewLabel }}</Button>
                    <Link :href="route('wallet.index')" class="text-xs text-gold/70 hover:text-gold no-underline">
                        Comprar tokens
                    </Link>
                </div>
                <p v-if="renewError" class="text-xs text-danger">{{ renewError }}</p>
            </div>

            <!-- Lista de mensagens: min-h-0 é essencial num flex-col — sem ele o
                 flex-1 NÃO encolhe e o overflow-y-auto não rola (o compositor
                 seria empurrado p/ fora da viewport em vez de a área rolar). -->
            <div ref="scroller" class="flex-1 min-h-0 overflow-y-auto py-4 space-y-3">
                <div v-if="hasOlder" class="text-center">
                    <button class="text-xs text-gold/70 hover:text-gold transition-colors" @click="loadOlder">
                        Carregar mensagens anteriores
                    </button>
                </div>

                <p v-if="!access.can_read && orderedMessages.length === 0" class="text-center text-sm text-muted py-8">
                    Desbloqueie o acesso para ver as mensagens desta conversa.
                </p>
                <p v-else-if="orderedMessages.length === 0" class="text-center text-sm text-muted py-8">
                    Nenhuma mensagem ainda.
                </p>

                <div
                    v-for="m in orderedMessages"
                    :key="m.id"
                    class="flex"
                    :class="isMine(m) ? 'justify-end' : 'justify-start'"
                >
                    <!-- Mensagem bloqueada (grace): tarja "Pague para ler", sem corpo. -->
                    <div
                        v-if="m.locked"
                        class="relative max-w-[75%] rounded-2xl border border-frame bg-surface px-4 py-3 overflow-hidden"
                    >
                        <div class="blur-sm select-none text-sm text-muted">████████ ████ ██████</div>
                        <div class="absolute inset-0 flex items-center justify-center bg-background/40 backdrop-blur-sm">
                            <span class="text-xs text-gold flex items-center gap-1">🔒 Pague para ler</span>
                        </div>
                    </div>
                    <!-- Mensagem legível -->
                    <div
                        v-else
                        class="max-w-[75%] rounded-2xl px-4 py-2.5 text-sm whitespace-pre-line break-words"
                        :class="isMine(m)
                            ? 'bg-gold text-background rounded-br-sm'
                            : 'bg-surface border border-frame text-cream rounded-bl-sm'"
                    >
                        {{ m.body }}
                    </div>
                </div>
            </div>

            <!-- Compositor -->
            <div class="pt-3 border-t border-frame/60">
                <form v-if="access.can_send" class="flex items-end gap-2" @submit.prevent="send">
                    <textarea
                        v-model="draft"
                        rows="1"
                        maxlength="1000"
                        placeholder="Escreva uma mensagem…"
                        class="flex-1 resize-none rounded-xl border border-frame bg-surface px-4 py-3 text-sm text-cream placeholder:text-muted focus:outline-none focus:border-gold focus:ring-1 focus:ring-gold"
                        @keydown.enter.exact.prevent="send"
                    />
                    <Button type="submit" variant="primary" size="sm" :loading="sending" :disabled="!draft.trim()">
                        Enviar
                    </Button>
                </form>
                <p v-else class="text-center text-xs text-muted py-2">
                    {{ access.state === 'grace' || access.state === 'expired'
                        ? 'Renove o acesso acima para voltar a enviar mensagens.'
                        : 'Desbloqueie o acesso acima para enviar mensagens.' }}
                </p>
                <p v-if="sendError" class="text-xs text-danger text-center mt-1">{{ sendError }}</p>
            </div>
        </div>
    </AppLayout>
</template>
