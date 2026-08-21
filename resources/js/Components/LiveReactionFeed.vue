<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import GiftIcon from '@/Components/GiftIcon.vue'

/**
 * Feed de gorjetas/presentes do console da performer (feat/live-room-console). No
 * lado do membro, o <LiveOverlay> ANIMA cada reação (prova social); a performer
 * precisa da LISTA que persiste — quem mandou (FanAlias) e quanto — para acompanhar
 * a sala. Ouve `.live.reaction` no MESMO canal `live.{slug}` do Reverb.
 *
 * Emite `reaction` a cada chegada: o console repuxa o ganho acumulado e a contagem
 * de espectadores (número EXATO do ledger, não somado no cliente).
 */
const props = defineProps({
    performerSlug: { type: String, required: true },
})

const emit = defineEmits(['reaction'])

const reactions = ref([])
let counter = 0

function append(r) {
    reactions.value.unshift({
        key: counter++,
        type: r.type,
        giftSlug: r.gift_slug,
        amount: r.amount_tokens,
        label: r.fan_alias_label,
    })
    if (reactions.value.length > 100) reactions.value.pop()
    emit('reaction')
}

let channel = null
onMounted(() => {
    if (!window.Echo) return
    channel = window.Echo.private(`live.${props.performerSlug}`)
    channel.listen('.live.reaction', append)
})

onBeforeUnmount(() => {
    channel?.stopListening('.live.reaction')
})
</script>

<template>
    <div class="flex min-h-0 flex-col rounded-xl border border-frame bg-surface">
        <div class="border-b border-frame px-4 py-2.5">
            <p class="text-sm font-medium text-cream">Gorjetas e presentes</p>
        </div>

        <div class="flex-1 min-h-0 space-y-2 overflow-y-auto px-4 py-3">
            <p v-if="!reactions.length" class="py-6 text-center text-sm text-muted">
                As gorjetas e presentes da sua sala aparecem aqui.
            </p>

            <div
                v-for="r in reactions"
                :key="r.key"
                class="flex items-center gap-2.5 rounded-lg border border-frame/60 bg-background/40 px-3 py-2"
            >
                <span v-if="r.type === 'gift'" class="h-6 w-6 shrink-0 text-gold">
                    <GiftIcon :slug="r.giftSlug" />
                </span>
                <span v-else class="text-lg leading-none">🪙</span>

                <span class="min-w-0 flex-1 truncate text-[13px] text-cream/90">{{ r.label }}</span>
                <span class="shrink-0 text-sm font-semibold text-gold">{{ r.amount }}</span>
            </div>
        </div>
    </div>
</template>
