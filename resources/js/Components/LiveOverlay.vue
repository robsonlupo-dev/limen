<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import GiftIcon from '@/Components/GiftIcon.vue'
import { useNotificationSound } from '@/composables/useNotificationSound'

/**
 * Prova social da live pública (Sprint 15, PR #142). Escuta o canal privado
 * `live.{slug}` (Reverb) e anima cada gorjeta/presente na tela — TODOS na sala
 * (performer + espectadores) veem. v1 é CSS animation simples; sprites/partículas
 * são follow-up (PR #143+).
 *
 * FILA, não sobreposição: se 3 presentes chegam juntos, mostra um por vez, na
 * ordem de chegada — cada um pela própria duração (fade). O payload é
 * não-sensível (FanAlias label, valor, tipo); nunca member_id/saldo/tier.
 */
const props = defineProps({
    performerSlug: { type: String, required: true },
})

// Estilo por presente (slug → ícone, tamanho, duração/fade em ms). Espelha o
// catálogo da Limen (múltiplos de 4). Presente desconhecido cai no padrão.
const GIFT_STYLES = {
    rosa: { icon: '🌹', size: 'sm', duration: 2000 },
    chocolate: { icon: '🍫', size: 'md', duration: 3000 },
    champagne: { icon: '🍾', size: 'lg', duration: 4000 },
    joia: { icon: '💍', size: 'xl', duration: 5000 },
    coroa: { icon: '👑', size: 'xxl', duration: 6000 },
    diamante: { icon: '💎', size: 'full', duration: 8000 },
}
const GIFT_DEFAULT = { icon: '🎁', size: 'md', duration: 3000 }
const TIP_STYLE = { icon: '🪙', size: 'md', duration: 3000 }

const { play } = useNotificationSound()

const current = ref(null)

// Fila interna (não reativa — só o `current` é renderizado por vez).
const queue = []
let processing = false
let timer = null
let channel = null

function styleFor(reaction) {
    if (reaction.type === 'gift') {
        return GIFT_STYLES[reaction.gift_slug] ?? GIFT_DEFAULT
    }
    return TIP_STYLE
}

function enqueue(reaction) {
    // Som por reação (gorjeta E presente caem na preferência 'tip'); toca no
    // enfileiramento, não na exibição, para acompanhar a chegada mesmo com fila.
    play('tip')
    queue.push(reaction)
    if (!processing) {
        processNext()
    }
}

function processNext() {
    const reaction = queue.shift()
    if (!reaction) {
        processing = false
        current.value = null
        return
    }
    processing = true
    const style = styleFor(reaction)
    current.value = { ...reaction, ...style }
    // Ao fim da duração, limpa e passa ao próximo — sequência, nunca sobreposição.
    timer = setTimeout(() => {
        current.value = null
        processNext()
    }, style.duration)
}

// Caixa do ícone por tamanho (o SVG do presente preenche; o emoji da gorjeta
// usa como font-size). Espelha a antiga escala text-5xl…text-[12rem].
function iconPx(size) {
    return ({ sm: '56px', md: '72px', lg: '96px', xl: '128px', xxl: '160px', full: '200px' })[size] ?? '72px'
}

function labelFor(reaction) {
    if (reaction.type === 'gift') {
        return `${reaction.fan_alias_label} enviou`
    }
    return `${reaction.fan_alias_label} · ${reaction.amount_tokens} 🪙`
}

onMounted(() => {
    if (!window.Echo) return
    channel = window.Echo.private(`live.${props.performerSlug}`)
    channel.listen('.live.reaction', (reaction) => enqueue(reaction))
})

onBeforeUnmount(() => {
    if (timer) clearTimeout(timer)
    if (channel) window.Echo?.leave(`live.${props.performerSlug}`)
    queue.length = 0
})
</script>

<template>
    <!-- Sobrepõe o vídeo, sem capturar cliques (pointer-events-none). -->
    <div class="pointer-events-none absolute inset-0 flex items-center justify-center overflow-hidden">
        <transition name="reaction">
            <div
                v-if="current"
                :key="`${current.icon}-${current.amount_tokens}-${current.fan_alias_label}`"
                class="flex flex-col items-center gap-2 text-center"
                :style="{ animationDuration: `${current.duration}ms` }"
            >
                <!-- Presente: SVG dourado (fonte única GiftIcon); gorjeta: coin.
                     A caixa fixa o tamanho pela escala do presente. -->
                <span
                    class="reaction-icon inline-block text-gold drop-shadow-lg"
                    :style="{ width: iconPx(current.size), height: iconPx(current.size) }"
                >
                    <GiftIcon v-if="current.type === 'gift'" :slug="current.gift_slug" />
                    <span
                        v-else
                        class="grid h-full w-full place-items-center leading-none"
                        :style="{ fontSize: iconPx(current.size) }"
                    >{{ current.icon }}</span>
                </span>
                <span class="rounded-full bg-black/60 px-3 py-1 text-sm font-medium text-white">
                    {{ labelFor(current) }}
                </span>
            </div>
        </transition>
    </div>
</template>

<style scoped>
/* Fade-in + subida suave; a duração vem do :style inline (por presente). */
.reaction-enter-active,
.reaction-leave-active {
    transition: opacity 0.4s ease, transform 0.4s ease;
}
.reaction-enter-from {
    opacity: 0;
    transform: translateY(20px) scale(0.8);
}
.reaction-leave-to {
    opacity: 0;
    transform: translateY(-20px) scale(1.05);
}
.reaction-icon {
    animation-name: reaction-pop;
    animation-timing-function: ease-in-out;
}
@keyframes reaction-pop {
    0% { transform: scale(0.9); }
    12% { transform: scale(1.15); }
    30% { transform: scale(1); }
    100% { transform: scale(1); }
}
</style>
