<script setup>
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { Room, RoomEvent, Track } from 'livekit-client'
import { getJson, postJson } from '@/lib/http'
import InCallBuyPanel from '@/Components/InCallBuyPanel.vue'
import TipModal from '@/Components/TipModal.vue'
import GiftModal from '@/Components/GiftModal.vue'
import GiftIcon from '@/Components/GiftIcon.vue'
import TokenCoin from '@/Components/TokenCoin.vue'

/**
 * Chamada privada 1:1 (Sprint 15) — sala de vídeo bidirecional. Os dois lados
 * publicam e assinam. O membro dirige a cobrança pelo heartbeat (a cada 60s) e
 * renova o JWT antes do TTL de 5min. O room_name nunca chega aqui — a sala vem
 * DENTRO do token.
 *
 * Encerramento por saldo (UX do § 3): banner discreto ≤3min, amarelo ≤1min, e
 * quando o heartbeat devolve can_continue:false a sessão já foi encerrada no
 * servidor — mostra a despedida por 10s e desconecta. A performer NUNCA vê o
 * financeiro (M.13.10): para ela o componente é o mesmo, sem banners de saldo.
 */
const props = defineProps({
    // 0 = ainda não conhecido. Na chamada AGENDADA (feat/scheduled-call-v1) a
    // performer entra na sala ANTES de a call_session existir; o call_id chega por
    // broadcast quando o membro entra, e o pai atualiza esta prop. refresh()/end()
    // ficam sem efeito de rede até ela ser conhecida (a janela pré-membro é < TTL).
    callId: { type: Number, default: 0 },
    token: { type: String, required: true },
    wsUrl: { type: String, required: true },
    // Só o lado do MEMBRO recebe estes — a performer não vê saldo/preço.
    role: { type: String, default: 'member' }, // 'member' | 'performer'
    pricePerMinute: { type: Number, default: 0 },
    initialBalance: { type: Number, default: 0 },
    // Compra SOBRE a chamada (feat/private-call-from-live). Vazio → o botão de
    // recarga cai no comportamento antigo (emit 'recharge') — retrocompatível com o
    // uso da chamada agendada.
    tokenPackages: { type: Array, default: () => [] },
    needsCpf: { type: Boolean, default: false },
    // Gorjeta/presente DENTRO da chamada (feat/in-call-gift-tip). Só o MEMBRO envia;
    // sem slug os botões não aparecem (ex.: lado da performer, que não envia). O
    // nome é só rótulo dos modais.
    performerSlug: { type: String, default: '' },
    performerName: { type: String, default: '' },
})

const emit = defineEmits(['ended', 'recharge'])

const page = usePage()

const localVideo = ref(null)
const remoteVideo = ref(null)
const remoteAudio = ref(null)
const status = ref('connecting') // connecting | live | ending | ended
const elapsedSeconds = ref(0)
const balance = ref(props.initialBalance)
const minutesLeft = ref(props.pricePerMinute > 0 ? Math.floor(props.initialBalance / props.pricePerMinute) : 0)
const endedNotice = ref('')
const showBuyPanel = ref(false)
const purchasePending = ref(false)
// Controles de mídia local (mudo de microfone/câmera). Começam ligados — o
// enableLocalMedia() publica os dois no connect.
const micOn = ref(true)
const camOn = ref(true)

const isMember = computed(() => props.role === 'member')
const canBuyInCall = computed(() => isMember.value && props.tokenPackages.length > 0)
// Gorjeta/presente na chamada: só o membro, e só com o slug da performer em mãos.
const canSendGifts = computed(() => isMember.value && !!props.performerSlug)
const showTipModal = ref(false)
const showGiftModal = ref(false)
// Reação cosmética na tela (gorjeta/presente). O movimento de token é 100%
// servidor (Tip/GiftService); isto é só o "oba" visual. O membro vê a própria no
// envio; a performer vê a dela pelo canal de dados do LiveKit — payload
// NÃO-sensível (tipo + valor/presente), nunca member_id/saldo/tier (M.13.10).
const callReaction = ref(null) // { kind:'tip'|'gift', amount?, giftSlug?, label }

// Chat de texto da chamada (feat/in-call-chat). Painel recolhível, fechado por
// padrão, para não atrapalhar o vídeo. As mensagens ficam no servidor para a
// moderação; aqui só enquanto a chamada está viva. Os dois lados usam.
const showChat = ref(false)
const chatMessages = ref([])
const chatInput = ref('')
const chatSending = ref(false)
const chatError = ref('')
const chatLoaded = ref(false)
const hasUnseen = ref(false)
const chatLog = ref(null)
const isPerformerSide = computed(() => props.role === 'performer')
// Segundos até o próximo minuto ser cobrado — quando o saldo não cobre o próximo
// minuto (minutesLeft==0), é a contagem regressiva até a chamada encerrar.
const secondsToBoundary = computed(() => 60 - (elapsedSeconds.value % 60))

let room = null
let heartbeatTimer = null
let refreshTimer = null
let clockTimer = null
let goodbyeTimer = null
let reactionTimer = null
let reactionChannel = null
let chatChannel = null
// finish() só pode rodar uma vez — vários caminhos (Encerrar, saldo, a outra
// pessoa sai, sala fechada, botão Fechar) podem convergir aqui; sem a trava o
// 'ended' seria emitido mais de uma vez para o pai.
let finished = false

const timerLabel = computed(() => {
    const m = Math.floor(elapsedSeconds.value / 60)
    const s = elapsedSeconds.value % 60
    return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
})

// Banner de saldo — SÓ o membro (a performer nunca vê o financeiro — M.13.10).
const balanceBanner = computed(() => {
    if (!isMember.value || status.value !== 'live') return null
    if (minutesLeft.value <= 0) {
        // CRÍTICO: o saldo não cobre o PRÓXIMO minuto — a chamada encerra no fim do
        // minuto atual. Contagem regressiva + comprar. Aparece assim que minutesLeft
        // zera (o heartbeat imediato no início já traz isso), dando ~1 minuto de
        // margem para comprar antes de cair (bem mais que os ~30s da spec).
        return { level: 'critical', text: `Seu saldo acaba em ${secondsToBoundary.value}s. Compre tokens para não cair.` }
    }
    if (minutesLeft.value <= 1) {
        return { level: 'warn', text: 'Último minuto. Compre tokens para continuar.' }
    }
    if (minutesLeft.value <= 3) {
        return { level: 'soft', text: `Seu saldo cobre mais ${minutesLeft.value} minutos.` }
    }
    return null
})

function recomputeMinutesLeft() {
    minutesLeft.value = props.pricePerMinute > 0 ? Math.floor(balance.value / props.pricePerMinute) : 0
}

// Abre o painel de compra SOBRE a chamada (sem cair). Sem pacotes (chamada agendada)
// → cai no emit 'recharge' antigo.
function openBuy() {
    if (canBuyInCall.value) {
        showBuyPanel.value = true
    } else {
        emit('recharge')
    }
}

// Pagamento compensou (webhook): saldo novo vale NA HORA, o aviso some, o painel
// fecha — sem recarregar. O próximo minuto passa a ser cobrado normalmente.
function onCredited(newBalance) {
    balance.value = newBalance
    recomputeMinutesLeft()
    purchasePending.value = false
    showBuyPanel.value = false
}

function attach(track, participantIsLocal) {
    if (track.kind === 'video') {
        const el = participantIsLocal ? localVideo.value : remoteVideo.value
        if (el) track.attach(el)
    }
    if (track.kind === 'audio' && !participantIsLocal && remoteAudio.value) {
        track.attach(remoteAudio.value)
    }
}

async function connect() {
    room = new Room()
    room.on(RoomEvent.TrackSubscribed, (track) => attach(track, false))
    // A OUTRA pessoa saiu da sala: encerra graciosamente deste lado também, para
    // ninguém ficar preso numa tela preta esperando uma contraparte que já foi.
    room.on(RoomEvent.ParticipantDisconnected, () => {
        if (status.value === 'live') beginGoodbye()
    })
    // FUI desconectado (a sala foi fechada no fim da chamada, ou a rede caiu).
    // Antes isto só marcava 'ended' em silêncio — sem aviso e sem emitir 'ended'
    // para o pai, então o overlay ficava preto e preso. Agora vai pela despedida
    // (aviso + botão Fechar + auto-encerramento), nunca sem saída.
    room.on(RoomEvent.Disconnected, () => {
        if (status.value === 'live') beginGoodbye()
    })

    await room.connect(props.wsUrl, props.token)
    await enableLocalMedia()

    const camPub = room.localParticipant.getTrackPublication(Track.Source.Camera)
    if (camPub?.track) attach(camPub.track, true)

    room.remoteParticipants.forEach((p) =>
        p.trackPublications.forEach((pub) => { if (pub.track) attach(pub.track, false) }),
    )

    status.value = 'live'
    startTimers()
}

/**
 * Publica câmera+mic com RETRY (fix/live-call-flow-states, bug 1). No caminho da
 * live→chamada, a performer entra nesta sala com a MESMA câmera física que a sala
 * pública acabou de liberar; em alguns navegadores o device demora um instante e
 * a 1ª aquisição falha (NotReadableError/AbortError) — a performer publicaria
 * NADA e o membro veria PRETO. Tenta de novo com um pequeno intervalo antes de
 * desistir. Sem lib nova. (O membro usa outro device — para ele o retry é inócuo.)
 */
async function enableLocalMedia() {
    let lastErr = null
    for (let attempt = 0; attempt < 3; attempt++) {
        try {
            await room.localParticipant.setCameraEnabled(true)
            await room.localParticipant.setMicrophoneEnabled(true)
            return
        } catch (e) {
            lastErr = e
            await new Promise((resolve) => setTimeout(resolve, 350))
        }
    }
    throw lastErr
}

function startTimers() {
    clockTimer = setInterval(() => { elapsedSeconds.value += 1 }, 1000)
    // Renova o JWT a cada 4min (antes do TTL de 5). Reautoriza na leitura.
    refreshTimer = setInterval(refresh, 4 * 60 * 1000)
    // O heartbeat/cobrança é do MEMBRO; a performer não cobra ninguém. Roda UM
    // heartbeat IMEDIATO no CONNECT: é ele que faz o lazy-start no servidor
    // (started_at + minuto 1) — desde fix/live-call-flow-states o minuto 1 é pago
    // AQUI, quando o vídeo conecta, e não mais no accept. Também traz saldo/minutos
    // já no início: se o saldo não cobre o próximo minuto, o aviso aparece na hora.
    if (isMember.value) {
        heartbeat()
        heartbeatTimer = setInterval(heartbeat, 60 * 1000)
    }
}

async function heartbeat() {
    try {
        const r = await postJson(route('call.heartbeat', props.callId))
        balance.value = r.balance_remaining
        minutesLeft.value = r.minutes_left
        if (r.can_continue === false) {
            beginGoodbye('Seu saldo acabou.')
        }
    } catch (e) {
        // 410/404 = sessão encerrada do lado do servidor.
        beginGoodbye()
    }
}

async function refresh() {
    // Sem call_id ainda (performer esperando o membro na chamada agendada): não há o
    // que renovar por rota. A janela pré-membro é curta (< TTL); quando o membro
    // entra, o call_id chega por broadcast e a próxima renovação já o usa.
    if (!props.callId) return
    try {
        await postJson(route('call.token-refresh', props.callId))
    } catch (e) {
        beginGoodbye()
    }
}

// Despedida elegante (§ 3): 10s de mensagem e desconecta. A sessão já está
// encerrada no servidor quando chegamos aqui.
function beginGoodbye(reason = '') {
    if (status.value === 'ending' || status.value === 'ended') return
    status.value = 'ending'
    stopBillingTimers()
    let msg = isMember.value
        ? (reason ? `${reason} A sessão foi encerrada. Obrigado pela companhia.` : 'A sessão foi encerrada. Obrigado pela companhia.')
        : 'O membro encerrou a sessão.'
    // Encerrou com um PIX em andamento (o relógio venceu o pagamento): os tokens
    // ainda entram na carteira quando compensar — deixa claro que nada se perde.
    if (isMember.value && purchasePending.value) {
        msg += ' Seu pagamento em andamento será creditado na sua carteira assim que o PIX compensar — nada se perde. Com saldo, é só pedir a chamada de novo.'
    }
    endedNotice.value = msg
    goodbyeTimer = setTimeout(finish, 10 * 1000)
}

async function endCall() {
    // Encerramento voluntário por este lado.
    status.value = 'ending'
    stopBillingTimers()
    // Sem call_id (performer saindo antes de o membro entrar): só desconecta local —
    // não há sessão para encerrar por rota; o cron marca no-show do membro.
    if (props.callId) {
        try { await postJson(route('call.end', props.callId)) } catch (e) { /* idempotente */ }
    }
    await finish()
}

async function finish() {
    if (finished) return
    finished = true
    await teardown()
    status.value = 'ended'
    emit('ended')
}

// Mudo de microfone / câmera. Vale para os DOIS lados (membro e performer). O
// estado só vira se o LiveKit confirmar — assim o ícone nunca mente.
async function toggleMic() {
    if (!room) return
    const next = !micOn.value
    try {
        await room.localParticipant.setMicrophoneEnabled(next)
        micOn.value = next
    } catch (e) { /* mantém o estado atual */ }
}

async function toggleCam() {
    if (!room) return
    const next = !camOn.value
    try {
        await room.localParticipant.setCameraEnabled(next)
        camOn.value = next
    } catch (e) { /* mantém o estado atual */ }
}

// --- Gorjeta / presente na chamada -------------------------------------------
// O modal já fez o POST (Tip/GiftService, débito+crédito+split no servidor) e
// devolve o novo saldo. Atualiza o saldo local NA HORA (o banner de minutos não
// fica defasado até o próximo heartbeat) e anima a reação deste lado (o membro).
// A PERFORMER recebe a mesma reação pelo servidor (`.call.reaction`).
function onTipSent(data) {
    if (data && typeof data.new_balance === 'number') {
        balance.value = data.new_balance
        recomputeMinutesLeft()
    }
    const amount = Number(data?.amount) || 0
    showReaction({ kind: 'tip', amount, label: `Gorjeta · ${amount} tokens` })
}

function onGiftSent(data) {
    if (data && typeof data.new_balance === 'number') {
        balance.value = data.new_balance
        recomputeMinutesLeft()
    }
    const giftSlug = data?.gift_slug || ''
    const name = data?.gift_name || 'Presente'
    showReaction({ kind: 'gift', giftSlug, label: `Presente · ${name}` })
}

// A PERFORMER recebe a reação pelo canal pessoal `user.{id}` (evento
// `.call.reaction`, disparado no servidor por Tip/GiftService) — o mesmo trilho
// confiável do CallEnded, e não um pacote cliente-a-cliente que pode cair. O
// valor/presente vem do servidor (nunca forjável). Só o lado da performer assina;
// o membro já vê a própria reação local no envio.
function subscribeReactions() {
    if (isMember.value) return // só a performer assina (o membro vê a reação local)
    const uid = page.props?.auth?.user?.id
    if (!uid || !window.Echo) return
    reactionChannel = window.Echo.private(`user.${uid}`)
    reactionChannel.listen('.call.reaction', onCallReaction)
}

function onCallReaction(payload) {
    if (!payload) return
    if (payload.type === 'tip') {
        const amount = Number(payload.amount) || 0
        showReaction({ kind: 'tip', amount, label: `Gorjeta · ${amount} tokens` })
    } else if (payload.type === 'gift') {
        showReaction({ kind: 'gift', giftSlug: String(payload.gift_slug || ''), label: `Presente · ${String(payload.gift_name || 'Presente')}` })
    }
}

// Sai SÓ do próprio callback — o canal user.{id} é compartilhado (CallIncoming,
// lista de conversas…); um Echo.leave calaria os irmãos.
function unsubscribeReactions() {
    if (reactionChannel) { reactionChannel.stopListening('.call.reaction', onCallReaction); reactionChannel = null }
}

// --- Chat de texto da chamada -------------------------------------------------
// Canal `call.{id}` (privado aos 2 participantes). É exclusivo desta chamada e só
// o PrivateCall o usa, então o teardown pode dar Echo.leave sem calar ninguém.
function subscribeChat() {
    if (!props.callId || !window.Echo || chatChannel) return
    chatChannel = window.Echo.private(`call.${props.callId}`)
    chatChannel.listen('.call.chat', onChatMessage)
}

function unsubscribeChat() {
    if (chatChannel && props.callId) { window.Echo?.leave(`call.${props.callId}`); chatChannel = null }
}

// Minha mensagem? `is_performer` do payload casa com o meu papel.
function isMine(msg) {
    return !!msg.is_performer === isPerformerSide.value
}

function appendMessage(msg) {
    if (!msg || chatMessages.value.some((m) => m.id === msg.id)) return
    chatMessages.value.push(msg)
    scrollChatSoon()
}

function onChatMessage(msg) {
    appendMessage(msg)
    if (!showChat.value && !isMine(msg)) hasUnseen.value = true
}

async function toggleChat() {
    showChat.value = !showChat.value
    if (!showChat.value) return
    hasUnseen.value = false
    if (!chatLoaded.value) await loadChatHistory()
    scrollChatSoon()
}

async function loadChatHistory() {
    try {
        const r = await getJson(route('call.chat.index', props.callId))
        ;(r.messages || []).forEach(appendMessage)
        chatLoaded.value = true
    } catch (e) { /* silencioso — o painel abre vazio e recebe em tempo real */ }
}

async function sendChat() {
    const body = chatInput.value.trim()
    if (!body || chatSending.value) return
    chatError.value = ''
    chatSending.value = true
    try {
        const r = await postJson(route('call.chat.store', props.callId), { body })
        chatInput.value = ''
        // Aparece já deste lado; o broadcast de volta é deduplicado por id.
        if (r.message) appendMessage(r.message)
    } catch (e) {
        chatError.value = e.data?.message ?? 'Não foi possível enviar a mensagem.'
    } finally {
        chatSending.value = false
    }
}

function scrollChatSoon() {
    nextTick(() => { const el = chatLog.value; if (el) el.scrollTop = el.scrollHeight })
}

// Mostra UMA reação por vez por ~3,2s (a nova substitui a anterior). Sem fila: na
// chamada 1:1 o volume é baixo; a simplicidade vale mais que a coreografia da live.
function showReaction(reaction) {
    callReaction.value = reaction
    if (reactionTimer) clearTimeout(reactionTimer)
    reactionTimer = setTimeout(() => { callReaction.value = null; reactionTimer = null }, 3200)
}

function stopBillingTimers() {
    if (heartbeatTimer) { clearInterval(heartbeatTimer); heartbeatTimer = null }
    if (refreshTimer) { clearInterval(refreshTimer); refreshTimer = null }
}

async function teardown() {
    stopBillingTimers()
    unsubscribeReactions()
    unsubscribeChat()
    if (clockTimer) { clearInterval(clockTimer); clockTimer = null }
    if (goodbyeTimer) { clearTimeout(goodbyeTimer); goodbyeTimer = null }
    if (reactionTimer) { clearTimeout(reactionTimer); reactionTimer = null }
    if (room) { await room.disconnect(); room = null }
}

// Na chamada agendada a performer entra antes de o call_id existir; ele chega por
// broadcast e o pai atualiza a prop. Quando mudar, (re)assina o canal do chat.
watch(() => props.callId, (id, old) => {
    if (id && id !== old) { unsubscribeChat(); subscribeChat() }
})

onMounted(async () => {
    subscribeReactions()
    subscribeChat()
    try {
        await connect()
    } catch (e) {
        status.value = 'ended'
        endedNotice.value = 'Não foi possível conectar à chamada.'
    }
})

onBeforeUnmount(teardown)
</script>

<template>
    <div class="space-y-3">
        <div class="relative overflow-hidden rounded-xl border border-frame bg-black aspect-video">
            <!-- Vídeo remoto (o outro lado) preenche a tela. -->
            <video ref="remoteVideo" autoplay playsinline class="h-full w-full object-contain" />
            <audio ref="remoteAudio" autoplay />

            <!-- Prévia local no canto. -->
            <video
                ref="localVideo"
                autoplay
                muted
                playsinline
                class="absolute bottom-3 right-3 h-24 w-32 rounded-lg border border-frame object-cover bg-black/60"
            />

            <!-- Timer sempre visível. -->
            <div class="absolute top-3 left-3 rounded-full bg-black/60 px-3 py-1 text-sm font-medium text-white tabular-nums">
                {{ timerLabel }}
            </div>

            <!-- Reação de gorjeta/presente (cosmética). Centralizada, sem capturar
                 clique; some sozinha. Os dois lados veem (membro no envio, performer
                 pelo canal de dados). -->
            <div
                v-if="callReaction"
                class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center gap-2"
            >
                <span class="call-reaction-pop inline-block h-24 w-24 text-gold drop-shadow-lg">
                    <GiftIcon v-if="callReaction.kind === 'gift'" :slug="callReaction.giftSlug" />
                    <span v-else class="grid h-full w-full place-items-center text-gold">
                        <TokenCoin class="h-[85%] w-[85%]" />
                    </span>
                </span>
                <span class="rounded-full bg-black/60 px-3 py-1 text-sm font-medium text-white">
                    {{ callReaction.label }}
                </span>
            </div>

            <!-- Despedida / encerramento. Sempre com um botão Fechar: mesmo que o
                 auto-encerramento falhe, nunca se fica preso na tela preta. -->
            <div
                v-if="status === 'ending' || status === 'ended'"
                class="absolute inset-0 flex flex-col items-center justify-center gap-4 bg-black/80 p-6 text-center text-white"
            >
                <p class="max-w-sm text-lg">{{ endedNotice || 'A chamada foi encerrada.' }}</p>
                <button
                    type="button"
                    class="rounded-lg bg-white/10 px-5 py-2 font-medium text-white ring-1 ring-white/30 hover:bg-white/20"
                    @click="finish"
                >
                    Fechar
                </button>
            </div>

            <!-- Compra SOBRE o vídeo (feat/private-call-from-live): overlay no rodapé.
                 NÃO cobre o vídeo inteiro nem empurra o layout (absolute); a chamada
                 segue rodando por trás. Mobile: faixa inferior; desktop: canto direito. -->
            <div
                v-if="showBuyPanel"
                class="absolute inset-x-2 bottom-2 max-h-[78%] sm:inset-x-auto sm:right-2 sm:w-80"
            >
                <InCallBuyPanel
                    :packages="tokenPackages"
                    :needs-cpf="needsCpf"
                    @credited="onCredited"
                    @pending-changed="(p) => (purchasePending = p)"
                    @close="showBuyPanel = false"
                />
            </div>

            <!-- Chat de texto SOBRE o vídeo: painel recolhível que NÃO cobre o vídeo
                 inteiro. Mobile: faixa inferior; desktop: coluna à direita. Começa
                 fechado (o botão "Chat" nos controles abre). -->
            <div
                v-if="showChat"
                class="absolute inset-x-2 bottom-2 flex max-h-[72%] flex-col overflow-hidden rounded-xl border border-frame bg-black/85 text-white backdrop-blur sm:inset-x-auto sm:right-2 sm:top-2 sm:bottom-2 sm:w-72"
            >
                <div class="flex items-center justify-between border-b border-white/10 px-3 py-2">
                    <span class="text-sm font-medium">Chat</span>
                    <button type="button" class="text-white/70 hover:text-white" title="Fechar chat" @click="showChat = false">✕</button>
                </div>
                <div ref="chatLog" class="flex-1 space-y-2 overflow-y-auto px-3 py-2">
                    <p v-if="chatMessages.length === 0" class="py-6 text-center text-xs text-white/50">
                        Nenhuma mensagem ainda. Diga um oi.
                    </p>
                    <div v-for="m in chatMessages" :key="m.id" :class="isMine(m) ? 'text-right' : 'text-left'">
                        <span
                            :class="[
                                'inline-block max-w-[85%] rounded-2xl px-3 py-1.5 text-left text-sm',
                                isMine(m) ? 'bg-gold/90 text-background' : 'bg-white/10 text-white',
                            ]"
                        >
                            <span class="block text-[10px] uppercase tracking-wide opacity-70">{{ isMine(m) ? 'Você' : m.label }}</span>
                            <span class="whitespace-pre-wrap break-words">{{ m.body }}</span>
                        </span>
                    </div>
                </div>
                <form class="border-t border-white/10 p-2" @submit.prevent="sendChat">
                    <p v-if="chatError" class="px-1 pb-1 text-xs text-danger">{{ chatError }}</p>
                    <div class="flex items-end gap-2">
                        <textarea
                            v-model="chatInput"
                            rows="1"
                            maxlength="500"
                            placeholder="Mensagem…"
                            class="min-h-[38px] max-h-24 flex-1 resize-none rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-sm text-white placeholder-white/40 focus:border-gold focus:outline-none"
                            @keydown.enter.exact.prevent="sendChat"
                        />
                        <button
                            type="submit"
                            :disabled="chatSending || !chatInput.trim()"
                            class="shrink-0 rounded-lg bg-gold px-3 py-2 text-sm font-semibold text-background hover:bg-gold/90 disabled:opacity-50"
                        >
                            Enviar
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Banner de saldo — SÓ o membro. `critical` (não cobre o próximo minuto) é o
             aviso forte com contagem + comprar; `warn`/`soft` são os discretos. -->
        <div
            v-if="balanceBanner"
            :class="[
                'flex items-center justify-between gap-3 rounded-lg px-4 py-2 text-sm',
                balanceBanner.level === 'critical'
                    ? 'bg-danger/15 text-danger ring-1 ring-danger/40'
                    : balanceBanner.level === 'warn'
                        ? 'bg-amber-100 text-amber-900'
                        : 'bg-neutral-100 text-neutral-700',
            ]"
        >
            <span aria-live="polite">{{ balanceBanner.text }}</span>
            <button
                v-if="balanceBanner.level === 'critical' || balanceBanner.level === 'warn'"
                type="button"
                class="mi-press shrink-0 rounded-md bg-gold px-3 py-1 font-semibold text-background hover:bg-gold/90"
                @click="openBuy"
            >
                Comprar tokens
            </button>
        </div>

        <!-- Controles. Mudo de microfone e câmera valem para os dois lados. -->
        <div v-if="status === 'live'" class="flex items-center gap-3">
            <button
                type="button"
                :aria-pressed="!micOn"
                :title="micOn ? 'Silenciar microfone' : 'Reativar microfone'"
                :class="[
                    'rounded-lg border px-4 py-2 font-medium transition-colors',
                    micOn ? 'border-frame hover:bg-neutral-50' : 'border-danger/40 bg-danger/15 text-danger',
                ]"
                @click="toggleMic"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5" aria-hidden="true">
                    <rect x="9" y="3" width="6" height="11" rx="3" />
                    <path d="M5 11a7 7 0 0 0 14 0" />
                    <line x1="12" y1="18" x2="12" y2="21" />
                    <line v-if="!micOn" x1="3" y1="3" x2="21" y2="21" />
                </svg>
                <span class="sr-only">{{ micOn ? 'Silenciar microfone' : 'Reativar microfone' }}</span>
            </button>
            <button
                type="button"
                :aria-pressed="!camOn"
                :title="camOn ? 'Desligar câmera' : 'Ligar câmera'"
                :class="[
                    'rounded-lg border px-4 py-2 font-medium transition-colors',
                    camOn ? 'border-frame hover:bg-neutral-50' : 'border-danger/40 bg-danger/15 text-danger',
                ]"
                @click="toggleCam"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5" aria-hidden="true">
                    <rect x="3" y="6" width="13" height="12" rx="2" />
                    <path d="M16 10l5-3v10l-5-3" />
                    <line v-if="!camOn" x1="3" y1="3" x2="21" y2="21" />
                </svg>
                <span class="sr-only">{{ camOn ? 'Desligar câmera' : 'Ligar câmera' }}</span>
            </button>
            <button
                type="button"
                class="rounded-lg bg-red-600 px-4 py-2 font-medium text-white hover:bg-red-700"
                @click="endCall"
            >
                Encerrar
            </button>
            <button
                type="button"
                :title="showChat ? 'Fechar chat' : 'Abrir chat'"
                :class="[
                    'relative rounded-lg border px-4 py-2 font-medium transition-colors',
                    showChat ? 'border-gold bg-gold/10 text-gold' : 'border-frame hover:bg-neutral-50',
                ]"
                @click="toggleChat"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5" aria-hidden="true">
                    <path d="M21 11.5a8.38 8.38 0 0 1-8.5 8.5 8.5 8.5 0 0 1-3.8-.9L3 21l1.9-5.7A8.38 8.38 0 0 1 4 11.5 8.5 8.5 0 0 1 12.5 3 8.38 8.38 0 0 1 21 11.5z" />
                </svg>
                <span
                    v-if="hasUnseen && !showChat"
                    class="absolute -right-1 -top-1 h-3 w-3 rounded-full bg-gold ring-2 ring-background"
                    aria-hidden="true"
                />
                <span class="sr-only">{{ showChat ? 'Fechar chat' : 'Abrir chat' }}</span>
            </button>
            <button
                v-if="canSendGifts"
                type="button"
                title="Enviar gorjeta"
                class="rounded-lg border border-frame px-4 py-2 font-medium hover:bg-neutral-50"
                @click="showTipModal = true"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5" aria-hidden="true">
                    <circle cx="12" cy="12" r="9" />
                    <path d="M14.5 9.5a2.5 2.5 0 0 0-2.5-1.5c-1.4 0-2.5.8-2.5 2s1.1 1.7 2.5 2 2.5.9 2.5 2-1.1 2-2.5 2a2.5 2.5 0 0 1-2.5-1.5" />
                    <line x1="12" y1="6.5" x2="12" y2="8" />
                    <line x1="12" y1="16" x2="12" y2="17.5" />
                </svg>
                <span class="sr-only">Enviar gorjeta</span>
            </button>
            <button
                v-if="canSendGifts"
                type="button"
                title="Enviar presente"
                class="rounded-lg border border-frame px-4 py-2 font-medium hover:bg-neutral-50"
                @click="showGiftModal = true"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5" aria-hidden="true">
                    <rect x="3" y="8" width="18" height="4" rx="1" />
                    <path d="M5 12v9h14v-9" />
                    <line x1="12" y1="8" x2="12" y2="21" />
                    <path d="M12 8S10.5 3.5 8 4.5 9 8 12 8zM12 8s1.5-4.5 4-3.5S15 8 12 8z" />
                </svg>
                <span class="sr-only">Enviar presente</span>
            </button>
            <button
                v-if="isMember"
                type="button"
                class="rounded-lg border border-frame px-4 py-2 font-medium hover:bg-neutral-50"
                @click="openBuy"
            >
                Comprar tokens
            </button>
        </div>

        <!-- Modais de gorjeta/presente (só o membro). Autocontidos: fazem o POST,
             idempotência e o fluxo de saldo insuficiente. No 'sent' animamos e
             avisamos a performer. -->
        <template v-if="canSendGifts">
            <TipModal
                :show="showTipModal"
                :performer-slug="performerSlug"
                :performer-name="performerName"
                @close="showTipModal = false"
                @sent="onTipSent"
            />
            <GiftModal
                :show="showGiftModal"
                :performer-slug="performerSlug"
                :performer-name="performerName"
                @close="showGiftModal = false"
                @sent="onGiftSent"
            />
        </template>
    </div>
</template>

<style scoped>
/* Reação da chamada: entra com um "pop", sustenta e sai — tudo em CSS, sem lib
   (ExternalAssetPolicyTest). Quem pede menos movimento vê só o fade. */
.call-reaction-pop {
    animation: call-reaction-pop 3.2s ease-out forwards;
}
@keyframes call-reaction-pop {
    0% { opacity: 0; transform: scale(0.6); }
    10% { opacity: 1; transform: scale(1.18); }
    22% { transform: scale(1); }
    82% { opacity: 1; transform: scale(1); }
    100% { opacity: 0; transform: scale(1.04) translateY(-8px); }
}
@media (prefers-reduced-motion: reduce) {
    .call-reaction-pop { animation: call-reaction-fade 3.2s ease-out forwards; }
    @keyframes call-reaction-fade {
        0%, 10% { opacity: 0; }
        12%, 82% { opacity: 1; }
        100% { opacity: 0; }
    }
}
</style>
