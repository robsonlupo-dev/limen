<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { Room, RoomEvent, Track } from 'livekit-client'
import { postJson } from '@/lib/http'
import InCallBuyPanel from '@/Components/InCallBuyPanel.vue'

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
})

const emit = defineEmits(['ended', 'recharge'])

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

const isMember = computed(() => props.role === 'member')
const canBuyInCall = computed(() => isMember.value && props.tokenPackages.length > 0)
// Segundos até o próximo minuto ser cobrado — quando o saldo não cobre o próximo
// minuto (minutesLeft==0), é a contagem regressiva até a chamada encerrar.
const secondsToBoundary = computed(() => 60 - (elapsedSeconds.value % 60))

let room = null
let heartbeatTimer = null
let refreshTimer = null
let clockTimer = null
let goodbyeTimer = null

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
    room.on(RoomEvent.Disconnected, () => {
        if (status.value === 'live') status.value = 'ended'
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
    await teardown()
    status.value = 'ended'
    emit('ended')
}

function stopBillingTimers() {
    if (heartbeatTimer) { clearInterval(heartbeatTimer); heartbeatTimer = null }
    if (refreshTimer) { clearInterval(refreshTimer); refreshTimer = null }
}

async function teardown() {
    stopBillingTimers()
    if (clockTimer) { clearInterval(clockTimer); clockTimer = null }
    if (goodbyeTimer) { clearTimeout(goodbyeTimer); goodbyeTimer = null }
    if (room) { await room.disconnect(); room = null }
}

onMounted(async () => {
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

            <!-- Despedida / encerramento. -->
            <div
                v-if="status === 'ending' || status === 'ended'"
                class="absolute inset-0 flex items-center justify-center bg-black/80 p-6 text-center text-white"
            >
                <p class="max-w-sm text-lg">{{ endedNotice }}</p>
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

        <!-- Controles. -->
        <div v-if="status === 'live'" class="flex items-center gap-3">
            <button
                type="button"
                class="rounded-lg bg-red-600 px-4 py-2 font-medium text-white hover:bg-red-700"
                @click="endCall"
            >
                Encerrar
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
    </div>
</template>
