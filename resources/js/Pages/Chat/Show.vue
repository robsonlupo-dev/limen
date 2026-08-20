<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Button from '@/Components/Button.vue'
import SharePhotoModal from '@/Components/SharePhotoModal.vue'
import { postJson } from '@/lib/http'

const props = defineProps({
    conversation: { type: Object, required: true },
    messages: { type: Object, required: true }, // paginator: { data, current_page, last_page, total }
    access: { type: Object, required: true }, // { state, can_send, can_read, locked, days_remaining, expires_at }
    // Gancho da última mensagem (teaser cortado no SERVIDOR) quando a leitura está
    // travada — nunca o corpo completo. null quando destravado ou sem mensagem.
    teaser: { type: String, default: null },
    accessCost: { type: Number, required: true },
    balance: { type: Number, required: true },
    // { can_share, photos: [{ id, expires_slot, shared_with }] }. Para a
    // performer vem sempre vazio — a tela dela não insinua nada sobre as fotos
    // do outro lado.
    photoSharing: { type: Object, default: () => ({ can_share: false, photos: [] }) },
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

// feat/chat-economy-v2: modo COMPOR — a conversa ainda não existe (o membro abriu
// o chat pela performer). O canal nasce e a cobrança acontece só no 1º envio.
const isComposeMode = computed(() => props.conversation.id === null)
const performerSlug = computed(() => props.conversation.performer.slug)

// O compositor aparece para a performer (can_send) E para o membro em QUALQUER
// estado (feat/chat-economy-v2: ele digita e paga ao enviar; não há mais botão de
// desbloquear ANTES de compor). Conversa arquivada não chega aqui como membro.
const showComposer = computed(() => props.access.can_send || isMember.value)

// Aviso de custo do envio: só ao membro sem janela vigente. Deixa claro que o
// próximo envio abre (e cobra) 30 dias — a cobrança passou do desbloqueio prévio
// para o ATO DO ENVIO.
const showCostHint = computed(() => isMember.value && ! props.access.can_send)

// Banner "pagar para ler": só quando há conteúdo travado a LER — a performer
// mandou algo (state 'none' com teaser) ou há histórico em carência/expirado.
// No modo compor (sem teaser, sem histórico) o banner some: o membro só compõe.
const showAccessBanner = computed(
    () => ['grace', 'expired'].includes(props.access.state)
        || (props.access.state === 'none' && props.teaser !== null),
)
const bannerCopy = computed(() => {
    if (props.access.state === 'grace') {
        return 'Seu acesso expirou. Pague para continuar lendo o histórico.'
    }
    if (props.access.state === 'expired') {
        return 'Seu acesso expirou e o histórico foi arquivado. Pague para reabrir a conversa.'
    }
    return 'Pague para ler as mensagens que você recebeu.'
})
const renewLabel = computed(() =>
    props.access.state === 'none'
        ? `Pagar para ler — ${props.accessCost} tokens`
        : `Renovar acesso — ${props.accessCost} tokens`,
)

// O botão só aparece quando os DOIS lados são verdade: janela de chat vigente
// e pelo menos uma foto ativa. Oferecer com uma das duas faltando levaria a um
// modal que só sabe dizer não — e o servidor recusaria de qualquer forma
// (MemberPhotoService::shareWith), porque a tela nunca é o guard.
const sharingOpen = ref(false)
const canOfferShare = computed(
    () => props.photoSharing.can_share && props.photoSharing.photos.length > 0,
)
const shareFeedback = ref('')

function onShared() {
    shareFeedback.value = 'Foto compartilhada.'
    // Recarrega o bloco para o contador "compartilhada com N performers" e a
    // lista refletirem o envio.
    router.reload({ only: ['photoSharing'] })
    setTimeout(() => (shareFeedback.value = ''), 4000)
}

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
        if (isComposeMode.value) {
            // Modo compor: o canal nasce agora. O backend cria a conversa, cobra o
            // tier no envio e devolve o id — navegamos para a conversa real.
            const res = await postJson(route('chat.start', performerSlug.value), { body })
            draft.value = ''
            router.visit(route('chat.show', res.conversation_id))
        } else {
            // Conversa existente: enviar cobra automaticamente se não houver janela
            // vigente (feat/chat-economy-v2). Recarrega só as mensagens (corpo
            // gateado no servidor) + estado/saldo; o parceiro recebe via Echo.
            await postJson(route('chat.messages.store', props.conversation.id), { body })
            draft.value = ''
            reloadThread()
        }
    } catch (e) {
        // Texto PRESERVADO no campo (não limpamos `draft` no erro): mensagem barrada
        // pelo filtro ou saldo insuficiente não perde o que foi digitado.
        sendError.value = e.status === 422 && e.data?.reason === 'insufficient_balance'
            ? 'Saldo insuficiente. Compre tokens na sua carteira para enviar.'
            : (e.data?.message ?? 'Não foi possível enviar. Tente novamente.')
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
        <!-- h-full na base do calc: no celular o rodapé fixo (barra de navegação)
             cobre ~6rem + safe-area; sem descontá-los, o compositor e a linha de
             custo ficam ATRÁS da barra (o saldo some). Desconta no mobile e usa dvh
             (barra do navegador móvel); no desktop não há rodapé fixo. -->
        <div class="max-w-2xl mx-auto px-4 sm:px-6 py-6 flex flex-col h-[calc(100dvh-9rem-6rem-env(safe-area-inset-bottom))] md:h-[calc(100vh-9rem)]">
            <!-- Cabeçalho: no retrato empilha (nome em cima, etiqueta abaixo) para o
                 nome longo não colidir com a etiqueta de expiração; lado a lado no
                 desktop. O nome trunca com reticências (min-w-0 deixa o flex encolher). -->
            <div class="flex flex-col gap-1.5 pb-4 border-b border-frame/60 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
                <div class="flex min-w-0 items-center gap-3">
                    <Link :href="route('chat.index')" class="shrink-0 text-muted hover:text-cream transition-colors no-underline" aria-label="Voltar às conversas">←</Link>
                    <h1 class="min-w-0 truncate font-serif text-xl text-cream">{{ conversation.performer.stage_name }}</h1>
                </div>
                <span
                    v-if="showTimer"
                    class="shrink-0 self-start text-xs rounded-full border border-gold/30 bg-gold/5 px-3 py-1 text-gold sm:self-auto"
                    :title="`Acesso expira em ${access.days_remaining} ${access.days_remaining === 1 ? 'dia' : 'dias'}`"
                >
                    Expira em {{ access.days_remaining }} {{ access.days_remaining === 1 ? 'dia' : 'dias' }}
                </span>
                <span
                    v-else-if="access.state === 'subscriber'"
                    class="shrink-0 self-start text-xs rounded-full border border-gold/30 bg-gold/5 px-3 py-1 text-gold sm:self-auto"
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
                <!-- Gancho: primeiras palavras da última mensagem (cortadas no
                     servidor) + convite ao desbloqueio. Só quando há teaser. -->
                <p v-if="teaser" class="rounded-lg bg-background/40 px-3 py-2 text-sm italic text-cream/90">
                    “{{ teaser }}”
                    <span class="not-italic text-gold/80">desbloqueie para ler o restante</span>
                </p>
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
            <div ref="scroller" class="messages-area flex-1 min-h-0 overflow-y-auto py-4 space-y-3">
                <div v-if="hasOlder" class="text-center">
                    <button class="text-xs text-gold/70 hover:text-gold transition-colors" @click="loadOlder">
                        Carregar mensagens anteriores
                    </button>
                </div>

                <p v-if="isComposeMode" class="text-center text-sm text-muted py-8">
                    Envie a primeira mensagem para {{ conversation.performer.stage_name }}.
                </p>
                <!-- Sem a linha redundante quando o card "Pagar para ler" já está
                     acima (item 6): dois textos dizendo o mesmo confundem. Só aparece
                     no caso raro de conversa sem card de pagamento e sem mensagens. -->
                <p v-else-if="!access.can_read && orderedMessages.length === 0 && !showAccessBanner" class="text-center text-sm text-muted py-8">
                    Pague para ver as mensagens desta conversa.
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
                    <div v-else class="max-w-[75%] flex flex-col" :class="isMine(m) ? 'items-end' : 'items-start'">
                        <div
                            class="rounded-2xl px-4 py-2.5 text-sm whitespace-pre-line break-words"
                            :class="isMine(m)
                                ? 'bg-gold text-background rounded-br-sm'
                                : 'bg-surface border border-frame text-cream rounded-bl-sm'"
                        >
                            {{ m.body }}
                        </div>
                        <!-- Confirmação de leitura: só nas minhas mensagens e só
                             quando de fato houve leitura confirmada. Ausência não
                             é "não leu" — pode ser leitor com read receipts
                             desligados (perk Black/FC) — por isso a UI não tem
                             estado "não lida": ou confirmou, ou não diz nada. -->
                        <span v-if="isMine(m) && m.read_at" class="pt-1 pr-1 text-[11px] text-muted">Lida</span>
                    </div>
                </div>
            </div>

            <!-- Compositor -->
            <div class="pt-3 border-t border-frame/60">
                <div v-if="canOfferShare" class="flex items-center justify-between pb-2">
                    <button
                        class="text-xs text-gold/80 hover:text-gold transition-colors"
                        @click="sharingOpen = true"
                    >
                        📷 Compartilhar foto
                    </button>
                    <span v-if="shareFeedback" class="text-xs text-muted">{{ shareFeedback }}</span>
                </div>

                <form v-if="showComposer" class="flex items-end gap-2" @submit.prevent="send">
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
                <!-- Custo mostrado ANTES da cobrança. Quando o card "Pagar para ler"
                     também aparece (a performer mandou algo), os dois caminhos abrem
                     A MESMA janela — o texto deixa explícito que é UMA cobrança só,
                     para o membro não achar que paga duas vezes. -->
                <p v-if="showCostHint && showAccessBanner" class="text-center text-xs text-muted pt-2">
                    Responder também abre os 30 dias — <span class="text-gold">{{ accessCost }}</span> tokens, cobrança única.
                    Seu saldo: <span class="text-gold">{{ balance }}</span> tokens.
                </p>
                <p v-else-if="showCostHint" class="text-center text-xs text-muted pt-2">
                    Ao enviar, <span class="text-gold">{{ accessCost }}</span> tokens abrem 30 dias de conversa.
                    Seu saldo: <span class="text-gold">{{ balance }}</span> tokens.
                </p>
                <p v-if="sendError" class="text-xs text-danger text-center mt-1">{{ sendError }}</p>
            </div>

            <SharePhotoModal
                :show="sharingOpen"
                :photos="photoSharing.photos"
                :performer-name="conversation.performer.stage_name"
                :performer-profile-id="conversation.performer.profile_id"
                @close="sharingOpen = false"
                @shared="onShared"
            />
        </div>
    </AppLayout>
</template>

<style scoped>
/* Scrollbar escura/discreta na área de mensagens — o padrão do SO (clara)
   destoa do tema. Chromium/Safari via ::-webkit-scrollbar; Firefox via as
   propriedades padrão scrollbar-width/scrollbar-color. */
.messages-area {
    scrollbar-width: thin;
    scrollbar-color: rgba(201, 162, 75, 0.2) transparent; /* gold/20 sobre trilho transparente */
}
.messages-area::-webkit-scrollbar {
    width: 4px;
}
.messages-area::-webkit-scrollbar-track {
    background: transparent;
}
.messages-area::-webkit-scrollbar-thumb {
    background: rgba(201, 162, 75, 0.2);
    border-radius: 2px;
}
.messages-area::-webkit-scrollbar-thumb:hover {
    background: rgba(201, 162, 75, 0.4);
}
</style>
