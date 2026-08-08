<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import GiftIcon from '@/Components/GiftIcon.vue'
import { useNotificationSound } from '@/composables/useNotificationSound'

/**
 * Prova social da live pública (Sprint 15, PR #142; animações v2 no Sprint 16).
 * Escuta o canal privado `live.{slug}` (Reverb) e anima cada gorjeta/presente na
 * tela — TODOS na sala (performer + espectadores) veem. O payload é
 * não-sensível (FanAlias label, valor, tipo); nunca member_id/saldo/tier.
 *
 * v2: cada presente tem uma animação PRÓPRIA (pétalas, bolhas, partículas, flash,
 * queda com giro) — CSS/SVG puro, ZERO asset externo e ZERO lib de animação
 * (ExternalAssetPolicyTest). Até 3 reações SIMULTÂNEAS; o excedente entra na FILA
 * e sobe quando um slot vaga. O ciclo de vida é 100% CSS: a animação `reaction-life`
 * do wrapper cuida do fade in/out e o `@animationend.self` remove ao terminar —
 * sem setInterval/setTimeout (requisito de performance da spec).
 */
const props = defineProps({
    performerSlug: { type: String, required: true },
})

const MAX_CONCURRENT = 3

// Estilo por presente: anim (qual coreografia), tamanho do ícone e duração (ms).
// Durações da spec. Presente desconhecido cai no padrão (pop simples).
const GIFT_STYLES = {
    rosa: { size: 'sm', duration: 2000 },
    chocolate: { size: 'md', duration: 1500 },
    champagne: { size: 'lg', duration: 3000 },
    joia: { size: 'lg', duration: 1000 },
    coroa: { size: 'xl', duration: 2000 },
    diamante: { size: 'xl', duration: 3000 },
}
const GIFT_DEFAULT = { size: 'md', duration: 2500 }
const TIP_STYLE = { size: 'md', duration: 2500 }

// Partículas pré-computadas (determinísticas — sem Math.random): posições/atrasos
// espalhados à mão para um visual agradável e reproduzível.
const PETALS = [
    { left: '10%', delay: 0, drift: '-24px', rot: '220deg' },
    { left: '24%', delay: 180, drift: '18px', rot: '-260deg' },
    { left: '38%', delay: 80, drift: '-14px', rot: '300deg' },
    { left: '50%', delay: 260, drift: '22px', rot: '-200deg' },
    { left: '62%', delay: 120, drift: '-20px', rot: '340deg' },
    { left: '76%', delay: 320, drift: '16px', rot: '-300deg' },
    { left: '88%', delay: 60, drift: '-18px', rot: '240deg' },
    { left: '32%', delay: 420, drift: '26px', rot: '-220deg' },
    { left: '68%', delay: 480, drift: '-22px', rot: '280deg' },
    { left: '52%', delay: 560, drift: '14px', rot: '-260deg' },
]
const BUBBLES = [
    { left: '30%', delay: 0, size: '10px', drift: '-10px' },
    { left: '42%', delay: 160, size: '7px', drift: '8px' },
    { left: '50%', delay: 60, size: '13px', drift: '-6px' },
    { left: '58%', delay: 240, size: '8px', drift: '12px' },
    { left: '66%', delay: 120, size: '11px', drift: '-9px' },
    { left: '36%', delay: 320, size: '6px', drift: '7px' },
    { left: '48%', delay: 400, size: '9px', drift: '-12px' },
    { left: '62%', delay: 200, size: '7px', drift: '10px' },
    { left: '44%', delay: 520, size: '12px', drift: '-7px' },
    { left: '54%', delay: 460, size: '8px', drift: '9px' },
]
// Explosão de partículas douradas: 12 direções ao redor do centro (R≈110px).
const SPARKS = [
    { dx: '110px', dy: '0px', delay: 0 },
    { dx: '95px', dy: '55px', delay: 20 },
    { dx: '55px', dy: '95px', delay: 40 },
    { dx: '0px', dy: '110px', delay: 0 },
    { dx: '-55px', dy: '95px', delay: 20 },
    { dx: '-95px', dy: '55px', delay: 40 },
    { dx: '-110px', dy: '0px', delay: 0 },
    { dx: '-95px', dy: '-55px', delay: 20 },
    { dx: '-55px', dy: '-95px', delay: 40 },
    { dx: '0px', dy: '-110px', delay: 0 },
    { dx: '55px', dy: '-95px', delay: 20 },
    { dx: '95px', dy: '-55px', delay: 40 },
]

const { play } = useNotificationSound()

const ICON_PX = { sm: '56px', md: '72px', lg: '96px', xl: '128px', xxl: '160px', full: '200px' }

// Reações ATIVAS na tela (até MAX_CONCURRENT) + fila de excedente.
const active = ref([])
const queue = []
let seq = 0
let channel = null

function styleFor(reaction) {
    if (reaction.type === 'gift') {
        const known = GIFT_STYLES[reaction.gift_slug]
        const s = known ?? GIFT_DEFAULT
        return { anim: known ? reaction.gift_slug : 'gift', size: s.size, duration: s.duration }
    }
    return { anim: 'tip', size: TIP_STYLE.size, duration: TIP_STYLE.duration }
}

function enqueue(reaction) {
    // Som por reação (gorjeta E presente caem na preferência 'tip'); toca na
    // chegada, respeitando o toggle do usuário e falhando em silêncio.
    play('tip')

    if (active.value.length < MAX_CONCURRENT) {
        activate(reaction)
    } else {
        queue.push(reaction)
    }
}

function activate(reaction) {
    active.value.push({ id: ++seq, ...reaction, ...styleFor(reaction) })
}

// Fim da animação de vida do wrapper (só a dele, via .self) → remove e puxa a
// próxima da fila. Sem timer: o próprio CSS marca o fim.
function onEnd(item) {
    const i = active.value.findIndex((r) => r.id === item.id)
    if (i !== -1) active.value.splice(i, 1)
    if (queue.length > 0) activate(queue.shift())
}

function iconPx(size) {
    return ICON_PX[size] ?? '72px'
}

function iconAnimClass(anim) {
    return {
        chocolate: 'anim-chocolate',
        coroa: 'anim-coroa',
        joia: 'anim-joia',
    }[anim] ?? 'anim-pop'
}

function petalStyle(p, duration) {
    return { left: p.left, animationDuration: `${duration}ms`, animationDelay: `${p.delay}ms`, '--drift': p.drift, '--rot': p.rot }
}

function bubbleStyle(p, duration) {
    return { left: p.left, width: p.size, height: p.size, animationDuration: `${duration}ms`, animationDelay: `${p.delay}ms`, '--drift': p.drift }
}

function sparkStyle(p, duration) {
    return { animationDuration: `${duration}ms`, animationDelay: `${p.delay}ms`, '--dx': p.dx, '--dy': p.dy }
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
    if (channel) window.Echo?.leave(`live.${props.performerSlug}`)
    active.value = []
    queue.length = 0
})
</script>

<template>
    <!-- Sobrepõe o vídeo, sem capturar cliques. As reações concorrentes ficam em
         linha centralizada (1 centra; 2-3 espalham). -->
    <div class="pointer-events-none absolute inset-0 flex items-center justify-center gap-6 overflow-hidden">
        <div
            v-for="item in active"
            :key="item.id"
            class="reaction-life relative flex h-full w-40 flex-col items-center justify-center gap-2 text-center"
            :style="{ animationDuration: `${item.duration}ms` }"
            @animationend.self="onEnd(item)"
        >
            <!-- Rosa: pétalas caindo de cima. -->
            <div v-if="item.anim === 'rosa'" class="absolute inset-0 overflow-hidden">
                <span
                    v-for="(p, i) in PETALS"
                    :key="i"
                    class="petal absolute top-0 text-2xl"
                    :style="petalStyle(p, item.duration)"
                >🌹</span>
            </div>

            <!-- Champagne: bolhas subindo do fundo. -->
            <div v-else-if="item.anim === 'champagne'" class="absolute inset-0 overflow-hidden">
                <span
                    v-for="(p, i) in BUBBLES"
                    :key="i"
                    class="bubble absolute bottom-8 rounded-full"
                    :style="bubbleStyle(p, item.duration)"
                />
            </div>

            <!-- Diamante: explosão de partículas douradas a partir do centro. -->
            <div v-else-if="item.anim === 'diamante'" class="absolute inset-0">
                <span
                    v-for="(p, i) in SPARKS"
                    :key="i"
                    class="spark absolute left-1/2 top-1/2"
                    :style="sparkStyle(p, item.duration)"
                />
            </div>

            <!-- Joia: flash radial de luz por trás do ícone. -->
            <div
                v-else-if="item.anim === 'joia'"
                class="jewel-flash absolute left-1/2 top-1/2 h-40 w-40"
                :style="{ animationDuration: `${item.duration}ms` }"
            />

            <!-- Ícone: presente (SVG dourado, fonte única) ou moeda da gorjeta.
                 A classe de animação varia por tipo (pulso+brilho, queda+giro…). -->
            <span
                class="reaction-icon inline-block text-gold drop-shadow-lg"
                :class="iconAnimClass(item.anim)"
                :style="{ width: iconPx(item.size), height: iconPx(item.size), animationDuration: `${item.duration}ms` }"
            >
                <GiftIcon v-if="item.type === 'gift'" :slug="item.gift_slug" />
                <span
                    v-else
                    class="grid h-full w-full place-items-center leading-none"
                    :style="{ fontSize: iconPx(item.size) }"
                >🪙</span>
            </span>

            <span class="relative rounded-full bg-black/60 px-3 py-1 text-sm font-medium text-white">
                {{ labelFor(item) }}
            </span>
        </div>
    </div>
</template>

<style scoped>
/* Ciclo de vida do wrapper: fade-in rápido, sustenta, fade-out no fim. O
   animationend deste keyframe (e só dele, via .self no template) remove a reação. */
.reaction-life {
    animation-name: reaction-life;
    animation-timing-function: ease-out;
    animation-fill-mode: forwards;
}
@keyframes reaction-life {
    0% { opacity: 0; transform: translateY(10px) scale(0.9); }
    10% { opacity: 1; transform: translateY(0) scale(1); }
    85% { opacity: 1; transform: translateY(0) scale(1); }
    100% { opacity: 0; transform: translateY(-8px) scale(1.03); }
}

/* Pop base (default/tip). */
.anim-pop { animation: reaction-pop 0.6s ease-in-out; }
@keyframes reaction-pop {
    0% { transform: scale(0.9); }
    12% { transform: scale(1.15); }
    30% { transform: scale(1); }
    100% { transform: scale(1); }
}

/* Chocolate: pulsa e brilha (scale + glow dourado). */
.anim-chocolate {
    animation-name: chocolate-glow;
    animation-timing-function: ease-in-out;
    animation-iteration-count: 2;
}
@keyframes chocolate-glow {
    0%, 100% { transform: scale(1); filter: drop-shadow(0 0 2px rgba(201, 168, 76, 0.5)); }
    50% { transform: scale(1.14); filter: drop-shadow(0 0 18px rgba(201, 168, 76, 1)); }
}

/* Coroa: desce do topo com rotação. */
.anim-coroa {
    animation-name: crown-drop;
    animation-timing-function: cubic-bezier(0.2, 0.9, 0.3, 1.2);
}
@keyframes crown-drop {
    0% { transform: translateY(-140px) rotate(-200deg); opacity: 0; }
    60% { transform: translateY(0) rotate(12deg); opacity: 1; }
    80% { transform: translateY(0) rotate(-6deg); }
    100% { transform: translateY(0) rotate(0); }
}

/* Joia: leve tremor de brilho no ícone (o flash grande vem do .jewel-flash). */
.anim-joia { animation: jewel-shine 0.7s ease-out; }
@keyframes jewel-shine {
    0% { transform: scale(0.6); opacity: 0.4; }
    40% { transform: scale(1.15); opacity: 1; }
    100% { transform: scale(1); }
}
.jewel-flash {
    margin: -5rem 0 0 -5rem; /* centraliza o quadrado 10rem sobre o ponto 50%/50% */
    border-radius: 9999px;
    background: radial-gradient(circle, rgba(255, 250, 235, 0.95) 0%, rgba(201, 168, 76, 0.4) 40%, transparent 70%);
    animation-name: jewel-flash;
    animation-timing-function: ease-out;
    animation-fill-mode: forwards;
}
@keyframes jewel-flash {
    0% { opacity: 0; transform: scale(0.2); }
    25% { opacity: 0.9; }
    100% { opacity: 0; transform: scale(2.4); }
}

/* Rosa: pétalas caindo, com deriva lateral e giro. */
.petal {
    animation-name: petal-fall;
    animation-timing-function: ease-in;
    animation-fill-mode: both;
    will-change: transform, opacity;
}
@keyframes petal-fall {
    0% { transform: translateY(-30px) rotate(0); opacity: 0; }
    15% { opacity: 1; }
    100% { transform: translateY(180px) translateX(var(--drift)) rotate(var(--rot)); opacity: 0; }
}

/* Champagne: bolhas douradas subindo. */
.bubble {
    background: radial-gradient(circle at 30% 30%, rgba(255, 255, 255, 0.9), rgba(201, 168, 76, 0.55));
    animation-name: bubble-rise;
    animation-timing-function: ease-out;
    animation-fill-mode: both;
    will-change: transform, opacity;
}
@keyframes bubble-rise {
    0% { transform: translateY(30px) scale(0.6); opacity: 0; }
    20% { opacity: 0.9; }
    100% { transform: translateY(-190px) translateX(var(--drift)) scale(1); opacity: 0; }
}

/* Diamante: partículas douradas explodindo do centro. */
.spark {
    width: 8px;
    height: 8px;
    margin: -4px 0 0 -4px;
    border-radius: 9999px;
    background: radial-gradient(circle, rgba(255, 250, 235, 1), rgba(201, 168, 76, 0.9));
    box-shadow: 0 0 6px rgba(201, 168, 76, 0.9);
    animation-name: particle-burst;
    animation-timing-function: cubic-bezier(0.15, 0.8, 0.3, 1);
    animation-fill-mode: both;
    will-change: transform, opacity;
}
@keyframes particle-burst {
    0% { transform: translate(0, 0) scale(1); opacity: 1; }
    100% { transform: translate(var(--dx), var(--dy)) scale(0.2); opacity: 0; }
}

/* Respeita quem pede menos movimento: sem partículas/giro, só o fade da vida. */
@media (prefers-reduced-motion: reduce) {
    .petal, .bubble, .spark, .jewel-flash { display: none; }
    .anim-chocolate, .anim-coroa, .anim-joia, .anim-pop { animation: none; }
}
</style>
