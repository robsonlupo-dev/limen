/**
 * Sons de notificação sintéticos (Sprint 16) — Web Audio API, ZERO assets.
 *
 * De propósito não há arquivo MP3/OGG nem `<audio src>`: cada som é um par de
 * osciladores gerado na hora, então nada trafega da rede e o ExternalAssetPolicyTest
 * ("zero terceiros em área logada") segue verde sem exceção nova. Também não há
 * blob binário no repo.
 *
 * Autoplay: navegadores só deixam o áudio soar depois de um gesto do usuário.
 * O AudioContext nasce suspenso; destravamos no primeiro clique/tecla/toque
 * (uma vez). Se um som dispara antes disso — evento em tempo real sem gesto
 * recente —, ele simplesmente não soa. Falha silenciosa é o comportamento
 * pedido: nunca quebra a UX, nunca lança.
 */

let ctx = null
let unlockBound = false

// Perfil de cada categoria: sequência de tons (freq em Hz, início/duração em s
// relativos ao disparo). Distintos entre si para o usuário reconhecer de ouvido.
const PROFILES = {
    // Chat: "ding" curto de dois tons ascendentes, discreto.
    message: [
        { freq: 660, at: 0, dur: 0.12 },
        { freq: 880, at: 0.1, dur: 0.16 },
    ],
    // Gorjeta/presente: brilho tipo moeda, mais alto e alegre.
    tip: [
        { freq: 880, at: 0, dur: 0.1 },
        { freq: 1175, at: 0.08, dur: 0.1 },
        { freq: 1568, at: 0.16, dur: 0.18 },
    ],
    // Chamada ao vivo: dois toques graves de atenção (padrão "ring" curto).
    live: [
        { freq: 523, at: 0, dur: 0.18 },
        { freq: 392, at: 0.22, dur: 0.22 },
    ],
}

const PEAK_GAIN = 0.06 // discreto de propósito — notificação, não alarme

function audioContextClass() {
    if (typeof window === 'undefined') return null
    return window.AudioContext || window.webkitAudioContext || null
}

function ensureContext() {
    if (ctx) return ctx
    const Ctor = audioContextClass()
    if (!Ctor) return null
    try {
        ctx = new Ctor()
    } catch {
        ctx = null
    }
    return ctx
}

function bindUnlock() {
    if (unlockBound || typeof window === 'undefined') return
    unlockBound = true
    const resume = () => {
        const c = ensureContext()
        // resume() pode rejeitar (contexto já fechado); engolir sem barulho.
        if (c && c.state === 'suspended') c.resume().catch(() => {})
    }
    // `once` por tipo: o primeiro gesto destrava e o listener se remove sozinho.
    ;['pointerdown', 'keydown', 'touchstart'].forEach((ev) => {
        window.addEventListener(ev, resume, { once: true, passive: true })
    })
}

/**
 * Toca a categoria pedida ('message' | 'tip' | 'live'). Não checa preferência —
 * quem chama já decidiu (ver useNotificationSound). Silencioso em qualquer
 * falha (sem Web Audio, contexto suspenso, categoria desconhecida).
 */
export function playNotificationSound(kind) {
    const profile = PROFILES[kind]
    if (!profile) return

    const c = ensureContext()
    if (!c) return

    // Suspenso (sem gesto ainda) → tenta destravar para a PRÓXIMA vez e sai
    // quieto agora, em vez de agendar um som que soaria fora de hora.
    if (c.state === 'suspended') {
        c.resume().catch(() => {})
        return
    }

    try {
        const now = c.currentTime
        for (const note of profile) {
            const osc = c.createOscillator()
            const gain = c.createGain()
            osc.type = 'sine'
            osc.frequency.value = note.freq

            const start = now + note.at
            const end = start + note.dur
            // Envelope curto (ataque + decaimento) para não estalar.
            gain.gain.setValueAtTime(0.0001, start)
            gain.gain.exponentialRampToValueAtTime(PEAK_GAIN, start + 0.01)
            gain.gain.exponentialRampToValueAtTime(0.0001, end)

            osc.connect(gain).connect(c.destination)
            osc.start(start)
            osc.stop(end + 0.02)
        }
    } catch {
        // Nunca propaga: som é enfeite, não fluxo crítico.
    }
}

// Liga o destravamento assim que o módulo é importado (barato: só registra
// listeners `once`). O AudioContext em si só nasce no primeiro som ou gesto.
bindUnlock()
