<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'

/**
 * Avisos NÃO-INTRUSIVOS do agendamento de chamada (feat/scheduled-call-v1). Banner
 * persistente (não modal, não bloqueia navegação) que escuta o canal user.{id}:
 *
 *  - reservation.reminder        (T-5min, os dois lados)
 *  - reservation.performer_entered (membro: "a performer entrou" + contador de 2min)
 *  - reservation.call_started    (performer: some o aviso — já está na sala)
 *  - reservation.resolved        (cancel/no-show — o lado afetado)
 *  - reservation.slot_warning    (performer: fim do slot próximo)
 *
 * Compartilha o canal com o <MessageToast> (PR #144): usa `stopListening` no
 * unmount, NUNCA `Echo.leave`, para não derrubar o listener do outro componente.
 */
const page = usePage()
const myUserId = page.props.auth?.user?.id

const notices = ref([]) // { key, kind, text, href?, until? }
let channel = null
let tick = null

const EVENTS = [
    '.reservation.reminder',
    '.reservation.performer_entered',
    '.reservation.resolved',
    '.reservation.slot_warning',
]

const RESOLVED_TEXT = {
    refunded_not_confirmed: 'A performer não confirmou o agendamento. Seu saldo foi devolvido.',
    refunded_no_show_performer: 'A performer não compareceu. Seu saldo foi liberado.',
    no_show_member: 'O membro não compareceu ao agendamento. O depósito é seu.',
    cancelled: 'Agendamento cancelado. Saldo devolvido.',
}

let seq = 0
function push(notice, ttlMs = 20000) {
    const key = ++seq
    notices.value.push({ key, ...notice })
    if (ttlMs > 0) {
        setTimeout(() => dismiss(key), ttlMs)
    }
}

function dismiss(key) {
    notices.value = notices.value.filter((n) => n.key !== key)
}

function countdownLabel(until) {
    const left = Math.max(0, Math.round((until - Date.now()) / 1000))
    const m = Math.floor(left / 60)
    const s = left % 60
    return `${m}:${String(s).padStart(2, '0')}`
}

onMounted(() => {
    if (!window.Echo || !myUserId) return
    channel = window.Echo.private(`user.${myUserId}`)

    channel.listen('.reservation.reminder', (e) => {
        push({ kind: 'reminder', text: `Chamada com ${e.counterpart_label} em ~${e.minutes_until} min.` }, 60000)
    })
    channel.listen('.reservation.performer_entered', (e) => {
        push({
            kind: 'enter',
            text: 'A performer entrou na chamada. Clique para iniciar:',
            href: route('reservations.index'),
            until: Date.now() + (e.join_window_seconds ?? 120) * 1000,
        }, (e.join_window_seconds ?? 120) * 1000)
    })
    channel.listen('.reservation.call_started', () => {
        // A performer já entrou na sala pela própria tela; limpa o "clique para iniciar".
        notices.value = notices.value.filter((n) => n.kind !== 'enter')
    })
    channel.listen('.reservation.resolved', (e) => {
        push({ kind: 'resolved', text: RESOLVED_TEXT[e.outcome] ?? 'Seu agendamento foi atualizado.' }, 20000)
    })
    channel.listen('.reservation.slot_warning', (e) => {
        push({ kind: 'slot', text: `Seu slot termina em ~${e.minutes_left} min. Há um próximo agendamento.` }, 60000)
    })

    // Atualiza os contadores de 2min a cada segundo.
    tick = setInterval(() => {
        notices.value = notices.value.filter((n) => !n.until || n.until > Date.now())
    }, 1000)
})

onBeforeUnmount(() => {
    if (tick) clearInterval(tick)
    // NÃO Echo.leave — o MessageToast divide este canal. Só solta os eventos deste.
    if (channel) EVENTS.concat('.reservation.call_started').forEach((ev) => channel.stopListening(ev))
    channel = null
})
</script>

<template>
    <div class="fixed bottom-4 left-1/2 z-40 w-full max-w-md -translate-x-1/2 space-y-2 px-4">
        <div
            v-for="n in notices"
            :key="n.key"
            class="flex items-center gap-3 rounded-xl border px-4 py-3 text-sm shadow-lg"
            :class="n.kind === 'enter'
                ? 'border-limen-gold/60 bg-limen-surface text-limen-ink'
                : 'border-limen-line bg-limen-surface text-limen-ink-soft'"
        >
            <span class="flex-1">{{ n.text }}</span>
            <Link
                v-if="n.href"
                :href="n.href"
                class="shrink-0 rounded-lg bg-limen-gold px-3 py-1 text-xs font-medium text-limen-bg no-underline hover:opacity-90"
            >
                Entrar <span v-if="n.until" class="tabular-nums">({{ countdownLabel(n.until) }})</span>
            </Link>
            <button type="button" class="shrink-0 text-limen-ink-mute hover:text-limen-ink" @click="dismiss(n.key)">✕</button>
        </div>
    </div>
</template>
