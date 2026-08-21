<script setup>
import { ref, nextTick, onMounted, onBeforeUnmount, watch } from 'vue'

/**
 * Chat da sala de live (feat/live-room-console), usado pelos DOIS lados — console da
 * performer e sala do membro. Dono do seu próprio ouvinte Reverb no canal
 * `live.{slug}` (o mesmo do <LiveOverlay>): usa `stopListening('.live.chat')` no
 * unmount, NUNCA `Echo.leave` — o canal é compartilhado com o overlay (padrão do
 * MessageToast/ReservationNotice).
 *
 * Não conhece rotas: o pai injeta `on-send`/`on-mute` (async). Assim o mesmo
 * componente serve à performer (que também modera) e ao membro (que só fala).
 */
const props = defineProps({
    performerSlug: { type: String, required: true },
    initialMessages: { type: Array, default: () => [] },
    canModerate: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    placeholder: { type: String, default: 'Escreva na sala…' },
    onSend: { type: Function, required: true },
    onMute: { type: Function, default: null },
})

const messages = ref([])
const seen = new Set()
const draft = ref('')
const sending = ref(false)
const error = ref('')
const notice = ref('')
const listEl = ref(null)

function append(m) {
    if (m?.id == null || seen.has(m.id)) return
    seen.add(m.id)
    messages.value.push({ id: m.id, label: m.label, body: m.body, is_performer: !!m.is_performer })
    // Sala de chat é efêmera: segura a lista para não crescer sem limite.
    if (messages.value.length > 200) messages.value.splice(0, messages.value.length - 200)
    scrollToBottom()
}

function scrollToBottom() {
    nextTick(() => {
        const el = listEl.value
        if (el) el.scrollTop = el.scrollHeight
    })
}

async function submit() {
    const body = draft.value.trim()
    if (!body || sending.value || props.disabled) return
    sending.value = true
    error.value = ''
    try {
        await props.onSend(body)
        draft.value = ''
    } catch (e) {
        error.value = e?.data?.message ?? 'Não foi possível enviar sua mensagem.'
    } finally {
        sending.value = false
    }
}

async function mute(id) {
    if (!props.onMute) return
    error.value = ''
    try {
        const res = await props.onMute(id)
        notice.value = res?.muted ? `${res.muted} foi removido da sala.` : 'Removido da sala.'
    } catch (e) {
        error.value = e?.data?.message ?? 'Não foi possível remover.'
    }
}

props.initialMessages.forEach(append)

let channel = null
onMounted(() => {
    scrollToBottom()
    if (!window.Echo) return
    channel = window.Echo.private(`live.${props.performerSlug}`)
    channel.listen('.live.chat', append)
})

onBeforeUnmount(() => {
    // stopListening (não leave): o <LiveOverlay> ouve `.live.reaction` no MESMO canal.
    channel?.stopListening('.live.chat')
})

// Se o pai injetar histórico depois (reload que resolve a sessão), semeia uma vez.
watch(() => props.initialMessages, (list) => list?.forEach(append))
</script>

<template>
    <div class="flex min-h-0 flex-col rounded-xl border border-frame bg-surface">
        <div class="flex items-center justify-between border-b border-frame px-4 py-2.5">
            <p class="text-sm font-medium text-cream">Chat da sala</p>
            <span class="text-[11px] text-muted">Gratuito</span>
        </div>

        <div ref="listEl" class="flex-1 min-h-0 space-y-2.5 overflow-y-auto px-4 py-3">
            <p v-if="!messages.length" class="py-6 text-center text-sm text-muted">
                Ainda não há mensagens. Diga um oi.
            </p>

            <div
                v-for="m in messages"
                :key="m.id"
                class="group flex items-start gap-2"
            >
                <div class="min-w-0 flex-1">
                    <p class="text-[13px] leading-snug">
                        <span
                            class="mr-1.5 font-semibold"
                            :class="m.is_performer ? 'text-gold' : 'text-cream/70'"
                        >{{ m.label }}</span>
                        <span class="break-words text-cream/90">{{ m.body }}</span>
                    </p>
                </div>

                <button
                    v-if="canModerate && !m.is_performer"
                    type="button"
                    class="shrink-0 rounded px-1.5 py-0.5 text-[11px] text-muted opacity-0 transition hover:text-danger focus-visible:opacity-100 group-hover:opacity-100 motion-reduce:transition-none"
                    :title="`Remover ${m.label} da sala`"
                    @click="mute(m.id)"
                >
                    Remover
                </button>
            </div>
        </div>

        <div class="border-t border-frame px-3 py-2.5">
            <p v-if="error" class="mb-1.5 text-[12px] text-danger">{{ error }}</p>
            <p v-else-if="notice" class="mb-1.5 text-[12px] text-muted">{{ notice }}</p>
            <form class="flex items-end gap-2" @submit.prevent="submit">
                <textarea
                    v-model="draft"
                    rows="1"
                    :disabled="disabled"
                    :placeholder="disabled ? 'A live não está no ar.' : placeholder"
                    class="max-h-24 min-h-[42px] flex-1 resize-none rounded-lg border border-frame bg-background px-3 py-2 text-sm text-cream placeholder:text-muted focus:border-gold/50 focus:outline-none disabled:opacity-50"
                    maxlength="500"
                    @keydown.enter.exact.prevent="submit"
                />
                <button
                    type="submit"
                    :disabled="disabled || sending || !draft.trim()"
                    class="mi-press h-[42px] shrink-0 rounded-lg bg-gold px-4 text-sm font-semibold text-background hover:bg-gold/90 disabled:opacity-40"
                >
                    Enviar
                </button>
            </form>
        </div>
    </div>
</template>
