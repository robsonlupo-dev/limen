<script setup>
import { ref, nextTick, onMounted, onBeforeUnmount, watch } from 'vue'
import { errorMessage } from '@/lib/http'
import GiftIcon from '@/Components/GiftIcon.vue'
import TokenCoin from '@/Components/TokenCoin.vue'

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

// ── Gorjeta/presente NO FLUXO do chat (UAT Fase 9) ───────────────────────────
// A animação do <LiveOverlay> é efêmera (flutua e some); aqui fica o RASTRO que a
// sala inteira lê — linha dourada com ícone, quem mandou (FanAlias label, dado já
// público no evento) e o valor. Mesmo canal, evento `.live.reaction`; payload só
// com type/gift_slug/amount_tokens/fan_alias_label (nada sensível — ver LiveReaction).
let reactionSeq = 0

// Nome de exibição do presente derivado do próprio slug ('rosa' → 'Rosa',
// 'urso-dourado' → 'Urso dourado'). Fallback neutro sem slug.
function giftName(slug) {
    if (!slug) return 'um presente'
    const s = String(slug).replace(/-/g, ' ')
    return s.charAt(0).toUpperCase() + s.slice(1)
}

function appendReaction(r) {
    messages.value.push({
        id: `reaction-${++reactionSeq}`,
        kind: 'reaction',
        type: r.type,
        gift_slug: r.gift_slug ?? null,
        amount: r.amount_tokens,
        label: r.fan_alias_label,
    })
    if (messages.value.length > 200) messages.value.splice(0, messages.value.length - 200)
    scrollToBottom()
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
        error.value = errorMessage(e, 'Não foi possível enviar sua mensagem.')
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
    channel.listen('.live.reaction', appendReaction)
})

onBeforeUnmount(() => {
    // stopListening (não leave): o <LiveOverlay> ouve `.live.reaction` no MESMO canal.
    channel?.stopListening('.live.chat')
    // Só o PRÓPRIO callback: overlay (membro) e feed do console (performer) ouvem
    // `.live.reaction` no mesmo canal — um stopListening sem callback os mataria.
    channel?.stopListening('.live.reaction', appendReaction)
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
                <!-- Gorjeta/presente: linha de destaque dourada no fluxo (UAT Fase 9). -->
                <div
                    v-if="m.kind === 'reaction'"
                    class="flex w-full items-center gap-2 rounded-lg border border-gold/30 bg-gold/10 px-2.5 py-1.5"
                >
                    <span class="h-5 w-5 shrink-0 text-gold" aria-hidden="true">
                        <GiftIcon v-if="m.type === 'gift'" :slug="m.gift_slug" />
                        <TokenCoin v-else class="h-full w-full" />
                    </span>
                    <p class="min-w-0 flex-1 truncate text-[13px] leading-snug text-cream">
                        <span class="font-semibold text-gold">{{ m.label }}</span>
                        <template v-if="m.type === 'gift'"> enviou {{ giftName(m.gift_slug) }} · {{ m.amount }} tokens</template>
                        <template v-else> mandou {{ m.amount }} tokens de gorjeta</template>
                    </p>
                </div>

                <template v-else>
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
                </template>
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
